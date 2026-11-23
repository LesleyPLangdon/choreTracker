# Phase 1 Prompt — Foundation (for Claude Code)

We're building a family chore tracker web app (working name "Chore Tracker"). The full product decisions are in `docs/decisions.md` — read it first; it is the source of truth. `README.md` describes how I work with you; the scripts and files it mentions are yours to create in this phase. This is **Phase 1 of 5: Foundation.** Don't build features from later phases, but design the database for all of them.

**Design principle for every phase:** few settings, smart defaults, and parent override as the safety valve. Don't add settings beyond what the decisions doc calls for; put optional ones behind a "More options" link.

## Target environment (production is Bluehost shared hosting)
- **PHP 8.4**
- **Percona Server 5.7.44 (MySQL 5.7).** Use only MySQL 5.7–compatible SQL: no CTEs, no window functions, and don't rely on CHECK constraints (5.7 ignores them; enforce rules in PHP). InnoDB, utf8mb4.
- No Node on the server, and **don't assume Composer is available on the server.** The app must not need any Composer packages at runtime: use a simple PSR-4 autoloader (or commit Composer's generated autoloader). PHPUnit is a development-only dependency.
- **The repository is public.** Never commit secrets. Database credentials and other settings live in `config.php`, which is git-ignored; commit a `config.example.php` instead.

## How I work (no Codespaces)
I don't have GitHub Codespaces. You work wherever this Claude Code session runs, and I do hands-on testing on a **test site on Bluehost** that pulls from GitHub through cPanel's Git Version Control.

**Your environment (automated testing):**
- **`scripts/setup-dev.sh`**: sets up PHP 8.4 and a MySQL-compatible database for running tests in your environment. Prefer MySQL 5.7 (e.g., via Docker) if it's available; otherwise use what's available, keep the SQL 5.7-compatible, and tell me which database version the tests ran against.
- **`scripts/test.sh`**: creates a fresh test database, runs all migrations, and runs PHPUnit.
- Run the tests before every commit. A local preview server (e.g., `php -S`) is fine if it helps you check the front end, but it's optional.
- **`.devcontainer/`**: later edits will often happen in a GitHub Codespace, where my free hours are limited. Add a small dev container (PHP 8.4, a MySQL 5.7 service, Composer for PHPUnit) so a Codespace opens with everything ready and `scripts/test.sh` works with no extra setup. Keep it lightweight so it starts fast on the smallest (2-core) machine.

**The Bluehost test site (my hands-on testing):**
- **`.cpanel.yml`**: deploys from the cPanel Git clone into the test site's web folder. Keep `config.php`, and anything else outside the deployed files, safe from being overwritten.
- **Folder layout on the server:** only what must be public goes in the web root. Keep `config.php`, migrations, and PHP classes outside the web root if Bluehost allows it; if not, block web access to them with `.htaccess`.
- **Migrations on Bluehost:** a command I can run from cPanel's Terminal, plus a fallback I can run from the browser that requires a secret key from `config.php` and is off unless the config turns it on.
- **Seed data (test site only):** a demo household (America/New_York) with two parents — `test@example.com` and `parent2@example.com`, both with password `Correct-Horse-42` — and two kids with PIN `1234`. The seed and reset scripts must **refuse to run** unless `config.php` marks the environment as a test site.

## Architecture (API-first — this will later become a phone app)
- **Backend:** a plain PHP JSON API (no heavy framework). Use a small router and PDO with prepared statements. It must deploy to Bluehost by copying files.
- **Auth:** bearer tokens stored hashed in the database, not PHP session cookies, so the same API works later from a Capacitor-wrapped phone app.
- **Front end: vanilla JavaScript, HTML, and CSS — no framework and no build step.**
  - Use native ES modules (`<script type="module">`) so files run exactly as written.
  - I maintain this myself, so keep the code plain, readable, and well organized: one small module per screen, a shared `api.js` for all API calls, and simple hash-based routing for the single-page app.
  - Talk to the backend only through the API (this keeps a later Capacitor wrapper simple).
- **Layout:** `api/` (PHP), `web/` (front end), `db/migrations/` (numbered SQL files plus a simple migration runner), `scripts/`, `tests/`, `docs/`.
- **Time:** store all timestamps in UTC. Each household has an IANA time zone and an end-of-day time (default 00:00). Calculate "today" and household-local times in PHP, through one shared clock class so later phases can test end-of-day rules by simulating a different time.

## Database schema — design all of it now
Create migrations for the full schema so later phases build on it cleanly. At minimum:

- **households**: name, timezone, day_end_time, plan (default `'free'`).
- **parents**: belong to a household; email (unique), password_hash (`password_hash()`), display name.
- **parent_invites**: so a second parent can join a household.
- **kids**: household, nickname, avatar, pin_hash, link_token_hash, failed PIN attempt count, locked_until, active flag. **No PII.**
- **auth_tokens**: hashed tokens for both parents and kids, with type, expiry, and revoked flag.
- **chores**: household, kid (one row per kid; a `group_id` links copies made when one chore is assigned to several kids), title, value (nullable), currency (`points` or `money`, nullable when no value), is_required, schedule_type (`daily` or `one_off`), days_of_week, due_date, overdue_behavior (`expire` or `carry_over`), auto_approve, active.
- **chore_slots**: every chore has one or more slots (most have one). Each slot has a label (e.g., "Morning," "Evening"), sort order, optional `available_from` time, and optional `due_by` time (household-local). Example: "Walk the dog" has a Morning slot and an Evening slot; "Brush teeth" has Morning due by 8:00 and Evening available from 18:00.
  - **`due_by` is soft:** after it, the kid can still report the chore done until the end of the day; the instance is flagged late. The end of the day is the hard cutoff.
- **chore_instances**: one per chore **slot** per kid per household-local date (unique on slot + date). Status covers assigned, reported done, approved, sent back, missed, and expired. Track `reported_at`, an `is_late` flag, who approved, timestamps, and a back-filled flag.
- **kid_days**: per kid per household-local date, status `locked`, `unlocked`, or `forfeited`, plus override info.
- **ledger_entries**: the money/points core. **Append-only**, signed integer amounts (points as whole numbers, money in cents), currency, kid, bucket (`pending`, `spending`, `savings`, `giving`, `goal`), entry type (earning, unlock, bucket split, forfeit, goal transfer, reward redemption, payout or transfer, parent adjustment), references to the related chore instance/goal/reward, created_by, note. **Balances are always computed from the ledger, never stored as editable numbers.**
- **bucket_settings**: per kid per currency, Savings % and Giving %.
- **goals**: kid, name, currency, target, status (requested, active, reached, cancelled), approved_by. Allow multiple rows; the "one active goal on free" rule is enforced in PHP by plan.
- **rewards**: household, name, currency, cost, active. (Bought from Spending.)
- **reward_requests**: reward, kid, status (requested, approved, denied, delivered), approved_by, timestamps.
- **challenges**: earned rewards that cost nothing. Household, kid, reward name/description, target count, active, and a **window** defined by the parent:
  - **Repeat type:** `once`, `daily`, or `weekly`.
  - **Once:** a start date-time and an end date-time.
  - **Daily:** a start time and an end time, plus a day-of-week picker (same format as chores — e.g., weekdays only).
  - **Weekly:** a start weekday + time and an end weekday + time (e.g., Friday 5:00 PM to Friday 5:00 PM).
  - All times are household-local.
- **challenge_chores**: which chores (by chore group) count toward a challenge — **one or more**.
- **challenge_periods**: one row per window instance of a challenge, with period start and end (stored in UTC), status (in progress, earned, missed, delivered), approved_by, timestamps.

Challenge counting rules (build in Phase 4, but the schema must support them):
- A chore instance counts if it belongs to one of the challenge's chores and was **reported done within the period's window** (on or after the start, before the end), and is approved (approval may happen after the window closes).
- Counts are **independent of forfeits**: instances on a forfeited day still count.
- No ledger entries — a challenge reward costs nothing.
- Examples to document:
  - "Walk the dog 7 times by Friday 5:00 PM → open gym": one chore, target 7, weekly, Friday 5:00 PM to Friday 5:00 PM.
  - "Ready for school by 7:30 → 30 minutes of TV after school": three chores (dressed, teeth, backpack), target 3, daily on weekdays, 6:00–7:30 AM.

Add indexes and foreign keys where they make sense. Document the schema in `docs/schema.md`, including the earnings order of operations, soft due-by times, and the challenge counting rules.

## Phase 1 features
**API:**
- Parent register (creates a household with a time zone), login, logout, and a "me" endpoint.
- Household settings: name, time zone, end-of-day time.
- Invite a second parent, and accept an invite.
- Kids: create, edit, deactivate; set or change PIN.
- Kid link: generate a long, random token (store only its hash). Parents can reset a link, which invalidates the old one.
- Kid login: link token + PIN → kid auth token.
  - Limit wrong PIN attempts (e.g., lock for 15 minutes after 5 misses).
  - Parents can unlock.

**Front end:**
- Parent: sign up, log in, household settings, invite parent, manage kids, and copy or reset each kid's link.
- Kid: open link → PIN screen → a placeholder kid home showing their nickname and avatar.
- Mobile-first layout.

## Documentation
- **`CLAUDE.md`**: working rules for future Claude Code sessions. Include: read `docs/decisions.md` first; the design principle; PHP 8.4 / MySQL 5.7 limits; no runtime Composer packages; the repo is public, so no secrets; vanilla JS with no build step; the ledger is append-only and balances are computed; all time logic goes through the shared clock class; run `scripts/test.sh` before committing; ask before deviating from the decisions; commit in logical steps and push at the end of a session; tell me when a change needs a migration on the test site; keep `docs/schema.md` current; and add a short "where we left off" note at the end of each work session.
- **`SETUP.md`**: step-by-step for someone new to cPanel. Cover:
  - Creating the test subdomain and its database in Bluehost.
  - Connecting the GitHub repo in cPanel's **Git Version Control** (public repo over HTTPS), plus a short note on what changes if the repo is made private later (deploy key).
  - Creating `config.php` on the server from `config.example.php`.
  - Running migrations and seeding the test site.
  - Updating the test site after a push (**Update from Remote**, then **Deploy HEAD Commit**).
  - What will differ for the real (production) site later.
- **`README.md`** already exists. Update it only if a command or detail in it turns out to be wrong, and tell me what you changed.

## Quality bar
- PHPUnit tests for:
  - Registration and login
  - Token auth
  - Kid link + PIN flow, including lockout and link reset
  - Household time zone "today" calculation, including the end-of-day time
  - The seed/reset scripts refusing to run outside a test environment
- Commit in logical steps with clear messages.

## How to work with me
- Start by reading `docs/decisions.md` and `README.md` and giving me a short plan.
- Ask before deviating from the decisions or this prompt.
- If something in the decisions doc is ambiguous or seems wrong, flag it rather than guessing.
