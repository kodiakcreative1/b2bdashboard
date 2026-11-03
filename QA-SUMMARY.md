# Chamber B2B Dashboard v0.1.1 — release QA

Packaged November 3, 2026, from the latest fetched `main` commit `cc3d8f1280210abcc395f50a59d1d21b3e014f9a` (monthly cards and consistent table borders).

## Scope

The application PHP, settings implementation, routing, CSV parser and frontend CSS/template are preserved from that commit. Release changes update the packaging script, release documentation, stable tag and regression tests for the new UI. The plugin remains in the single `chamber-b2b-dashboard/` directory, with the same settings option names, group sources and cache keys. The Google Sheet and production website were not modified or contacted during this packaging run.

## Checks

| Environment | PHP integration assertions | Chromium browser assertions |
| --- | --- | --- |
| WordPress 7.1.4 / PHP 8.3.36 / MariaDB 11.4 | 75 passed | 88 passed |
| WordPress 6.6.2 / PHP 8.2.25 / MariaDB 11.4 | 75 passed | 88 passed |

- PHP syntax checks cover all five runtime PHP files and all four PHP test helpers on both PHP versions (18 successful checks).
- JavaScript syntax checks cover both runtime scripts and all three browser test runners (5 successful checks).
- The existing integration suite continues to exercise CSV parsing, currency precision, date validation, duplicate preservation, email exclusion, filters/sorting, cache failure retention, refresh locks, source isolation, all refresh intervals, and activation/deactivation safety.
- Browser regressions retain the existing navigation, metrics, filters, sorting, pagination, privacy, XSS, malformed-query, long-note, mobile, media-picker, nonce/capability and saved-color checks. Chart assertions were replaced with assertions for the twelve monthly cards now present on `main`.

## Monthly-card verification

The PHP suite checks each of January–December against independently specified expected cent totals and transaction counts. Its multi-month case covers two January transactions totaling $4.00, one April transaction of $328,000.00, a valid zero-dollar December transaction counted as one, and nine months with no transactions. An empty year must yield twelve zero totals and twelve zero counts; monthly totals/counts must reconcile with headline values.

Browser checks verify every card's month name, formatted dollars and correctly singular/plural transaction count against the known local fixture:

- 2026: October **$662,367.00 / 28 transactions**; every other month **$0.00 / 0 transactions**.
- 2025: January **$1.25 / 1 transaction**; every other month **$0.00 / 0 transactions**.
- 2024: all twelve months **$0.00 / 0 transactions**.

These are explicit synthetic QA values, not live Chamber financial results. Fixtures are excluded from the runtime ZIP.

## Transaction-table borders

Chromium checks computed styles on all six cells of the final row, requiring a solid border of at least one pixel on each of the four sides. This is checked for the recent-transactions table, the full list's first and last pagination pages, and both tables at mobile width. Both WordPress/PHP combinations pass. Desktop and mobile screenshots are saved under `artifacts/qa-*-synthetic.png`.

## Packaging and upgrade

The repository's existing `python3 tools/package.py` creates `dist/chamber-b2b-dashboard-v0.1.1.zip` and the corresponding source archive. The WordPress plugin header, `CB2B_VERSION`, stable tag and package version are all 0.1.1. Archive validation checks CRCs, a single correct root, safe paths, and exclusion of fixture/test files.

The normal WordPress Admin ZIP upload/replace flow is exercised on a third local QA site, upgrading its installed v0.1.0 plugin. The test checks successful upload, installed version 0.1.1, retained activation and exact equality of every existing settings form field before/after upgrade. Both routes must still render, and an unsynced dashboard must display twelve $0.00 / 0-transaction cards without example rows. Installed file hashes are compared with the final runtime ZIP. Evidence is saved in `artifacts/zip-install.txt` and `artifacts/zip-installed-sha256.txt`; release archive hashes are in `dist/SHA256SUMS`.

Synthetic snapshots are removed from the two fixture QA sites after regression testing, then the separate empty-state browser suite checks both routes. WordPress core can emit optional update-check warnings when WordPress.org access is unavailable in the isolated QA environments; no such warning is treated as a successful live Sheet check or a plugin regression.

## Limits

No live Google Sheet fetch or production deployment was performed. The optional `tests/live.php` checker is not part of this offline packaging regression run; its dated baseline comparison now ignores the newly added `month_counts` model field. The historical `artifacts/live-data-check.txt` records the earlier v0.1.0 network failure, not a new v0.1.1 attempt. Real source availability, official branding, host cron timing, CDN exclusions and production theme/plugin compatibility remain site-owner checks. Existing settings are retained; this release does not add or reset any settings.
