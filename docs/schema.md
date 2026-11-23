# Database schema

MySQL 5.7 (Percona on Bluehost), InnoDB, utf8mb4. The migrations in `db/migrations/` are the source of truth; this
file explains them. **Keep it current whenever a migration changes the schema.**

Conventions
- All timestamps are `DATETIME` in **UTC**. Dates and times that are *household-local* (`local_date`, `due_by`,
  `available_from`, challenge window times, `day_end_time`) are stored as plain `DATE`/`TIME` and converted in PHP
  through `HouseholdTime` — never in SQL.
- Money is stored in **cents**, points as **whole numbers**; both are integers. Currencies never convert.
- `days_of_week` is a bitmask: Mon=1, Tue=2, Wed=4, Thu=8, Fri=16, Sat=32, Sun=64 (127 = every day, 31 = weekdays).
  Chores and daily challenges use the same format.
- CHECK constraints are not used (MySQL 5.7 ignores them). Rules are enforced in PHP.
- Secrets (passwords, PINs, link/invite/bearer tokens) are never stored in plain text: passwords and PINs use
  `password_hash()`; the long random tokens are stored as a sha256 hash.
- Migrations are applied by `php scripts/migrate.php` (tracked in `schema_migrations`). Never edit a migration that
  has already been applied on the test or production site — add a new numbered file.

## Tables

### Accounts (`001`) — used in Phase 1
| Table | Purpose |
|---|---|
| `households` | `name`, `timezone` (IANA), `day_end_time` (household-local; default `00:00`), `plan` (default `free`). |
| `parents` | Belong to a household. `email` is unique (stored lower-case), `password_hash`, `display_name`. |
| `parent_invites` | A second parent joins via a single-use link: `token_hash`, optional `email` restriction, `expires_at` (7 days), `accepted_at`, `revoked`. |
| `kids` | Profiles, not accounts — **no PII**: `nickname`, `avatar` (emoji), `pin_hash`, `link_token_hash`, `failed_pin_attempts`, `locked_until`, `active`. |
| `auth_tokens` | Bearer tokens for parents and kids: `token_hash`, `type` (`parent`/`kid`), `expires_at`, `revoked`, `last_used_at`. |

### Chores (`002`, `005`, `006`) — Phase 2 (flow) / Phase 3 (rules)
| Table | Purpose |
|---|---|
| `chores` | **One row per kid.** Assigning one chore to several kids creates copies that share a `group_id`. `value` (nullable), `currency` (`points`/`money`, NULL when no value), `is_required`, `schedule_type` (`daily`/`one_off`), `days_of_week`, `due_date`, `overdue_behavior` (`expire`/`carry_over`), `auto_approve`, `active`. |
| `chore_slots` | Every chore has ≥1 slot (label, `sort_order`, optional `available_from`, optional `due_by`, household-local). `active` (migration 005): removing a slot deactivates it so instances and ledger history are never lost to the cascade. |
| `chore_instances` | One per **slot** per kid per household-local date (`UNIQUE(slot_id, local_date)`). `status`: `assigned`, `reported_done`, `approved`, `sent_back`, `missed`, `expired`. Also `reported_at` (UTC), `is_late`, `approved_by_parent_id` (NULL + `approved` = auto-approved), `approved_at`, `sent_back_at` + `send_back_note` (migration 005: the optional note shown to the kid), `is_back_filled`, and a snapshot of `value`/`currency` so later chore edits don't rewrite earnings already promised. |
| `kid_days` | Per kid per household-local date: `locked` / `unlocked` / `forfeited`, plus override info (`override_by_parent_id`, `override_note`, `overridden_at`). |

### Ledger, goals, rewards (`003`) — Phase 2/4
| Table | Purpose |
|---|---|
| `ledger_entries` | **Append-only.** Signed integer `amount`; `currency`; `bucket` (`pending`, `spending`, `savings`, `giving`, `goal`); `entry_type` (`earning`, `unlock`, `bucket_split`, `forfeit`, `goal_transfer`, `reward_redemption`, `payout_transfer`, `parent_adjustment`); optional references to a chore instance, goal, or reward request; `created_by_type`/`created_by_id`; `note`. |
| `bucket_settings` | Per kid per currency: `savings_pct`, `giving_pct` (whole percents). |
| `goals` | `status`: `requested`, `active`, `reached`, `cancelled`. Many rows allowed; "one active goal on the free plan" is enforced in PHP by `households.plan`. |
| `rewards` | Parent-defined, priced in one currency; bought from Spending. |
| `reward_requests` | `requested` → `approved`/`denied` → `delivered`. Snapshots `cost` and `currency` at request time. |

**The ledger is the only record of money/points.** Never `UPDATE` or `DELETE` a ledger row; fix mistakes with an
offsetting entry. **Balances are always computed** (`SUM(amount)` per kid + currency + bucket) — see `App\Ledger`.
There is deliberately no stored balance column anywhere.

### Challenges (`004`) — Phase 4
| Table | Purpose |
|---|---|
| `challenges` | Household, kid, `reward_name`/`reward_description`, `target_count`, `repeat_type` (`once`/`daily`/`weekly`), and the window definition (below). |
| `challenge_chores` | Which chores count — **one or more**, by `chores.group_id`. |
| `challenge_periods` | One row per window instance: `period_start`/`period_end` in **UTC** (start inclusive, end exclusive), `status` (`in_progress`, `earned`, `missed`, `delivered`), `approved_by_parent_id`, timestamps. `UNIQUE(challenge_id, period_start)`. |

Window definition (all times household-local):
- **once:** `once_start_local` and `once_end_local` (date-times).
- **daily:** `start_time`, `end_time`, and `days_of_week` (same bitmask as chores; e.g. weekdays only).
- **weekly:** `start_weekday` + `start_time` and `end_weekday` + `end_time` (ISO weekday 1=Mon … 7=Sun), e.g. Friday 5:00 PM to Friday 5:00 PM (a full week).

## Rules the schema supports

### Earnings: order of operations
1. A chore is approved → its value is recorded as a ledger `earning` in the **`pending`** bucket (visible, locked).
2. When the day's required chores are done, the day **unlocks**: the pending amount is moved out of `pending`
   (an `unlock` entry), the parent-set **Savings %** and **Giving %** come off the top (`bucket_split` entries into
   `savings` and `giving`), and the **remainder goes to `spending`**. Percentages that don't divide evenly leave the
   remainder in Spending.
3. The kid moves money from `spending` into goals when they choose (`goal_transfer` into the `goal` bucket).
4. A day that ends with a required chore undone is **forfeited**: the pending amount is cancelled with a `forfeit`
   entry and never reaches the buckets (unless a parent overrides).
5. Rewards are bought from `spending` (`reward_redemption`). Savings is later marked transferred by a parent
   (`payout_transfer`).

### Soft due-by times
`chore_slots.due_by` is **soft**. After it passes, the kid can still report the chore done until the end of the
household day; the instance is simply flagged `is_late`. The **end of the household day** (`day_end_time` in the
household's time zone; default midnight) is the hard cutoff — there is no second time to set. A late required chore
still unlocks the day. A day labelled `D` runs from `D` at `day_end_time` until `D+1` at `day_end_time`
(`HouseholdTime::dayBoundsUtc`).

### Challenge counting
A chore instance counts toward a challenge period if:
- it belongs to one of the challenge's chores (`challenge_chores.chore_group_id` = the chore's `group_id`), **and**
- it was **reported done within the window**: `reported_at >= period_start AND reported_at < period_end`, **and**
- it is **approved** (approval may happen after the window closes).

Counts are **independent of forfeits** — instances on a forfeited day still count. A challenge reward **costs
nothing**: no ledger entries are written. When the count reaches `target_count` the period becomes `earned` and
shows in the parent's approval list; the parent marks it `delivered`. A parent can mark a period earned by hand (e.g.
a drive day changed the schedule).

Examples:
- *"Walk the dog 7 times by Friday 5:00 PM → open gym."* One chore (Morning + Evening slots, so at most 2 a day),
  `target_count` 7, `weekly`, start Friday 17:00 to end Friday 17:00.
- *"Ready for school by 7:30 → 30 minutes of TV after school."* Three chores (dressed, teeth, backpack),
  `target_count` 3, `daily`, `days_of_week` = 31 (weekdays), `start_time` 06:00, `end_time` 07:30.

## How chores run (Phase 2)

**Instances are created on demand**, whenever a kid's or parent's view of a day loads (`InstanceService::ensureToday`);
there is no cron job. It is idempotent and safe if two requests run at once: the insert uses
`ON DUPLICATE KEY` on the unique key (`slot_id`, `local_date`), so a second insert is a no-op.
- **Repeating (`daily`) chores:** one instance per active slot for today's household day, if today's weekday is in
  `days_of_week`. Chores only appear from the day they were created (nothing is back-created for past days).
- **One-time (`one_off`) chores:** exactly **one instance per slot, ever**, dated (`local_date`) the household day the
  chore was created. It stays on the kid's list every day while it is `assigned` or `sent_back`, and while
  `reported_done` (waiting for a parent). Once `approved` it is shown only on the household day it was reported.
- **The kid sees today only:** today's repeating instances plus open one-time instances. No future preview.
- A day is the household's day (`HouseholdTime`): its time zone plus `day_end_time`. Slot times are compared on the same
  scale (`secondsIntoDay` / `clockSecondsIntoDay`), so with a 2:00 AM day end, an "evening from 6:00 PM" slot is still
  open at 1:00 AM.

**Status flow:** `assigned` → (kid taps Done) `reported_done` → (parent) `approved`, or `sent_back` (with an optional
note; the kid can tap Done again). The kid can undo Done back to `assigned` (clearing `reported_at` and `is_late`) until
a parent approves. A parent can also mark a chore done directly (`assigned`/`sent_back`/`reported_done` → `approved`; not
flagged late). Auto-approve chores go straight to `approved` (`approved_by_parent_id` stays NULL). `missed` and `expired`
are not used yet (Phase 3).

**Available-from / due-by:** before `available_from` the kid can't tap Done (the API refuses it). `due_by` is soft:
`is_late` is set at the moment of reporting if that moment is strictly after `due_by` (exactly at `due_by` is on time).
Undo clears it. The API also returns a live `past_due_by` for chores not yet done, for the "late" tag.

**Earnings:** approving an instance with a value writes **exactly one** `earning` entry in the **`pending`** bucket
(whole points, or cents), referencing the instance, in the same transaction as the approval (the instance row is locked,
and an existing earning for the instance is checked, so repeated or simultaneous requests never double-pay). Chores with
no value write nothing. The entry's `local_date` is the instance's day for repeating chores, and the household day it was
**reported done** for one-time chores. "Earned today" is the sum of `earning` entries for today's `local_date` per
currency. Nothing leaves `pending` yet (unlocking is Phase 3, buckets Phase 4).

**Editing and removing:** an edit applies from the household's current day forward. It updates today's untouched
(`assigned`/`sent_back`) instances (value and currency snapshot; to-dos for slots or days that no longer apply are
deleted) but never touches an instance that is `reported_done` or `approved`, or any past day. A group edit changes all
copies unless one kid is chosen. A chore can't be switched between repeating and one time. "Remove" sets `chores.active = 0`
and deletes today's untouched to-dos; history is kept. There are no hard deletes of chores.

## Rules (Phase 3, migration `006`)

**New columns/tables:** `households.settled_through` (last fully settled household day), `chores.required_from`,
`chore_instances.required_for_date`, `chore_slots.created_at` / `deactivated_at`, `kid_days.status` gains `waiting`,
`kid_days.notified_at`, and the `day_events` table (who did each back-fill/override, and when).

**Required chores.** `chores.is_required` makes *every slot* of the chore required. A new or newly-required chore starts
counting the **next household day** (`required_from` = tomorrow), so an unlocked day never re-locks from a mid-day edit.
Each instance records the day it gates in `required_for_date` (NULL = not required): its own day for repeating chores;
for a required one-time chore, its due date (or creation day if none) but never before `required_from`. A day's
**required slots** are the instances whose `required_for_date` is that day.

**Two questions, kept separate.**
1. *Did the kid do the required chores?* "Reported" (`reported_done` or `approved`) counts, even if late. Decides forfeiting.
2. *When does the money move?* When **every required slot of the day is `approved`**: the day's `pending` earnings move to `spending`.

**Day status (`kid_days.status`)**, computed by `DayRules::computeStatus` from the required slots:
no required slots, or all approved → `unlocked`; during the day otherwise → `locked`; after the day ended: any required
slot not reported (`assigned`/`sent_back`/`missed`) → `forfeited`; all reported but some not approved → `waiting`.
Automatic rules: an `unlocked` day stays unlocked; a `forfeited` day stays forfeited unless a back-fill re-settles it;
a parent's manual unlock/forfeit (`override_by_parent_id` set) is never changed automatically.

**Ledger entries** (all appended, all tagged with the earning day in `local_date`, none edited):
`earning` (pending, +) · `unlock` (pending −, spending +; the one place a Savings/Giving split will go in Phase 4) ·
`forfeit` (pending −; a later reversal is a `forfeit` +) · undoing an approval is an `earning` − in whichever bucket
the money now sits · `parent_adjustment` (spending ±, with a note). Every move is computed from the day's current
ledger sums, so repeating it (or two requests at once) never moves money twice. The earning day is the household day the
chore was **reported** (a back-filled chore: the day filled in), so overdue chores earn on the day they're finished.

**Settlement** (`Rules\Settler`): any authenticated request first settles every household day from `settled_through`+1
up to yesterday (never before the household existed; at most 366 days back), oldest first, one day per transaction, with
the household row locked so two requests can't both do the work. For each active kid and day: create the instances
that belonged on that day (only for chores and slots that existed then — `chores.created_at`, `chore_slots.created_at`),
close out undone chores, then reconcile the day.
- Repeating chores undone at end of day: **expire** → `missed`; **carry over** → stays as the one overdue copy for that
  slot (older undone copies of the slot become `missed`, so they never stack).
- One-time chores set to expire become `missed` once their due date (or creation day) has passed; carry-over ones stay
  as a single overdue instance.
- `scripts/settle.php` runs the same code for every household (for an optional cron job).

**Overrides** (`Rules\Overrides`, up to 7 days back; each logs a `day_events` row): back-fill (creates the instance if
needed, approves it with `is_back_filled`, writes its earning, re-settles the day — this is how a forfeited day recovers),
unlock a forfeited/waiting day, forfeit a day (an unlocked day's money comes back out of Spending, refused if already
spent), undo an approval (refused if the money was already spent), clear a late flag. **Balance adjustments**
(`parent_adjustment`, any time, note required) can't take Spending below zero.
A back-filled instance's `reported_at` is the moment the parent recorded it.

**Settle failures (migration `007`).** If settling a household fails, `Settler` records `households.settle_failed_at`
(and rethrows; the request itself carries on, so the app keeps working). `GET /approvals` returns `settle_failed: true`
while it is set, and the parent's Home screen shows "Some past days couldn't be updated — tell Lesley". The next settle
that completes without error (including one that finds nothing left to do) clears it. `scripts/settle.php` records and
reports the failure the same way and exits non-zero.

