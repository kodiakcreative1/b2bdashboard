# Chamber B2B Dashboard v0.1.2 — release validation

Reviewed from GitHub `main` commit `bda9703` and packaged November 3, 2026. No production WordPress website, form, Zapier integration or Google Sheet was changed. Google access was read-only.

## Corrections made during review

- Replaced the duplicated/malformed main plugin class with one clean implementation preserving v0.1.1 current reporting and integrating all three routes/templates. The incoming malformed rewrite declarations would prevent activation.
- Added upgrade-time route initialization because WordPress ZIP updates need not rerun activation. Existing settings/cache keys are retained; both cron events and all routes are removed safely on deactivation.
- Made historical refresh independent: separate scheduled hook, manual administrator/nonce-protected refresh, cache/status, overlap lock and diagnostics. Transaction sync does not fetch or aggregate history.
- Historical validation now fails closed on any invalid/duplicate row, subtotal mismatch, integer overflow or disappearance of previously cached groups. Previously valid cached history survives partial, empty, HTML, oversized, transport and HTTP failures.
- Active tab/group matching is authoritative, preventing another group's history from appearing under a changed display name/key. Disabled/unmatched groups show no history.
- Fixed BOM-prefixed quoted CSV headers and added coverage to both historical and current parsers. Existing valid transaction behavior is retained.
- Added a documented Google read-only query-CSV fallback for export transport failures; TLS and WordPress safe URL checks remain enabled.
- Matched the requested four historical summary labels, stale status, breakdown cards and three-link navigation. Navigation wraps on narrow phones.

## Executed checks

Both isolated environments (WordPress 7.1.4 / PHP 8.3.36 and WordPress 6.6.2 / PHP 8.2.25, MariaDB 11.4) run:

| Suite | Assertions per environment |
| --- | --- |
| Current transaction PHP regressions | 76 passed |
| Historical PHP integration | 65 passed |
| Existing dashboard Chromium regressions | 88 passed |
| Historical Chromium checks | 33 passed |

PHP syntax checks cover all runtime/helper PHP files on both PHP versions. JavaScript syntax checks cover both runtime scripts and every browser test runner. Evidence is in `artifacts/`.

Current regressions include exact-cent calculations, all twelve monthly amounts/counts including empty months/years, final-row borders on recent/full tables including pagination/mobile, search/date/business/year filters, numeric sorting, duplicates, private email exclusion, note escaping, nonce/capability checks, refresh locks/fallback, custom colors, media picker, schedules and settings.

Historical tests cover all five CSV headers, BOM/reordered columns/blank rows, malformed input, quoted currency, exact reference totals for all three groups, overflow/signed amounts, whole-snapshot failure retention, missing-group imports, export fallback, independent current cache/settings, locks, intervals, deactivation/reactivation and ZIP-update initialization. Browser tests cover all three public routes/nav links, four summary cards, three breakdown cards, no current-data mixing, authoritative Monday/Tuesday/Thursday group selection, protected actions, diagnostics, no email payload and 768/390/320px layouts.

Historical screenshots use test reference fixtures with the same values independently verified in the live CSV. Current screenshots use synthetic transactions. Neither fixture set is included in the installable ZIP.

## Actual Google historical data

An HTTPS read-only request to the configured workbook's Google query CSV endpoint returned status 200 and three summary records. That real CSV was parsed by the plugin and checked against these references (also confirmed to reconcile in integer cents):

| Group | Established | Through 2024 | 2025 | Grand total |
| --- | --- | --- | --- | --- |
| Monday | 2021 | $1,868,857.90 | $929,216.00 | $2,798,073.90 |
| Tuesday | 2014 | $15,253,960.30 | $127,499.39 | $15,381,459.69 |
| Thursday | 2016 | $1,203,220.93 | $440,773.77 | $1,643,994.70 |

These are validation expectations only; runtime totals are loaded from Google, never hardcoded. See `artifacts/live-historical.txt`.

## Environmental limitation / checks not completed live

A native live WordPress safe-HTTP sync could not complete in this proxy-only QA runtime: DNS resolution required by `wp_safe_remote_get` returned `http_request_failed: A valid URL was not provided`. Direct proxied HTTPS CSV download works. URL-safety and TLS validation were not disabled to force a pass. Live parser/reference verification and mocked native HTTP success/failure tests passed; **normal-host end-to-end current and historical sync still requires production/staging verification**. The production site was not accessed or deployed to. Optional WordPress.org update checks can log core network warnings in these local QA environments.

## Packaging and upgrade

`python3 tools/package.py` produces `chamber-b2b-dashboard-v0.1.2.zip`, with the unchanged `chamber-b2b-dashboard/` root, all production assets, and no tests/fixtures/development dependencies. Version header, constant, stable tag and packaging target are 0.1.2. A separate source archive and SHA256SUMS accompany the release.

The existing WordPress ZIP-upload test upgrades the isolated v0.1.1 copy, confirms active version 0.1.2, compares every saved settings form field before/after, and checks all three routes plus honest unsynced states. Installed file hashes must match the final ZIP. See `artifacts/zip-install.txt` and `artifacts/zip-installed-sha256.txt`.

## Owner verification after upload

Confirm existing logo/colors/source/group/interval settings remain correct. Run both Refresh Now and Refresh Historical Now, check their independent last-success/status fields, compare the active group's historical values, and verify current totals separately. Confirm host DNS/TLS/outbound access, traffic-driven WP-Cron (or server cron), CDN exclusions, and theme/plugin compatibility. An unmatched group is deliberately not substituted, and an invalid/partial historical import retains old data with an error. Existing branding configuration is preserved; no replacement official logo was introduced.
