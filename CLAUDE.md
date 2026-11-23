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
- **Money moves between buckets only in `App\Rules\DayRules`** (unlock, forfeit, reverse, claw back) and only as
  idempotent ledger entries computed from the ledger's current sums. Phase 4's Savings/Giving split goes in
  `DayRules::moveToSpending`. Every authenticated request first runs `App\Rules\Settler` (end-of-day settlement).
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

**Phases 1 and 2** are built, merged, and deployed to the Bluehost test site (real PHP 8.4 + Percona 5.7).
Notes: a kid's link is shown only when created or reset (only its hash is stored), so parents use "New link" for a
fresh one. `scripts/deploy-cpanel.sh` never overwrites an existing web-root `.htaccess` (cPanel keeps the PHP version
there — overwriting it once dropped the site to an old PHP).

**Phase 3 (Rules) is built and tested in the cloud environment, but NOT yet deployed to the test site.**
Done: migration `006`; required chores (every slot required; `required_from`/`required_for_date` make changes start the
next day; required one-time chores gate only their due date); `Rules\DayRules` (day status + all pending/spending/forfeit
moves, idempotent); `Rules\Settler` (on-demand settlement via `households.settled_through`, runs on every authenticated
request through `Auth::onAuthenticated`; creates instances for days nobody opened; missed/carry-over with no stacking);
`Chores\InstanceCreator`; `Rules\Overrides` (back-fill, unlock, forfeit, undo approval, clear late, balance adjust,
7-day history); `scripts/settle.php`; `/clock` + the yellow "Simulated time" banner (front end takes time from the API);
`simulated_now` now accepts a local time with a zone name; seed makes Make bed and Brush teeth required; front end: Required
checkbox, kid lock/waiting/unlocked status + Spending + overdue + forfeit note (dismiss with OK), parent approvals show
waiting days, parent History screen (`#/history/<kid>`) with back-fill/overrides/adjust. 147 PHPUnit tests pass (MariaDB
10.11 + PHP 8.3 in the cloud; not yet run on MySQL 5.7/PHP 8.4).
**The test site needs migration 006** (`php scripts/migrate.php`); run `php scripts/reset.php` to get the required demo chores.
Decisions made where the prompt was silent (flagged to Lesley): a required chore finished after its day ended doesn't
revive a forfeited day (back-fill or an override does); manual unlock/forfeit overrides are sticky; waiting-day items in
the approvals list aren't counted in the badge; history shows today + the previous 7 days (never before the household
existed); back-fill sets `reported_at` to when the parent recorded it; balance adjustments require a note; the forfeit
note has an OK button; a day that already unlocked stays unlocked even if an approval is later undone.
A settle that fails is logged and skipped (the request still works). It sets `households.settle_failed_at` (migration `007`), so
parents see "Some past days couldn't be updated — tell Lesley" on Home until a later settle succeeds (tested in `SettleWarningTest`).
**The test site also needs migration 007** (`php scripts/migrate.php`).
Next: Phase 4 (Money flow) — buckets, Spending purchases, goals, rewards, challenges.
