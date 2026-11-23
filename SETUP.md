# Setting up the Bluehost test site

A step-by-step guide for the **test site** (demo family only — never real data). You'll do most of this once. Menu
names in cPanel move around a little between Bluehost plans; use the search box at the top of cPanel if you can't
spot one.

You'll end up with three things:

| What | Example | Where |
|---|---|---|
| A test web address | `chore-test.yourdomain.com` | its folder is public |
| A private app folder | `/home/YOURUSER/choretracker-test` | **not** reachable from the web |
| A database | `YOURUSER_choretest` | MySQL |

> Replace `YOURUSER` with your cPanel username (shown on the right side of the cPanel home page).

## 1. Make the test subdomain
1. In cPanel, open **Domains** (or **Subdomains** on older layouts) → **Create A New Domain / Subdomain**.
2. Enter something like `chore-test.yourdomain.com`. Use a domain you own — **not** stayingatthehelm.com.
3. Note the **Document Root** it suggests (for example `public_html/chore-test`). That is the folder web visitors see;
   you'll need it in step 4. Turn on **HTTPS** for it (Bluehost's free SSL — look under **SSL/TLS Status** and run
   *AutoSSL* for the new subdomain if it isn't secured automatically).
4. In **MultiPHP Manager**, set this subdomain to **PHP 8.4**.

## 2. Make the database
1. cPanel → **MySQL® Databases**.
2. **Create New Database**: name it `choretest` (cPanel adds the `YOURUSER_` prefix).
3. **Add New User**: name `choreuser`, click **Password Generator**, and **save the password somewhere safe**.
4. **Add User To Database**: choose the user and database → **ALL PRIVILEGES** → Make Changes.
5. Write down three things: database name, user name (both with the `YOURUSER_` prefix), and the password.

## 3. Connect the GitHub repository (Git Version Control)
1. cPanel → **Git™ Version Control** → **Create**.
2. Turn **Clone a Repository** on.
3. **Clone URL:** `https://github.com/LesleyPLangdon/choreTracker.git` (the repo is public, so no password is needed).
4. **Repository Path:** a folder *outside* `public_html`, e.g. `/home/YOURUSER/repositories/choreTracker`.
5. **Repository Name:** `choreTracker`. Click **Create**.

> **If you make the repo private later:** a private repo can't be cloned over plain HTTPS without a login. Instead:
> in cPanel → **SSH Access** → **Manage SSH Keys** → *Generate a New Key*, **Authorize** it, then add its *public key* to
> GitHub (repo → **Settings → Deploy keys**, read-only is fine). Re-create the clone using the SSH URL
> `git@github.com:LesleyPLangdon/choreTracker.git`. Nothing else changes.

## 4. Tell the deploy where your site lives
The deploy script (`scripts/deploy-cpanel.sh`) needs to know your folders. This is kept in a small file in your
home folder — *outside* the repo — so nothing account-specific is stored on GitHub.

1. cPanel → **File Manager** → make sure **Show Hidden Files** is on (Settings, top right) → go to your home folder
   (`/home/YOURUSER`).
2. **+ File** → name it `.choretracker-deploy.conf` → open it with **Edit** and paste (use your own document root
   from step 1):
   ```
   WEB_DIR="$HOME/public_html/chore-test"
   APP_DIR="$HOME/choretracker-test"
   ```
3. Save. (`APP_DIR` is the private folder; the script creates it.)

## 5. First deploy
1. **Git™ Version Control** → **Manage** next to `choreTracker`.
2. **Pull or Deploy** tab → **Update from Remote**, then **Deploy HEAD Commit**.
3. The deploy copies the public files into `WEB_DIR` and the private code into `APP_DIR`. If a "deployment
   cannot be queued" message appears, the repository has uncommitted changes in the server clone — don't edit files
   in the clone; ask Claude.

## 6. Create `config.php` (the private settings file)
1. **File Manager** → open `APP_DIR` (`/home/YOURUSER/choretracker-test`). You'll see `config.example.php`.
2. Select it → **Copy** → name the copy `config.php`.
3. **Edit** `config.php` and fill in:
   - `db` → `host` `localhost`, `name`, `user`, `password` from step 2 (with the `YOURUSER_` prefixes).
   - `is_test_site` → `true` (**test site only!** This is what allows the seed/reset scripts to run.)
   - `app_url` → your test address, e.g. `https://chore-test.yourdomain.com`
   - `web_migrate` → leave `enabled` as `false` for now (see "No Terminal?" below).
4. Save. The deploy never overwrites `config.php`, and git ignores it, so your password stays private.

## 7. Create the tables and the demo family
Open cPanel → **Terminal** and run (replace `YOURUSER`):

```bash
php -v
```
If it says PHP 8.4 or 8.3, continue. If it shows an older version, use the full path instead of `php` in the commands
below, e.g. `/opt/cpanel/ea-php84/root/usr/bin/php`.

```bash
php /home/YOURUSER/choretracker-test/scripts/migrate.php
```
```bash
php /home/YOURUSER/choretracker-test/scripts/seed.php
```
`seed.php` prints the two kids' links. **Copy them now** — they're not shown again (use **New link** on the Kids
screen any time to make fresh ones). Then open your test address and sign in:

- Parents: `test@example.com` or `parent2@example.com` — password `Correct-Horse-42`
- Kids: open the kid's link and type PIN `1234`.

To wipe everything and start over with a fresh demo family: `php /home/YOURUSER/choretracker-test/scripts/reset.php`
(it refuses to run unless `is_test_site` is `true`).

### No Terminal? Use the browser fallback for migrations
1. In `config.php`, set `'web_migrate' => ['enabled' => true, 'key' => 'PASTE-A-LONG-RANDOM-STRING']`
   (at least 20 characters; use a password generator).
2. Visit `https://chore-test.yourdomain.com/migrate.php?key=PASTE-A-LONG-RANDOM-STRING`. It lists what it applied.
3. **Set `enabled` back to `false`** when done. (With it off, the page just says "Not found".)

The browser page only migrates; seeding needs Terminal.

## 8. Update the test site after Claude pushes
1. cPanel → **Git™ Version Control** → **Manage** → **Update from Remote**.
2. Click **Deploy HEAD Commit**.
3. If Claude said the database changed (a new migration), run the migrate command from step 7 (or the browser fallback).

## What's different for the real (production) site later
- **Its own** subdomain/domain, database, user, and `config.php` — never share the test database.
- **`is_test_site` must be `false`** (or removed) so the seed and reset scripts can never run there.
- **Don't run the seed script** or create demo accounts on production.
- `web_migrate.enabled` stays `false`; run migrations from Terminal.
- Use a separate `.choretracker-deploy.conf` target (different `WEB_DIR` / `APP_DIR`), ideally deployed from a
  tagged/known-good commit rather than every push.
- Turn on backups for the production database, and keep the site on HTTPS (kid links and sign-ins travel over it).

## Troubleshooting
- **"The app is not set up correctly yet"** on the sign-in screen: `config.php` is missing or its database details
  are wrong (check the `YOURUSER_` prefixes), or migrations haven't run.
- **Signed in but immediately signed out / "Please sign in" everywhere:** the web server may be dropping the
  `Authorization` header. `web/api/.htaccess` already handles the common case; tell Claude if it still happens.
- **Blank page / 500 on `/api/...`:** check **Metrics → Errors** in cPanel for the PHP error message.
- **"PHP Parse error" lines in `api/error_log`, or a 500 on sign-in:** the site is running an older PHP than 8.3.
  Set the domain to **PHP 8.4** in cPanel → **MultiPHP Manager** and click Apply. cPanel stores that choice in the
  `.htaccess` file in the site's web folder. The deploy script never overwrites an existing `.htaccess`, so the setting
  survives later deploys. (If you ever delete that file, set the PHP version again.)
- **`command not found` with a `$'\r'` when the deploy runs:** `~/.choretracker-deploy.conf` has Windows line endings
  (usually from pasting). Fix it with `sed -i 's/\r$//' ~/.choretracker-deploy.conf`.

