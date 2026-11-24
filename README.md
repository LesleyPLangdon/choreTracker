# Chore Tracker

Lesley's family chore tracker (working name; real name TBD). Kids complete chores to earn points or money, and the app handles required chores ("earn it, then unlock it"), Savings and Giving buckets, savings goals, rewards, and challenges. Built first for our family, designed to be offered to other families later.

- **How it's built:** a plain PHP JSON API (`api/`) and a vanilla JavaScript front end with no build step (`web/`). The API-first design is so the front end can later be wrapped as a phone app.
- **Hosting:** Bluehost (PHP 8.4, MySQL 5.7), on its own domain, separate from stayingatthehelm.com.
- **Decisions:** `docs/decisions.md` is the source of truth. Decisions are made in the Chore Tracker project on claude.ai, then copied here.
- **Build prompts:** `docs/prompts/` (one per phase).
- **Database:** `docs/schema.md`. **Working rules for Claude:** `CLAUDE.md`. **Bluehost setup and deploying:** `SETUP.md`.

## Working with Claude Code

### Start a session
1. Open Claude Code in the Claude desktop app and choose the **choreTracker** repository.
2. To pick up an earlier conversation, open it from the list of past sessions.
3. If you start a fresh conversation, begin with:
   > Read CLAUDE.md and docs/decisions.md, then tell me where we left off.
4. Before you finish, ask Claude to **commit and push** so the work is saved on GitHub.

### Working in a Codespace instead
Big builds happen in the desktop app to save Codespaces hours, but smaller edits can happen in a Codespace. The repository includes a dev container, so a new Codespace opens with PHP and MySQL ready. Use the smallest (2-core) machine, and stop or delete Codespaces you aren't using, since they count against your monthly free hours.

### How testing works
There are two kinds of testing:
- **Automated tests (Claude runs these).** Claude Code sets up PHP and MySQL in its own environment and runs the tests before each commit. You can ask "run the tests" any time.
- **Hands-on testing (you do this).** You click around a **test site** on Bluehost: a separate copy of the app at its own test address, with its own database and a demo family. It never holds real family data.

**Test site address:** _(fill in once it's set up — see `SETUP.md`)_

**Test sign-ins** (test site only):
- Parents: `test@example.com` / `Correct-Horse-42` and `parent2@example.com` / `Correct-Horse-42`
- The demo family: **Alex** uses points *and* money (10% Savings and 10% Giving on money, two goals, a weekly and a daily challenge); **Sam** uses points only. Three rewards, and the household is on a plan that allows several goals at once.
- Kids: PIN `1234`. Each kid's link is printed by the seed/reset command (`SETUP.md`), and the parent's **Kids** screen can make a fresh one any time with **New link** (for safety, a link can only be shown when it's created).

### Testing end-of-day rules without waiting for midnight (simulated time)
The test site can pretend it is any date and time you choose, so you can watch a day lock, unlock, forfeit, or settle in a few minutes. This only works on the test site (where `is_test_site` is `true`).

**Set the time**
1. In cPanel, open **File Manager**, go to your home folder, open `choretracker-test`, and **Edit** `config.php`.
2. Find the line `'simulated_now' => null,` and change it to a time in **your own time zone**, written like this (keep the quotes and the comma):
   ```
   'simulated_now' => '2026-12-01 23:59:00 America/New_York',
   ```
   Use the date and time you want (24-hour clock) and your time zone name (the same one in Settings, like `America/Chicago`).
3. Save, then reload the app. A yellow bar at the top of every screen says **Simulated time: …** so you never forget it's on.

**What to know**
- The simulated clock is **frozen**. It does not tick, so change the line each time you want to move time.
- Move time **forward**. Days that have already been settled stay settled. To start over, run `php scripts/reset.php` (see `SETUP.md`), then set the time again.
- Required chores start counting **the day after** a chore is created, so right after a reset, set the time to **tomorrow** to see the lock and unlock rules.

**Try this: watch a day settle**
1. Set the time to the morning of a day (for example `'2026-12-01 09:00:00 America/New_York'`). Open a kid's link: their screen says the day is **locked** and lists the required chores.
2. Do and approve some chores. Earnings stay locked (grayed) until **every required chore is approved**; then the day **unlocks** and the money moves to **Spending**.
3. Leave one required chore undone. Set the time to `'2026-12-01 23:59:00 America/New_York'`: nothing has changed yet, the day is still open.
4. Set the time to the next morning (`'2026-12-02 08:00:00 America/New_York'`) and open the app. The day has **settled**: if a required chore was never done, the earnings were **forfeited** (the kid sees a short note); if everything was done but a parent hadn't approved yet, the day shows as **waiting on a parent** in the approvals list on the parent's Home screen.
5. On the parent's **Home → a kid → History** screen you can **back-fill** a chore ("we did it, just didn't tap it") or **unlock/forfeit** a day, with a note.

**Turn it off**
Change the line back to `'simulated_now' => null,` and save. The yellow bar disappears and the app uses the real time again.

### Update the test site after Claude pushes
The test site pulls its files straight from GitHub, so there's nothing to download or upload by hand.
1. In Bluehost's cPanel, open **Git Version Control** → **Manage** for this repository.
2. Click **Update from Remote**, then **Deploy HEAD Commit**.
3. If Claude says the database changed (a new migration), run the migration step in `SETUP.md`.

`SETUP.md` has the one-time setup and more detail.

### When a decision changes
Decisions are made in the Chore Tracker project on claude.ai. When the log there changes:
1. Copy the updated log into `docs/decisions.md` (replace the whole file).
2. Tell Claude Code: "I updated docs/decisions.md. Read it and tell me what changed and whether anything already built needs to change."

### Good to know
- **This repository is public.** Anyone can read the code, so passwords and database settings never go in it. They live only in `config.php` on Bluehost, which git ignores.
- **Closing the app doesn't lose committed work.** Anything Claude committed and pushed is on GitHub.
- **To stop Claude mid-task**, press `Esc`.
