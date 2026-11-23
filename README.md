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
- Kids: PIN `1234`. Each kid's link is printed by the seed/reset command (`SETUP.md`), and the parent's **Kids** screen can make a fresh one any time with **New link** (for safety, a link can only be shown when it's created).

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
