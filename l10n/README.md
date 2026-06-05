<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Translations (l10n)

All user-facing strings in the app are wrapped with the Nextcloud translation
helpers, so the app is **translation-ready**:

- **Frontend (Vue):** `t('dataforms', '…')` and `n('dataforms', '…', '…', n)`
  from `@nextcloud/l10n`.
- **Backend (PHP):** user-facing strings go through `IL10N::t()`.

This directory holds the generated translation catalogs (`<lang>.json` and
`<lang>.js`), one pair per language. They are produced by Nextcloud's
translation tooling from the wrapped strings — they are **not** hand-written:

```bash
# from the Nextcloud server root, with this app in apps/ (or custom_apps/)
php -f build/translation-checker.php          # or: make l10n
# Transifex sync (for community translations):
tx pull -a
```

Until a catalog exists for a language, Nextcloud falls back to the source
strings (English), so the app is fully usable untranslated.
