# Chamber B2B Dashboard 0.1.2 beta

An installable, standalone WordPress dashboard for the Dubuque Area Chamber of Commerce B2B groups. The plugin reads Google Sheets on the server, caches sanitized public records and renders `/dashboard/`, `/dashboard/transactions/` and `/dashboard/historical/`. No paid dependencies, API keys, build step or external frontend libraries are required.

**v0.1.2 release:** adds a separate Historical page and independently refreshed/validated Google Sheet summaries. The release was reviewed from `main` commit `bda9703`; malformed duplicated route code and unsafe partial historical cache replacement were corrected. Existing transaction reporting and saved settings are retained. See [QA-SUMMARY.md](QA-SUMMARY.md) for automated checks and actual historical CSV validation. No production website or Sheet was modified. The local QA environment cannot complete WordPress safe-HTTP DNS validation; a normal-host live sync remains a deployment check. No reference figures are hardcoded into runtime code.

## Deliverables

- `dist/chamber-b2b-dashboard-v0.1.2.zip`: uploadable plugin, one `chamber-b2b-dashboard/` root directory.
- `chamber-b2b-dashboard/`: complete runtime source, styles, scripts and GPL license.
- `dist/chamber-b2b-dashboard-source-v0.1.2.zip`: source, local testing tools, documentation and QA evidence.
- `tests/` and `tools/`: isolated WordPress integration/browser tests and reproducible packaging.

## Requirements

- WordPress **6.6+**, **64-bit PHP 8.2+**, pretty permalinks and standard WordPress database support.
- Tested combinations: WordPress 6.6.2 / PHP 8.2.25 and WordPress 7.1.4 / PHP 8.3.36, Apache, MariaDB 11.4; local Docker installations. This is not a claim to have tested every intermediate version or the production site's plugin stack.
- WordPress server must reach `docs.google.com` over HTTPS and follow Google's public download redirects. A Google-selected `*.googleusercontent.com` download host may also need outbound access. Keep TLS verification enabled. Do not allow arbitrary user-supplied fetch destinations.
- The sheet must allow unauthenticated read access to its CSV export. An upstream permissions/login/HTML response produces diagnostics rather than fabricated data.

## Site-owner deployment checklist

1. Prefer a staging site for the first review. Back up using your normal site process. Upload `chamber-b2b-dashboard-v0.1.2.zip` under **Plugins → Add New → Upload Plugin**, then activate.
2. Open **Settings → Chamber B2B**. The defaults already select the provided spreadsheet and Monday tab, gid `0`, active key `monday`, one-minute refresh. Confirm these values and save. Choose a verified Chamber logo using **Choose image**, and set official brand colors if available.
3. Click **Refresh Now** for transactions and **Refresh Historical Now** for the separate historical summaries. Confirm source status, record count, last success and any excluded-row diagnostics. Errors do not erase the last good snapshot.
4. Open `/dashboard/`, `/dashboard/transactions/` and `/dashboard/historical/` while logged out. Check 2026 totals against the real sheet. If unchanged since November 1: **56 transactions, $841,648.28 total, $328,000.00 largest, $15,029.43 average**, latest date October 27. These numbers are reference expectations, not application constants.
5. Confirm mobile layout, filters, logo and notes. Confirm Column D email never appears in public HTML or responses. Keep Google Sheets, Gravity Forms, Zapier and existing submissions unchanged.

No manual source edits are needed to configure or run the plugin. This development task did not deploy to or modify the live website.

## Dashboard behavior

- Exactly four year-scoped metric cards, twelve monthly cards with exact dollar totals and transaction counts, including zero-transaction months, and five latest transactions. Initial reporting year is 2026. Other years present in the source are selectable.
- Transactions page searches public date/name/business/recipient/details/formatted amount, sorts by date or numeric amount, filters by year, inclusive date range and submitting business, and paginates at 20 rows. List filters intersect the selected year and do not modify overview totals.
- Equal transaction dates use newest sheet row first, regardless of amount sort direction. Duplicate-looking submissions remain separate.
- Recipient is one unsplit field. Long notes expand inline, including on mobile; narrow tables scroll inside their container. Navigation, filters, monthly labels and notes work without JavaScript.
- Transaction dates and sync timestamps use `America/Chicago`, including daylight saving time. The last-updated label always reflects the last successful server sync, not the current page request.
- No public group switch, transaction detail pages, leaderboards, login system, PDF export, spreadsheet editing or public transaction JSON endpoint.

## Source and validation

The default spreadsheet ID is `19_wSAJv4c7Rw08pRR6wp58FsQnr4kw1UZ3faR-apKeY`; the plugin constructs an HTTPS Google `/export?format=csv&gid=...` request. The admin accepts a spreadsheet ID or normal `https://docs.google.com/spreadsheets/d/...` URL, not arbitrary backend URLs.

Expected exact headers (reordering is supported):

`Date`, `Submitted by Name`, `Submitted by Business`, `Submitted by Email`, `B2B Group`, `Recipient of Closed Business`, `Value of Closed Business`, `Details`.

Quoted commas, UTF-8 BOM and embedded CSV newlines are supported. USD is parsed to integer cents with validated comma placement and at most two decimal digits. Dues, donations, tiny transactions, zero amounts and major closings are included; there are no category or business-size cutoffs and no de-duplication. Signed amounts are supported. The technical limit is 64-bit integer capacity, not a business rule; oversized input produces diagnostics. A 5 MB response safety limit protects the WordPress worker.

US dates (`m/d/Y`, `m/d/y`), ISO dates (`Y-m-d`), common US numeric timestamps and English month-name dates are accepted without date rollover. ISO dates are recommended for future consistency. Invalid dates/amounts, incorrect row widths and unexpected group values are excluded with row-only admin diagnostics. A valid dated/amount-bearing row still counts when optional public text fields are blank. Blank rows do not count. Missing/duplicate required headers, HTML/login responses or no valid records fail the sync and retain the prior snapshot. A successful partial parse reports its excluded rows: **review those diagnostics before accepting totals**. When genuinely clearing all transactions, contact the maintainer; empty upstream responses deliberately cannot erase the last-good cache automatically.

Column D is read only as part of the transient CSV input and is never copied into normalized records, persisted public cache, diagnostics or frontend output. Notes and public text fields are sanitized, then escaped on rendering. The CSV response is not logged or saved by the plugin.

## Refresh and caching

Activation schedules a first sync shortly afterward. **Refresh Now** is a synchronous administrator-only action with capability and nonce checks. Scheduled syncs use the same code and a 120-second ownership-checked lock. Requests have a 20-second timeout and at most three redirects. Visitors read the WordPress cache; the template never starts its own Google download.

Refresh choices are 1, 15 or 30 minutes (default 1). WP-Cron is traffic-driven and cannot promise exact timing. For reliable refreshes, have the host run due WordPress events every minute, for example with WP-CLI from the site directory:

```cron
* * * * * cd /path/to/wordpress && /usr/local/bin/wp cron event run --due-now --quiet
```

Your host should supply the actual path and PHP/WordPress user. If replacing traffic-triggered WP-Cron with a system cron, your host can configure that in the usual way; the plugin does not edit `wp-config.php`.

On an upstream error the previous successful data remains visible with a stale warning. A warning also appears when success is older than two refresh intervals (minimum two minutes). Before any successful sync, the pages explicitly say data is unavailable; they never substitute example transactions. Plugin pages send no-cache headers and set `DONOTCACHEPAGE`. Exclude all dashboard paths from any CDN or reverse-proxy full-page cache, which may ignore WordPress headers.

## Future groups

Settings provide an editable group-source table: unique key, display name, exact tab / expected `B2B Group` value, numeric gid and enabled state. One enabled active group is public at a time. Sources not selected are not fetched. Changing spreadsheet, group, gid or expected tab/group value uses a distinct cache so another group's old data cannot appear under the new selection.

Only Monday ships configured. Known handoff tabs are Monday `0`, Tuesday `395825866`, Thursday `125109628`. Wednesday is a distinct anticipated future group and has no verified gid; never silently map it to Tuesday. Group validation currently compares the row's `B2B Group` value to the configured tab name, case-insensitively. If the actual sheet uses another group label, confirm that mapping before enabling the source.

## Branding and route assumptions

The navy/teal defaults are **provisional, not verified official Chamber colors** and were not inferred from City of Dubuque branding. No unverified official logo is included. A text wordmark appears until an administrator chooses the correct logo. Fonts and card styles are local/system based.

The plugin owns `/dashboard/`, `/dashboard/transactions/` and `/dashboard/historical/` while active and renders a standalone document through normal WordPress routing/bootstrap, without theme header/footer templates. Existing pages at these paths are not edited; their routes can be shadowed while the plugin is active, with a settings warning. Standard `wp_head`/`wp_footer` hooks remain for WordPress compatibility. Third-party theme/plugin styles, SEO metadata and scripts should be checked on staging. The default block-theme duplicate document title/viewport hooks are removed only inside plugin templates.

Deactivation removes scheduled syncs and plugin rewrite rules but retains settings and sanitized cached data for reactivation. No uninstall script deletes site data. Administrators can remove `cb2b_*` options separately if permanently retiring the plugin. No changes are made to Gravity Forms, Zapier, Google Sheets, theme files or Elementor templates.

## Troubleshooting

- **No data / HTML or permission error:** click Refresh Now, inspect diagnostics and confirm the public CSV endpoint actually works from the WordPress server. A spreadsheet view working in your authenticated browser does not establish anonymous server access. Do not disable TLS verification.
- **HTTP/network error:** ask the host to check HTTPS egress, DNS, TLS trust and Google's redirect host. The development workspace's network restriction is separate from the production server's access.
- **Totals differ:** check selected year, newly added rows, exact tab/group mapping and invalid-row diagnostics. Preserve duplicate submissions. Do not remove high-value closings, dues or donations to force the old baseline.
- **404 or redirect to WordPress admin at `/dashboard/`:** enable pretty permalinks; save Settings → Permalinks to rebuild routes. A path collision or another routing plugin may need staging review.
- **Old data after refresh:** verify last success and error status, WP-Cron, reverse-proxy/CDN cache exclusions and active source.

## Architecture and developer tests

`includes/class-data.php` is the canonical CSV/date/money/filter/metrics layer. `class-plugin.php` owns routing, HTTP fetches, snapshots, locks and scheduling. `class-admin.php` owns administrator-only configuration/actions. The current reporting pages use `templates/dashboard.php` and the same normalized model. No runtime Composer/npm dependencies or compile step exist.

Use the existing checkout: cloud tasks are already isolated; no Git worktree is needed. The test helper creates **local-only** Docker WordPress/MariaDB containers and fixed, explicitly nonproduction QA accounts. Never expose these containers or reuse the QA credentials on a real site. Review your environment's Docker proxy/CA requirements before network operations.

```sh
./tools/local-site.sh
# On this managed Docker host, use the local Unix socket explicitly:
docker --host=unix:///var/run/docker.sock exec -u www-data cb2b-qa-wp php /qa/run.php
node tests/browser.cjs
# Optional real read-only check; deletes synthetic data on this QA instance first:
docker --host=unix:///var/run/docker.sock exec -u www-data cb2b-qa-wp php /qa/live.php
python3 tools/package.py
```

The browser runner requires Node, Playwright and Chromium (`CHROMIUM_PATH` override supported). It can target the second **local** QA site with `CB2B_BASE_URL=http://localhost:8089`. The live checker compares actual metrics to the dated handoff baseline and prints aggregates only. If the source changed, review differences rather than forcing expected values into runtime code. Fixtures and test credentials are excluded from the installable ZIP.

For the older supported combination:

```sh
CB2B_QA_SUFFIX=-legacy CB2B_QA_PORT=8089 \
CB2B_QA_IMAGE=wordpress:6.6.2-php8.2-apache ./tools/local-site.sh
docker --host=unix:///var/run/docker.sock exec -u www-data cb2b-qa-legacy-wp php /qa/run.php
CB2B_BASE_URL=http://localhost:8089 node tests/browser.cjs
```

Local containers can be stopped with `docker --host=unix:///var/run/docker.sock stop cb2b-qa-wp cb2b-qa-db cb2b-qa-legacy-wp cb2b-qa-legacy-db`. Live processes are not needed by the deliverable. PHP lint and JS syntax checks are listed in QA-SUMMARY.md. License: GPL-2.0-or-later.

## Historical reporting (v0.1.2)

`/dashboard/historical/` shows four primary cards (Historical Closed Business, Through 2024 Total, 2025 Closed Business, Group Established) and three breakdown cards using the existing dashboard styling. Overview / Transactions / Historical navigation appears on all pages. Historical summaries are not transactions and never enter monthly cards, transaction counts, filters or recent-business tables.

The same configured workbook is read from gid **884257498**. Required headers are `B2B Group`, `Starting Year`, `Through 2024 Total`, `2025 Total`, `Grand Total`. Column order may change; quoted CSV and UTF-8 BOM are supported. Each group must occur once. Values use integer cents and must satisfy **Through 2024 Total + 2025 Total = Grand Total**. Invalid/duplicate rows or missing previously cached groups reject the whole import and retain the last successful snapshot. Group removal or an intentional complete reset requires maintainer review rather than silently discarding valid history.

The active group's configured **tab** is the authoritative matching key, ignoring case and surrounding/repeated whitespace. A custom display name does not change mapping. A missing tab match shows an unavailable message; it never substitutes another group's figures based on an old display name or key. Legacy configurations lacking a tab can match their display name/key. Only an enabled active group is public.

History has its own workbook/gid cache, status, five-minute ownership lock, and `cb2b_historical_sync` event. It follows the configured 1/15/30-minute interval independently of `cb2b_sync`. Settings → Chamber B2B includes separate status, timestamps, group mapping and **Refresh Historical Now** controls (administrator + nonce required). Transaction Refresh Now continues to refresh transactions independently. Each source retains its last-good data on failures. Stale history is labeled publicly, including delayed cron. The history fetch first tries Google CSV export, then the documented read-only Google query CSV endpoint if export transport/permissions/HTML fails. Both URLs are generated from the configured workbook and fixed historical gid; TLS and WordPress URL safety remain enabled.

A ZIP update performs one-time route/schedule initialization without replacing `cb2b_settings` or transaction cache keys. Deactivation clears both cron events and all three plugin routes while retaining settings/data. If another plugin blocks rewrite updates, save Settings → Permalinks once on the target site.

Live CSV read-only checks confirmed the three supplied historical totals on November 3, 2026. This verifies the Google values and parser, not the target host's future connectivity. Run `php /qa/historical.php` and `node tests/historical-browser.cjs` on the isolated QA sites for historical regressions. `tests/live-historical.php` attempts a real WordPress sync; optionally supply a local actual CSV path to validate downloaded live data without weakening URL safety. The original current-data live checker is optional; it is not a production deployment action.
