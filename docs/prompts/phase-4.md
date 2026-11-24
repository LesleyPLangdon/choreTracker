# Phase 4 Prompt — Money Flow (for Claude Code)

Read `CLAUDE.md` and `docs/decisions.md` first. This is **Phase 4 of 5: Money flow** — Savings and Giving buckets, Spending, payouts, goals, rewards, and challenges. Phases 1–3 are built, merged, and deployed to the Bluehost test site. Build on the existing code: `Ledger`, `Rules\DayRules` (all bucket moves happen there; the Savings/Giving split goes in `DayRules::moveToSpending`), `Rules\Settler`, `Rules\Overrides`, the approvals list, and the parent History screen. Don't restructure them without asking.

**Not in this phase** (Phase 5): home-screen install (PWA) and the visual theme. Don't start on avatars or games (planned separately, later).

## 0. First, a small fix from Phase 3
If settling fails, keep the app running, but show parents a small warning on Home ("Some past days couldn't be updated — tell Lesley") until a later settle succeeds. Store the failure so it survives across requests; clear it on the next successful settle. Add a test.

## How families use the two currencies
Points and money work identically in the code, but families use them differently, so the screens should read naturally for both:
- A common pattern (ours): **points** for everyday expectations (make bed, brush teeth, homework), spent on **rewards**; **money** for extra jobs (raking leaves, cleaning cabinets), saved, spent toward goals, or **paid out**.
- Different kids may use different currencies (a 5-year-old on points only, a 12-year-old on money).
- **No per-kid currency setting.** Show a currency on a kid's screens only if that kid has any chore, balance, goal, or bucket setting in it. Never show an empty "$0.00" row to a points-only kid.

## 1. Buckets (per kid, per currency)
- Parent sets **Savings %** and **Giving %** per kid, per currency (the `bucket_settings` table). Default 0% / 0%. Put this on the kid's settings, not the main forms.
- **On unlock** (in `DayRules::moveToSpending`): take Savings % and Giving % of the day's earnings off the top, then put the remainder in Spending. Whole points / cents; any remainder from rounding goes to Spending. Earnings that go straight to Spending on a day with no required chores, or later the same day after unlock, get the same split.
- **Forfeited days never reach the buckets** (already true; keep it true).
- **Savings is long-term saving** — money that will move into a real savings account. The kid sees it but can't move it. Parent action: **Mark transferred** (amount + optional note) takes it out of Savings when they move it to a real account.
- **Giving** is for charitable giving. The kid sees it but can't move it. Parent action: **Mark given** (amount + optional note).
- Overrides: a parent can adjust any bucket (not just Spending) with a note, never below zero.
- Back-fill and the other Phase 3 overrides must split correctly too: if a forfeited day is unlocked later, its earnings get split when they move.

## 2. Spending and payouts
- **Spending** is what the kid has available.
- **Pay out (money only):** parent records "I gave her $5" — amount + optional note, out of Spending (`payout_transfer`). Can't exceed the balance.
- Points leave Spending through rewards, goals, or parent adjustments — no points payout.

## 3. Goals — saving for something specific
Goals are the difference between **long-term savings** (the Savings bucket) and **saving for something specific**.
- **Kid creates a goal** (name, currency, target). It goes to the parent's approvals list. Parent approves or denies (optional note). Parents can also create a goal for a kid directly (already approved).
- **One active goal per kid on the free plan** (enforce by `households.plan`; another plan allows several). Show a friendly message if a free kid tries to start a second one.
- **Kid moves money from Spending into the goal** when they choose (`goal` bucket, linked by `goal_id`). Any amount up to their Spending balance. Show a progress bar.
- **Kid can move money back** from a goal into Spending. It's their money; the parent can see every move in the activity list.
- **Goal reached:** when the goal's balance reaches the target, mark it `reached` and put it in the parent's approvals list ("Ava reached her goal: new game").
- **Using the goal:** the parent marks it **Used** (bought / given). That takes the goal's balance out (`goal_transfer` out). For a money goal, this is the payout for that item. If there's money left over beyond the target, it goes back to Spending.
- **Cancel a goal** (kid or parent): its balance returns to Spending.
- A parent can mark a goal used before it's fully funded (override); the kid's balance is used, and the parent covers the rest in real life.

## 4. Rewards — things to spend on
- **Parent creates rewards** for the household: name, price, currency, and optionally which kids can see it (default: all). Rewards are usually priced in points; money pricing is available for families that work only in dollars.
- **Kid's Rewards screen:** the rewards they can see, with the price and whether they can afford it from Spending.
- **Request:** the kid taps Request (only if Spending covers the price). It goes to the approvals list. Nothing is taken yet.
- **Approve:** the price comes out of Spending (`reward_redemption`). If the balance no longer covers it, block the approval with a clear message (the parent can adjust or deny).
- **Deny:** optional note; nothing taken. **Delivered:** parent marks it delivered when it happens in real life.
- **Kid can cancel** a request before it's approved.
- **"Save for this":** if a reward costs more than the kid has, offer **Save for this**, which creates a goal for that reward (same name, price, and currency; already approved because the parent created the reward). When that goal is reached, requesting the reward pays from the goal first, then Spending. Store the link between the goal and the reward (new migration).

## 5. Challenges — rewards earned by doing, not buying
Build exactly as in `docs/decisions.md` and `docs/schema.md`:
- **Parent creates a challenge** for a kid: the reward (text), which chore(s) count (one or more, by chore group), target count, and the window — **one time** (start/end date-time), **daily** (start/end time + day-of-week picker), or **weekly** (start day+time, end day+time). All household-local.
- **Counting:** a chore instance counts if it belongs to one of the challenge's chores, was **reported within the window**, and is **approved** (approval can come after the window closes). **Independent of forfeits.** No ledger entries.
- **Periods:** create `challenge_periods` on demand (the same way instances and settlement work). A period that ends below target becomes `missed` once all its counted chores are decided (approved or sent back); a period that reaches the target becomes `earned` and goes to the approvals list. The parent marks it **delivered**. Parent override: mark any period earned or missed.
- **Kid view:** each active challenge with a progress bar ("5 of 7 walks"), the deadline ("by Fri 5:00 PM"), and the reward. After earning: "Earned! Ask a parent."
- **Examples to seed and test:**
  - "Walk the dog 7 times by Friday 5:00 PM → open gym": weekly, Friday 5:00 PM to Friday 5:00 PM, Morning + Evening slots so at most 2 a day.
  - "Ready for school by 7:30 → 30 minutes of TV": daily on weekdays, 6:00–7:30 AM, three chores (dressed, teeth, backpack), target 3.

## 6. Approvals list and badge
Add these item types to the existing list, each with its actions: **goal requests** (approve/deny), **reward requests** (approve/deny, then delivered), **goals reached** (mark used), **challenges earned** (mark delivered). The badge counts items that need a parent's action.

## 7. Activity list (trust and clarity)
- **Parent:** per kid, a plain-language activity list of ledger moves for the last 30 days ("Mon: Unlocked 15 points — 2 to Giving, 13 to Spending"; "Paid out $5.00 — movie money"; "Moved $3.00 to goal: new game").
- **Kid:** a simple version of the same for their own activity.
- Build the wording from the ledger; never store separate text balances.

## 8. Kid's home screen
Keep it simple and readable for a young kid and an older one:
- Today's chores and day status (from Phase 3).
- **Spending**, then the goal progress bar(s), then Savings and Giving (read-only), per currency in use.
- Links to Rewards, Goals, and Challenges (show only what the kid actually has).

## Seed data (test site)
- Kid 1 uses points and money: 10% Savings and 10% Giving on money; 0% on points.
- Kid 2 uses points only.
- Rewards: "Pick the movie" (50 points), "Stay up 30 minutes late" (100 points), "Ice cream trip" (200 points).
- An approved goal for kid 1: "New game", $20.00.
- The two example challenges above, for kid 1 (add "Get dressed" and "Pack backpack" weekday chores if needed).

## Tests (PHPUnit) — use the shared clock
- Split on unlock with rounding (e.g., 10%/10% of 15 points and of $0.55); remainder to Spending; 0% settings change nothing.
- Same-day post-unlock approvals and no-required-chore days split too; forfeited days never reach any bucket.
- Unlocking a forfeited day later (back-fill/override) splits correctly; nothing moves twice.
- Mark transferred / Mark given; adjustments in any bucket never go below zero.
- Payout: money only, can't exceed Spending.
- Goals: request/approve/deny; free plan limits one active goal; move in and back out; reached; mark used (with leftover returned); cancel returns balance; used before fully funded.
- Rewards: visibility per kid; request only if affordable; approve takes from Spending; approval blocked if the balance dropped; deny; cancel; delivered.
- "Save for this": creates a linked goal; requesting when reached pays from the goal first.
- Challenges: once, daily, and weekly windows, including weekday-only; counts only reported-in-window and approved; approval after the window counts; forfeited-day completions count; at most 2 a day via slots; missed vs earned; override; periods created on demand idempotently.
- Currency display: a points-only kid never sees money rows.
- Settle-failure warning appears and clears.
- Ledger check in every money test: sums by kid, currency, and bucket match the expected balances.

## Finish
- Update `docs/schema.md`, `README.md` if anything I do changes, and the "Where we left off" note in `CLAUDE.md`.
- Tell me the migration and reset commands for the test site.
- Commit in logical steps, push, and open a pull request into `main`.

## How to work with me
- Start with a short plan, including any new tables or columns.
- Ask before deviating from the decisions or this prompt; flag anything ambiguous instead of guessing.
