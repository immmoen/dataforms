<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Deploying DataForms (sideload / custom app)

This is the guide for installing DataForms on your own Nextcloud as a **custom
app** (not via the App Store). It needs **Nextcloud 30–32** and **PHP 8.1–8.3**.

DataForms has **no runtime third-party dependencies** — Nextcloud autoloads its
classes from `lib/`. So the installable package is just the app files plus the
**built** frontend (`js/`, `css/`); there is no `composer install` to run on the
server.

---

## 1. Get the app onto the server

Pick one.

### Option A — drop the prebuilt package (fastest)

A ready-to-install tarball is produced at `build/dataforms.tar.gz` (see §4 to
rebuild it). Copy it to the server and extract into the apps directory:

```bash
# on the Nextcloud server, as the web user (e.g. www-data)
cd /path/to/nextcloud/custom_apps        # or apps-extra, or apps
tar -xzf /tmp/dataforms.tar.gz            # creates ./dataforms
chown -R www-data:www-data dataforms      # match your web user
```

### Option B — build from source (reproducible)

The built `js/`+`css/` are git-ignored, so you must build them. On a machine
with **Node 20/22** (Composer is *not* needed):

```bash
git clone https://github.com/immmoen/dataforms.git
cd dataforms
npm ci
npm run build                             # produces js/dataforms-*.mjs + css/
```

Then copy the directory to the server's `custom_apps/dataforms`, excluding the
dev-only parts: `src/`, `node_modules/`, `tests/`, `vendor/`, `.git/`, and the
root config files (`package*.json`, `composer.*`, `*.config.js`, `Makefile`, …).
The simplest way is the bundled packager: `sh scripts/package.sh` after building, or
`make appstore` (needs `make`+`rsync`).

---

## 2. Enable it

```bash
sudo -u www-data php occ app:enable dataforms
sudo -u www-data php occ upgrade          # runs the DB migrations
```

`occ status` should be clean; "DataForms" appears in the app menu.

---

## 3. Post-install — do these before real users

1. **Background jobs → Cron.** Settings → *Administration → Basic settings* →
   **Cron**, and make sure system cron runs `cron.php` every ~5 min. ⚠️ This is
   the most important step: the deferred automation actions (email, webhook, Talk,
   Deck) run on the job queue. (Folder/template/calendar actions run *inline* and
   don't need this.) On **AJAX** cron they fire late/erratically; with no cron at
   all they never fire.
2. **Backups.** Make sure the Nextcloud database is in your backup rotation —
   record deletes are soft-deletes with **no in-app restore** yet.
3. **Optional — cross-app service account.** Only if you'll use the *Create a
   Talk room* / *Create a Deck board* actions: Settings → *Administration →
   DataForms* → add a service account (its app-password is stored encrypted).
4. **Leave `allow_local_remote_servers` at its default (false)** so the webhook
   SSRF guard stays effective.

---

## 4. Rebuild the installable package

```bash
npm ci && npm run build       # refresh js/ + css/ for the current version
sh scripts/package.sh              # → build/dataforms.tar.gz (clean, no dev cruft)
# or, with make + rsync:
make appstore                 # also prints the (optional) signing command
```

The tarball contains only `appinfo/ lib/ templates/ js/ css/ img/ l10n/ docs/`
plus the licences and `openapi.json` — no `src`, `tests`, `vendor`, or
`node_modules`.

---

## 5. Verify

- `occ upgrade` reports `Updated <dataforms> to <version>` with no errors.
- The app loads (open it from the app menu; create a register and a record).
- An automation fires end-to-end (e.g. a *Create folders* action makes the
  folders on save; check **Automations → Activity** for the run/outcome).
- **Pilot first:** put one or two real users on a non-critical register for a few
  days before opening it up.

---

## 6. Updating later

Bump `<version>` in `appinfo/info.xml`, rebuild (§4), replace the
`custom_apps/dataforms` directory, then:

```bash
sudo -u www-data php occ upgrade
```

---

## 7. App Store (later)

To publish on apps.nextcloud.com instead of sideloading: register an App Store
account, obtain the Nextcloud-issued signing certificate, then `make appstore`
and sign with `occ integrity:sign-app`. You'll also need 1–3 listing screenshots
(absolute HTTPS URLs) wired into `appinfo/info.xml` (`<screenshot>`). The
`info.xml` already validates against the App Store schema.
