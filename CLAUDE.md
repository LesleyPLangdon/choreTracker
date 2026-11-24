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
- **Moves out of Pending (unlock, the Savings/Giving split, forfeit, reverse, claw back) happen only in `App\Rules\DayRules`**,
  as idempotent ledger entries computed from the ledger's current sums. Everything a parent or kid does with settled money
  (payouts, transfers, adjustments, goal moves, rewards) lives in `Money\Wallet`, `Money\GoalService` and `Money\RewardService`, which
  lock the kid (`Money\Locks::kid`), check the balance, then append entries; nothing ever goes below zero. Every authenticated request
  first runs `App\Rules\Settler` (end-of-day settlement) and refreshes challenge periods.
- **Services are built once in `App\Services`** (the API and the tests both use it); add new services there.
- **A currency shows on a kid's screens only if the kid uses it** (`Money\Currencies`): never an empty "$0.00" row for a points-only kid.
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

**Phases 1-3** are built, merged, and deployed to the Bluehost test site (real PHP 8.4 + Percona 5.7). Notes: a kid's link is
shown only when created or reset (only its hash is stored). `scripts/deploy-cpanel.sh` never overwrites an existing web-root
`.htaccess` (cPanel keeps the PHP version there). A failed settle is logged and skipped, sets `households.settle_failed_at`
(migration `007`), and parents see "Some past days couldn't be updated — tell Lesley" on Home until a later settle succeeds.

**Phase 4 (Money flow) is built and tested in the cloud environment, but NOT yet deployed to the test site.**
Done: migration `008`; `App\Services` container; Savings/Giving split in `DayRules::moveToSpending` (with reversals across all
buckets); `Money\Buckets`, `Wallet` (payout, mark transferred/given, adjust any bucket), `GoalService` (requests, links, pending
changes, moves in/out/between, reached, used, cancel, plan limit via `Plans`), `RewardService` (visibility, request/approve/deny/cancel/
delivered, "Save for this" and pay-from-goal-first), `ChallengeService` (once/daily/weekly windows, on-demand periods, counting, overrides),
`Activity` (plain-language list from the ledger), `Currencies`/`Summary` (per-kid currency visibility), `Approvals\ApprovalList` (all item
types and the badge). Front end: kid home (day status, Spending, goal bars, read-only Savings/Giving, links), kid Goals / Rewards /
Challenges / Activity screens; parent Money screen per kid, Rewards, Challenges (+ form), approval items for every new type, Savings &
Giving % on the Kids screen. Seed: Alex uses points and money (10% Savings, 10% Giving on money), Sam points only, three rewards, two goals
(one with a link), the two example challenges; the demo household is on the `plus` plan so several goals can be tried. 234 PHPUnit tests
pass (MariaDB 10.11 + PHP 8.3 in the cloud; not yet run on MySQL 5.7/PHP 8.4).
**The test site needs migration 008** (`php scripts/migrate.php`); run `php scripts/reset.php` to get the Phase 4 demo data (it wipes the
demo data and prints new kid links).
Decisions made where the prompt was silent (flagged to Lesley): a challenge window that was already open when the challenge was created
counts everything reported inside it; a daily window must end the same day; challenges can be created and removed but not edited; the
split is applied to each unlock and rounds down; the kid's Goals link always shows and Rewards/Challenges only if there are any; a goal
or reward-request denial keeps the kid's money untouched; a reached goal returns to active if money is taken out of it.
Next: Phase 5 (Polish) — home-screen install (PWA) and the visual theme. Gamification (earned/bought avatars, games) is planned separately.
