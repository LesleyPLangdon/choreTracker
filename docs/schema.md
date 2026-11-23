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

### Chores (`002`) — Phase 2/3
| Table | Purpose |
|---|---|
| `chores` | **One row per kid.** Assigning one chore to several kids creates copies that share a `group_id`. `value` (nullable), `currency` (`points`/`money`, NULL when no value), `is_required`, `schedule_type` (`daily`/`one_off`), `days_of_week`, `due_date`, `overdue_behavior` (`expire`/`carry_over`), `auto_approve`, `active`. |
| `chore_slots` | Every chore has ≥1 slot (label, `sort_order`, optional `available_from`, optional `due_by`, household-local). |
| `chore_instances` | One per **slot** per kid per household-local date (`UNIQUE(slot_id, local_date)`). `status`: `assigned`, `reported_done`, `approved`, `sent_back`, `missed`, `expired`. Also `reported_at` (UTC), `is_late`, `approved_by_parent_id` (NULL + `approved` = auto-approved), `approved_at`, `is_back_filled`, and a snapshot of `value`/`currency` so later chore edits don't rewrite earnings already promised. |
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
