# Phase 2 Prompt — Chores (for Claude Code)

Read `CLAUDE.md` and `docs/decisions.md` first. This is **Phase 2 of 5: Chores** — creating and scheduling chores, the kid's "today" list, reporting done, parent approvals, and recording earnings. Phase 1 is built, deployed to the Bluehost test site, and tested. Build on the existing code, schema, `Clock`/`HouseholdTime`, and `Ledger`; don't restructure Phase 1 without asking.

**Not in this phase** (Phase 3): required chores, lock/unlock, end-of-day forfeit, marking chores missed or expired at end of day, back-fill, and parent overrides of past days. (Phase 4): buckets, Spending, goals, rewards, challenges. The schema already supports these — don't build them yet, but don't make choices that block them.

## Parent: creating and editing chores
**Basic form** (keep it short):
- Title.
- Value: a number plus points or money, or no value.
- Schedule:
  - **Repeats** — a day-of-week picker (every day, weekdays, specific days).
  - **One time** — with an optional due date ("due by Sunday").
- Assign to one or more kids. Assigning to several kids creates one copy per kid sharing a `group_id` (as in the schema).

**More options** (collapsed by default):
- **Slots:** more than one per day (e.g., Morning and Evening), each with a label.
- **Times per slot:** optional **available from** and optional **due by** (household-local).
- **Auto-approve:** on/off.
- **If it isn't done:** expires vs. carries over. Defaults: repeating chores expire; one-time chores carry over. (Store the setting now; the end-of-day behavior is Phase 3.)

**Editing:** changes apply from the household's current day forward. Instances already created for past days, and anything already reported or approved, keep their original value and details (instances snapshot value/currency). Editing a group (a chore assigned to several kids) should edit all copies unless I choose one kid.

**Removing:** "Remove" deactivates the chore; history is kept. No hard deletes.

## How chores show up for the kid
- **Repeating chores** appear on their scheduled days, one row per slot.
- **One-time chores appear from the day they're created and stay on the kid's list every day until they're done** — the kid sees them all week, not just on the due date. Show the due date ("Due Sun"). Example: "Rake the front yard, due Sunday" — the kid can work on it a little each day and taps Done when it's finished.
  - A one-time chore has **a single instance** (one per slot), not one per day. Never create duplicate copies of it.
  - Its earnings belong to the household day it is **reported done** (Phase 3 lock/unlock will use that day).
- **The kid sees today only:** today's repeating chores plus any open one-time chores. No preview of future days.
- **Available from:** before a slot opens, show it grayed with "opens at 6:00 PM" and don't allow Done.
- **Due by is soft:** after the due-by time the kid can still tap Done; the instance is flagged `is_late` at the moment it's reported, and the parent sees a "late" tag.

**Creating instances:** create them on demand — whenever a kid's or parent's view of today is loaded, fill in any of today's instances that don't exist yet (idempotent, safe to run repeatedly, safe if two requests run at once — rely on the unique key). **A chore added or changed during the day shows up on the kid's list the next time it loads.** No server cron job in this phase.

**Keeping screens current:** refresh the kid's list and the parent's approval list when the app is opened or comes back to the foreground, add a visible refresh control, and poll lightly (about once a minute) while a screen is visible. Keep it simple — no websockets.

## Completion and approval flow
- **Kid taps Done** → `reported_done`, record `reported_at` (UTC) and `is_late`.
- **Kid can undo Done** until a parent approves it (back to `assigned`, clearing `reported_at` and `is_late`).
- **Parent approves** → `approved`, recording who and when.
- **Parent sends back** → `sent_back`, with an **optional short note** shown to the kid ("The bed isn't made"). The kid can then tap Done again. Add a migration for the note column (never edit a shipped migration).
- **Parent marks done directly** (from the parent's view of a kid's day) → approved in one step.
- **Auto-approve chores** go straight to `approved` when the kid taps Done (`approved_by_parent_id` NULL, as the schema notes).
- Either parent in the household can approve or send back.

## Parent approvals list
- A **"Needs your approval"** list across all kids, newest first, showing kid, chore, slot, time reported, and a "late" tag when it applies, with Approve and Send back on each row.
- A **badge** with the count on the parent's main screen/navigation.
- Build it so later phases can add other item types (goal requests, reward requests, challenges earned) to the same list.

## Parent view of a kid's day
- For each kid: today's chores with status (to do / waiting for approval / approved / sent back / late), plus Mark done.
- Today's earnings for that kid (see below).

## Earnings
- When a chore with a value is approved, write an **`earning` ledger entry to the `pending` bucket** (signed integer; points whole, money in cents), referencing the chore instance. Use `App\Ledger`.
- If an approval is undone in any way in this phase (it shouldn't be possible yet except through Phase 3 overrides), it must be corrected with an offsetting entry — never edit or delete ledger rows.
- Exactly one earning entry per approved instance, even if requests repeat (make it idempotent).
- **Kid's screen:** show "Earned today" (pending) per currency. Show money as dollars and cents.
- **Parent's kid view:** the same totals.
- Don't move anything out of `pending` — unlocking into Spending is Phase 3, buckets are Phase 4. Label it so it doesn't look spendable yet (e.g., "Earned today").

## API
- Endpoints for chores (create, list, edit, deactivate), today's list for a kid (kid auth) and for a parent viewing a kid, report done, undo, approve, send back, mark done, the approvals list and its count, and today's earned totals.
- Kids can only see and act on their own chores; parents only within their household. Test these boundaries.

## Seed data (test site)
Add demo chores to the seed so I can try everything right away:
- "Make bed" — every day, 10 points.
- "Brush teeth" — every day, two slots: Morning due by 8:00 AM, Evening available from 6:00 PM. No value.
- "Walk the dog" — every day, two slots: Morning and Evening, $0.50.
- "Rake the front yard" — one time, due on the coming Sunday, $5.00.
- "Feed the fish" — every day, auto-approve, 5 points.

## Tests (PHPUnit) — use the shared clock to simulate times
- Repeating chores appear only on scheduled days; one row per slot.
- A one-time chore appears every day from creation until done, as one instance, with no duplicates.
- On-demand instance creation is idempotent, including when run twice at once.
- A chore added mid-day appears on that day's list.
- Available-from blocks Done before the slot opens.
- Late flag is set after due-by, not before; undo clears it.
- Undo works before approval and is refused after.
- Send back (with and without a note), then report again.
- Auto-approve.
- Approval writes exactly one pending earning, in the right currency and units; no-value chores write none.
- Editing a chore doesn't change instances already reported or approved.
- Household time zone and end-of-day time decide which day a chore belongs to (e.g., a household whose day ends at 2:00 AM).
- Permission boundaries: a kid can't see or act on another kid's chores; a parent can't reach another household.

## Finish
- Update `docs/schema.md` and the "Where we left off" note in `CLAUDE.md`.
- Tell me whether the test site needs a migration and which commands to run.
- Commit in logical steps and push.

## How to work with me
- Start by giving me a short plan.
- Ask before deviating from the decisions or this prompt; flag anything ambiguous instead of guessing.
