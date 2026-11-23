# Chore Tracker — Decisions Log

*Started Oct 28, 2026. Family chore tracker built first for Lesley's family, designed to be monetized later. Hosting: Bluehost, PHP + MySQL (like Recall Studio), new domain, NOT on stayingatthehelm.com. Name: TBD (working name "Chore Tracker"). Candidate tagline: "Earn it, then unlock it."*

---

## Design principle (applies to every phase)
**Few settings, smart defaults, and parent override as the safety valve.**
- Before adding a setting, ask: would a typical family actually change this regularly? If not, give it a sensible default and let the parent override the result when life happens.
- Optional settings go behind a "More options" link so the basic forms stay short.
- Don't build for an exception until real use shows it happens often.
- *Later/paid idea:* "More options" can also hold special-use-case settings a family can turn on — a natural paid upgrade. Not part of Phase 1.

## 0. Build process & architecture (decided Oct 28; updated Nov 23)
- **Planning and decisions happen in the claude.ai Chore Tracker Project** (easier to navigate; works from phone). This log stays the source of truth; the repo keeps a copy at `docs/decisions.md`.
- **Repository:** `LesleyPLangdon/choreTracker` on GitHub (public).
- **Building happens with Claude Code** (not Google AI Studio). **Big builds** (new projects, whole phases) run in the Claude desktop app, outside Codespaces, to save Codespaces hours. **Later edits and updates** will often happen in a GitHub Codespace; the repo includes a `.devcontainer` so a Codespace opens ready to go (decided Nov 23). Reasons for Claude Code:
  - The core of this app is ledger logic that must be exact.
  - Claude can run PHP + MySQL and test scenarios directly.
  - Every change is a git commit.
- **Testing (decided Nov 23):**
  - Claude Code runs the automated tests in its own environment before each commit.
  - Lesley's hands-on testing happens on a **test site on Bluehost** (its own test address and database, demo family only, never real data).
  - The test site **pulls from GitHub through cPanel's Git Version Control**, so there's no manual download/upload.
- **Public repo:** no secrets in git; settings live in a git-ignored `config.php` on Bluehost.
- **Repo docs:** `README.md` (how Lesley works), `CLAUDE.md` (working rules for Claude), `SETUP.md` (Bluehost setup and deploy), `docs/schema.md`, `docs/decisions.md`, `docs/prompts/` (one prompt per phase).
- **Server versions (Bluehost):** PHP 8.4; Percona Server 5.7.44 (MySQL 5.7). Use only MySQL 5.7–compatible SQL (no CTEs, no window functions; CHECK constraints not enforced). No runtime Composer packages (don't assume Composer on the server).
- **Architecture: API-first.**
  - A **plain PHP JSON API** (no heavy framework) holds all rules and data.
  - A **separate single-page front end in vanilla JavaScript, HTML, and CSS — no framework, no build step** (native ES modules). Chosen so Lesley can maintain small changes and bugs herself.
  - **Bearer-token auth** (not PHP session cookies) so the same API works from a phone app.
  - Reason: the same front end can later be wrapped as a phone app (e.g., Capacitor, which works with plain web front ends) without rebuilding rules or data. Keep phone-app conversion in mind throughout the build.
- **Ledger design:** money and points live in an append-only ledger with signed whole-number amounts (points whole; money in cents). Balances are always computed from the ledger, never stored as editable numbers.
- **Time:** timestamps stored in UTC; "today" is calculated from the household's time zone and end-of-day time, through one shared clock so end-of-day rules can be tested by simulating a different time.
- **Build in phases**, testing each with Lesley's family before the next:
  1. Foundation — parent accounts, household (incl. time zone), kid profiles + links + PIN, full database schema, plan field, test site on Bluehost. *(Prompt: docs/prompts/phase-1.md)*
  2. Chores — create/schedule (incl. slots and times), kid "done," approve / send back / auto-approve, balances.
  3. Rules — required chores, lock/unlock, end-of-day forfeit, late flag, overdue/expire, back-fill, override.
  4. Money flow — buckets, Spending, goals, rewards, challenges.
  5. Polish — PWA install, theme.

## 1. Platform & access
- Web app, accessed mainly from phones via links. Plan to make it installable to the home screen (PWA) so it opens full-screen like an app.
- **Household is the top-level unit.** A household holds multiple parents and multiple kids (both parents can approve, assign, override). A second parent joins by invite.
- Each household has a **time zone** (used for end of day, chore times, and challenge windows).
- **Parents** have accounts: email + password (same conventions as her other apps).
- **Kids have profiles, not accounts:** nickname, avatar, simple PIN. No email, no PII. Keeps the only account holder an adult (COPPA-friendly for later commercial use).
- **Kid access:** a parent puts the kid's link on the kid's device; the kid enters their PIN to open it. The link uses a long, unguessable token; wrong PIN attempts are limited; parents can reset a link if it gets shared.
- Each household carries a **plan field** from day one so features can be switched on per tier later.

## 2. Currencies (points and money)
- Two currencies: **points** and **money**. **They work identically and have the same features** — "money" is just a label for points that a family may choose to use. A family can use one or both.
- Each chore earns one currency.
- Currencies stay separate (no conversion between them).
- **Money is a ledger only.** The app tracks earned / owed / paid out; real money changes hands in real life. No payment processing.
- The kid sees a **running total of what they've earned**, separate from goals.
- **Rounding:** points are whole numbers. Money is stored in cents. When bucket percentages don't divide evenly, the remainder goes to Spending.

## 3. Where earnings go
**Order of operations:**
1. Chore approved → earnings sit **pending/locked** until the day's required chores are done.
2. On unlock, **parent-set buckets come off the top automatically** (e.g., 10% Savings, 10% Giving). Kid can't touch them.
   - A parent **Savings** bucket represents money moving into a real savings account (the parent marks it transferred).
   - **Giving** bucket for charitable giving.
   - *Working assumption:* bucket percentages are set per kid, per currency.
3. The remainder lands in the kid's **Spending** balance.
4. The kid **moves from Spending into goals** when they choose (envelope-style). This decides "what goes in which pile" when there are multiple goals.
- **Forfeited days never reach the buckets.**
- *Later/paid idea:* kid-set auto-split into goals.

## 4. Savings goals
- Kid saves toward something with a **progress bar**; works for points or money (e.g., "new game = 400 points").
- A goal is priced in one currency.
- **Kid creates the goal, parent approves.**
- **Free: one active goal per kid. Paid: several.**
- **Parent is notified when a goal is reached** (in-app for v1; see Notifications).

## 5. Rewards (v1 — base feature)
- **Parent creates rewards** the kid can earn, each with a name and a value.
- A reward is priced in one currency (same as goals).
- **Flow:** kid picks a reward → request → parent approves → value comes out of the kid's **Spending** → parent delivers it in real life.
- *Later/paid ideas:* a fuller rewards store (categories, images, kid wish lists).

## 6. Challenges (v1 — earned rewards, decided Oct 28)
- A **challenge** is a reward earned by completing chores a set number of times within a time window. It **costs nothing** (no ledger entries).
- Parent sets: the reward, which chore(s) count (**one or more**), the target number, and the **window**.
- **Windows start and end at times the parent sets**, and can repeat or happen once:
  - **One time only:** a start date-time and an end date-time.
  - **Daily:** a start time and an end time, with the same day-of-week picker as chores (e.g., weekdays only).
  - **Weekly:** a start day + time and an end day + time.
- **What counts:** a completion counts if the kid **reported it within the window** and a parent approves it (approval can come after the window closes; parents verify all chores anyway).
- **Independent of forfeits:** completions still count toward a challenge even if that day's earnings were forfeited.
- Kid sees a **progress bar** ("5 of 7 walks"). When the target is reached, it appears in the parent's approval list; parent marks it delivered.
- Per-day limits come from chore slots (see Chores).
- **Examples:**
  - Walk the dog 7 times by Friday 5:00 PM → open gym. One chore (Morning + Evening slots, so at most 2 a day), target 7, weekly window Friday 5:00 PM to Friday 5:00 PM.
  - Ready for school by 7:30 → 30 minutes of TV after school. Three chores (dressed, teeth, backpack), target 3, daily on weekdays, 6:00–7:30 AM.
- Challenges are also how the app rewards being **on time** — a positive framing instead of penalizing lateness.

## 7. Chores
- **Daily (recurring)** and **occasional (one-off)** chores.
- **Scheduling (v1):** daily chores have a day-of-week picker (covers "weekdays only" and "weekly on Saturday"). One-off chores have an optional due date.
- **Slots (decided Oct 28):** a chore can happen more than once a day. Each chore has one or more **slots** (e.g., Morning and Evening); each slot can be completed once per day.
- **Times (decided Oct 28):** any slot can have an optional **due-by time** and an optional **available-from time** (household-local). Examples: brush teeth — Morning due by 8:00, Evening available from 6:00 PM; walk the dog — Morning and Evening slots.
- **Due-by is soft (decided Oct 28):** after the due-by time, the kid can still mark the chore done; it is flagged **late** so the parent can see it. **The end of the day is the hard cutoff** — no second time to set.
- **Multiple kids:** assigning a chore to several kids creates a separate copy per kid; each completes and earns on their own. Shared "whoever does it first" chores are a later idea.
- A chore may or may not carry a value.
- **Completion flow:** assigned → kid reports done → parent approves or sends back. Parent can also mark complete directly.
- **Auto-approve:** per-chore on/off toggle (v1).
- **Changing schedules (e.g., bus days vs. drive days):** no setting for now. Due-by is soft, so a drive day doesn't affect earnings; if it affects a timed challenge, the parent marks the challenge earned. A "today only" time change can come later if real use shows it's needed.

## 8. Required chores
- A chore can be marked **required**. It may or may not be worth anything itself.
- Until the day's required chores are done, that day's earnings show **grayed out / locked** (visible but not counted).
- Completing the required chore(s) **unlocks** them. **A late required chore still unlocks the day** — forfeiting is for chores that never got done.
- If the day ends with a required chore undone, that day's earnings are **forfeited** (unless a parent overrides).
- **End of day:** midnight in the household's time zone by default, adjustable per household.

## 9. Overdue behavior
- Per-chore setting: **Expires** (drops off after its due day) or **Carries over** (stays, listed as overdue).
- Defaults: daily chores expire; one-off chores carry over.
- **No stacking:** a carry-over chore shows once as overdue, never as multiple copies (avoids "three overdue morning tooth-brushings").
- Expired chores leave the kid's view but stay in the parent's history as **missed**.
- Parents can **back-fill** past days ("it was done, we just didn't tap it"); back-filling a required chore unlocks that day's earnings.

## 10. Parent override — universal rule
- **A parent can override anything**, always (approvals, forfeits, missed chores, late flags, balances, goals, rewards, challenges).

## 11. Notifications
- **No push notifications until the phone-app conversion.**
- v1 uses an in-app "needs your approval" list / badge for parents (approvals, goal requests, reward requests, goals reached, challenges earned).

## 12. Themes & age range
- Should feel right for all ages — not babyish for older kids.
- Treat **themes** (visual skins; cheap to add over time; natural paid feature) separately from **age-appropriate layout/tone** (big picture buttons vs. clean list).
- v1 ships with **one theme**.

## 13. Free vs. paid (sketch — not final)
- Principle: Free is genuinely useful for one family; paid adds scale, convenience, and fun.
- Decided: goals (1 free / several paid). Basic rewards list and challenges are in v1.
- Candidates for paid: more kids, multiple goals, extra themes, kid auto-split, longer history, a fuller rewards store, more active challenges, **special-use-case settings under "More options."**
- Existing tier names from her other apps (Free → Plus → Plenty) may or may not carry over.

## v1 scope
**In:** household with multiple parents + time zone; kid profiles with link + PIN; chores (daily with day picker, one-off with due date, slots with optional available-from and soft due-by times, points or money); completion/approval flow with per-chore auto-approve; late flag; required chores with lock/unlock and forfeit; overdue behavior; back-fill; parent override; buckets; Spending; one goal per kid; rewards (parent-defined, kid requests, parent approves); challenges (one-time, daily, or weekly windows); in-app approval list; one theme; plan field.

**Later:** multiple goals, extra themes, age-based layouts, kid auto-split, fuller rewards store, shared chores, "today only" schedule changes, special-use-case settings, push notifications (with phone app), longer history, billing.

## Open / next up
- **Name:** not yet. Lesley thinks the difference is the combination of features; revisit once the family has used it.
- **Test site address:** pick a test subdomain on Bluehost (needed during Phase 1 setup).
- **Phase 1:** repo created (`choreTracker`); prompt revised for Claude Code without Codespaces. Next: add `README.md`, `docs/decisions.md`, and `docs/prompts/phase-1.md` to the repo, run the Phase 1 prompt in Claude Code, and report back.
