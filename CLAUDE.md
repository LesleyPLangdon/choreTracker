# Working rules for Claude Code — Chore Tracker

Read this file and **`docs/decisions.md` first** (it is the source of truth). `README.md` explains how Lesley works
with you; `docs/schema.md` documents the database; `docs/prompts/` has one prompt per phase.

## Principles
- **Design principle: few settings, smart defaults, parent override as the safety valve.** Don't add settings the
  decisions doc doesn't call for. Optional ones go behind a "More options" link.
- **Ask before deviating** from `docs/decisions.md` or the phase prompt. If something is ambiguous or seems wrong,
  flag it instead of guessing. Don't build later-phase features early.

## Hard constraints
- **Production is Bluehost shared hosting: PHP 8.4, Percona Server 5.7.44 (MySQL 5.7).**
  MySQL 5.7-compatible SQL only: no CTEs, no window functions, no reliance on CHECK constraints (enforce rules in
  PHP). InnoDB, utf8mb4. Avoid PHP features newer than 8.4 (tests may run on an older PHP, e.g. 8.3).
- **No runtime Composer packages.** The app uses its own PSR-4 autoloader (`api/autoload.php`). Composer/PHPUnit are
  development-only. Don't assume Composer exists on the server.
- **The repository is public. Never commit secrets.** Settings live in the git-ignored `config.php`; update
  `config.example.php` when you add a setting. Never put real passwords, tokens, or keys in code, tests, or docs
  (the demo credentials in README/seed are test-site only).
- **Front end: vanilla JS/HTML/CSS, no framework, no build step.** Native ES modules, one small module per screen in
  `web/js/screens/`, all server calls through `web/js/api.js`, hash routing in `web/js/app.js`. Build DOM with
  `h()` / `textContent` — never put user text into `innerHTML`. Keep code plain and readable: Lesley maintains it.
- **API-first.** All rules live in the PHP JSON API; the front end only talks to it. Bearer tokens (hashed in the
  DB), no PHP sessions/cookies, so a Capacitor phone app can reuse everything.
- **The ledger is append-only and balances are computed.** Never `UPDATE`/`DELETE` `ledger_entries`; correct with an
  offsetting entry. Never store a balance as an editable number. Use `App\Ledger`.
- **All time logic goes through `App\Clock` and `App\HouseholdTime`.** No `time()`, `date()`, `new DateTime('now')`,
  or SQL `NOW()` elsewhere. Store UTC; compute "today" and household-local times with `HouseholdTime`, so tests (and
  the test site's `simulated_now`) can simulate other moments.
- Prepared statements (PDO) for every query with variable input.

## Layout
`api/` PHP (`src/` classes, `autoload.php`, `bootstrap.php`) · `web/` public front end + `web/api/index.php` entry ·
`db/migrations/` numbered SQL · `scripts/` CLI + deploy + dev scripts · `tests/` PHPUnit · `docs/`.
On the server, only `web/` is public; `api/`, `db/`, `scripts/`, and `config.php` live in a private folder
(see `scripts/deploy-cpanel.sh`). `.htaccess` files also deny web access as a backup.

## Workflow
- **Run `scripts/test.sh` before every commit.** First time in a new environment: `scripts/setup-dev.sh`.
  Say which database version the tests ran against (MySQL 5.7 via Docker if available, otherwise e.g. MariaDB).
- Commit in logical steps with clear messages. **Push at the end of a session.**
- **Tell Lesley when a change needs a migration on the test site** (she runs `php scripts/migrate.php` — see SETUP.md).
  Never edit a migration that has already shipped; add a new numbered file.
- Keep `docs/schema.md` current whenever the schema changes. Update `SETUP.md`/`README.md` if commands change
  (and tell Lesley what you changed in README).
- Seed/reset scripts must keep refusing to run unless `config.php` has `'is_test_site' => true`.
- Optional local preview: copy `config.example.php` to `config.php` (set `is_test_site` true), run
  `php scripts/migrate.php && php scripts/seed.php`, then `scripts/dev-server.sh` → http://127.0.0.1:8080.
- **At the end of each work session, update the "Where we left off" note below.**

## Where we left off
*(Update this at the end of every session.)*

**Phase 1 (Foundation)** is built, deployed to the Bluehost test site, and tested there (real PHP 8.4 + Percona 5.7).
Notes: a kid's link is shown only when created or reset (only its hash is stored), so parents use "New link" for a
fresh one. `scripts/deploy-cpanel.sh` never overwrites an existing web-root `.htaccess` (cPanel keeps the PHP version
there — overwriting it once dropped the site to an old PHP).

**Phase 2 (Chores) is built and tested in the cloud environment, but NOT yet deployed to the test site.**
Done: migration `005` (send-back note, slot `active`); `ChoreInput`/`ChoreService` (create per kid with a shared
`group_id`, edit one/all copies, remove = deactivate); `InstanceService` (on-demand idempotent instances, report/undo,
approve/send back/mark done, approvals list + count, pending earnings via `Ledger`); API endpoints (`/chores`,
`/kid/today`, `/kids/{id}/today`, `/instances/{id}/...`, `/approvals`); demo chores in the seed; front end (Chores list and
form, Home approvals + badge, parent's kid-day view, kid's today list, ~1-minute refresh + foreground refresh +
Refresh buttons). 87 PHPUnit tests pass (MariaDB 10.11 + PHP 8.3 in the cloud; not yet run on MySQL 5.7/PHP 8.4).
**The test site needs migration 005:** `php scripts/migrate.php`. To see the new demo chores she must also run
`php scripts/reset.php` (it wipes the demo data), because `seed.php` skips an existing demo household.
Decisions made where the prompt was silent (flagged to Lesley): editing can't switch repeating ↔ one-time or change
which kids a chore is assigned to; a parent's "Mark done" is never flagged late; "late" means strictly after due-by;
earnings for one-time chores belong to the day reported; chores only appear from the day they're created (no back-creation
of past days — Phase 3 must create/mark missed instances for days nobody opened the app).
Next: Phase 3 (Rules) — required chores, lock/unlock, end-of-day forfeit, missed/expired, back-fill, override.
