# Phase 3 Prompt — Rules (for Claude Code)

Read `CLAUDE.md` and `docs/decisions.md` first. This is **Phase 3 of 5: Rules** — required chores, locking and unlocking a day's earnings, end-of-day settlement (forfeit, missed, overdue), back-fill, and parent overrides. Phases 1 and 2 are built, merged, and deployed to the Bluehost test site. Build on the existing code, schema, `Clock`/`HouseholdTime`, `Ledger`, and the Phase 2 instance service; don't restructure them without asking.

**Not in this phase** (Phase 4): Savings/Giving buckets, goals, rewards, challenges. In this phase, unlocked earnings go straight into **Spending**. Phase 4 will add the bucket split at the moment of unlock, so keep the unlock step in one place where that split can be added later.

## Required chores
- Add **Required** to the chore form (basic form, not More options — it's central to the app). A required chore may or may not have a value.
- **Every slot** of a required chore is required (required "Brush teeth" means Morning *and* Evening).
- A **required one-time chore** only affects **its due date** (or, with no due date, the day it was created). It doesn't lock any other day. If it's still not done after that day, it carries over as overdue like any one-time chore, but it no longer gates anything.
- **Changing whether a chore is required, or adding a new required chore, takes effect the next household day.** A day that has already unlocked never re-locks because of a mid-day edit.
- A **day with no required chores** is unlocked from the start.

## Locking and unlocking a day (per kid, per household day)
Two separate questions, on purpose:
1. **Did the kid do the required chores?** Reporting done counts — this decides whether a day can be **forfeited**. A late report still counts.
2. **When does the money move?** Approved earnings for the day move from `pending` to `spending` when **every required slot for that day is approved**. On a day with no required chores, earnings move to `spending` as each chore is approved.

So:
- **During the day:** approved earnings sit in `pending` (shown grayed / locked). When the last required slot is approved, the day **unlocks**: move that day's pending earnings into `spending`. Earnings approved later that same day go straight to `spending`.
- **Sent back during the day:** the kid can redo it; nothing re-locks because money only moved after approval.
- **At end of day — settle each kid's day:**
  - If any required slot was **never reported done** → the day is **forfeited**: offset that day's pending earnings (`forfeit` entry). Forfeited earnings never reach Spending (or, in Phase 4, the buckets).
  - If every required slot was reported but **some aren't approved yet** → the day is **waiting on a parent**. It shows in the approvals list. Approving unlocks the day; sending back after the day ended **forfeits** it (the parent can still override).
  - Otherwise the day is unlocked (already, or now).
- Earnings belong to the household day they were **reported** (one-time and overdue chores included), so they follow that day's lock/forfeit status.
- All money movement is append-only ledger entries with references to the day and instance. Each transfer must be idempotent — settling or unlocking twice must never double-move money.
- If `kid_days` needs another status for "waiting on a parent," add it with a new migration.

## End of day: settlement, missed, and overdue
- **Settle on demand**, like Phase 2's instance creation: whenever any request for a household comes in, settle every household day that has ended since the last settled day, oldest first. It must be idempotent and safe if two requests arrive at once.
- **Days nobody opened:** settlement must create the missing instances for those days (only for chores that existed and were scheduled then — never before a chore or household was created), then settle them. Required chores on such a day count as not done.
- Also add **`scripts/settle.php`** that runs the same settlement for all households, so a cPanel cron job can be added later if we want it. Don't require it.
- **Repeating chores not reported by end of day:**
  - **Expires** (default for repeating chores): the instance becomes `missed`. It leaves the kid's view and stays in the parent's history.
  - **Carries over:** it shows on the kid's list as **overdue**. **No stacking** — only the most recent undone instance of that slot carries over; older ones become `missed`.
- **One-time chores:** carry over by default and show as overdue after the due date (one instance — never copies). If set to expire, they become `missed` after their due date.
- Completing an overdue chore today earns on **today** (the day it's reported).

## Kid's view
- A clear day status at the top:
  - **Locked:** "Finish these to unlock today's earnings:" with the required chores still left (e.g., "Brush teeth — Evening, opens at 6:00 PM"), plus the locked amount shown grayed.
  - **Unlocked:** today's earnings shown as unlocked.
  - **Waiting:** "Waiting for a parent to check."
- **Spending** balance (points and money, shown separately).
- If yesterday was forfeited, a short, neutral note the next time the kid opens the app ("Yesterday's earnings didn't unlock"). Informational, not scolding.
- Overdue chores appear with an "Overdue" tag.

## Parent's view
- **Approvals list** also shows **days waiting on a parent**.
- **History for each kid: the last 7 household days**, each with its status (unlocked / forfeited / waiting / locked), the chores and their status (done, late, missed, overdue), and that day's earnings.
- **Back-fill (up to 7 days back):** mark a past day's chore done ("it was done, we just didn't tap it"). This creates the instance if it doesn't exist, marks it approved and back-filled, writes its earning, and **re-settles that day** — back-filling the last missing required chore on a forfeited day unlocks it (reverse the forfeit with offsetting entries, then move the earnings into Spending).
- **Overrides (up to 7 days back), each with an optional note:**
  - Unlock a forfeited or waiting day.
  - Forfeit a day.
  - Undo an approval (offsetting entry; re-settle the day).
  - Clear a late flag.
- **Adjust balance** (any time): add or subtract points or money from Spending with a note (`parent_adjustment`). Don't allow it to take Spending below zero.
- Show who made each override and when.

## Testing on the test site
I want to test the end-of-day rules myself without waiting until midnight. The test site has `simulated_now` in `config.php`. Make it usable for me:
- Explain in `README.md`, in plain steps, how to set and clear the simulated time on the test site, and what to expect (e.g., set it to 11:59 PM, then to the next morning, to watch a day settle).
- Make sure every rule in this phase uses the shared clock so simulated time works everywhere, front end included (the front end should take "now" from the API, not the device clock, wherever it matters).
- Show a small "Simulated time: …" banner on every screen when it's set, so I never forget it's on.

## Seed data (test site)
- Make "Make bed" and both slots of "Brush teeth" **required**.
- Keep the other demo chores as they are.

## Tests (PHPUnit) — use the shared clock
- Required slot reported but not approved: day not forfeited at end of day; it's waiting; approve → unlocks and earnings move to Spending; send back after the day ended → forfeited.
- All required approved during the day → unlock; later approvals that day go straight to Spending.
- Required slot never reported → forfeited at end of day; pending earnings offset; Spending unchanged.
- No required chores → earnings go to Spending as approved.
- Every slot of a required chore is required.
- Required one-time chore gates only its due date.
- Making a chore required mid-day doesn't re-lock an unlocked day; it applies tomorrow.
- Days nobody opened (including several in a row): instances created, settled correctly, nothing created before a chore existed.
- Settlement is idempotent and safe when run twice at once; never double-moves money.
- Expire vs. carry over; no stacking; one-time chore overdue as a single instance; overdue completion earns today.
- Back-fill unlocks a forfeited day with correct offsetting entries; back-fill limited to 7 days.
- Each override, including undoing an approval; balance adjustment can't go below zero.
- End-of-day time other than midnight (e.g., 2:00 AM) and a time zone other than New York.
- Ledger check: for every test, the sum of entries matches the expected pending and Spending balances.

## Finish
- Update `docs/schema.md`, `README.md` (simulated time), and the "Where we left off" note in `CLAUDE.md`.
- Tell me the migration and reset commands for the test site.
- Commit in logical steps, push, and open a pull request into `main`.

## How to work with me
- Start by giving me a short plan, including how you'll store "waiting on a parent" and how settlement tracks which days are done.
- Ask before deviating from the decisions or this prompt; flag anything ambiguous instead of guessing.
