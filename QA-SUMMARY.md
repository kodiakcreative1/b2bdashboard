# Chamber B2B Dashboard 0.1.0 — beta QA

Prepared November 2, 2026, before the noon America/Chicago review target. This is an implementation and isolated-test result, not production deployment approval or a claim of verified live totals.

## Outcome

The plugin is implemented and packaged for beta review. Both public routes and protected administrator configuration work in isolated WordPress installations. No live WordPress site, form, Zap, spreadsheet or submission was changed.

**Remaining acceptance blocker: real Monday data.** Google Sheets requests were blocked by the workspace's HTTPS egress policy. A direct HTTPS request failed with proxy `CONNECT ... 403`; an actual WordPress `wp_safe_remote_get` sync also failed. The provided sheet's sharing permissions were not diagnosed as incorrect. The plugin ships the real source ID and gid `0`, with no sample records or hardcoded dashboard totals. A failed first sync shows an explicit unavailable-data message. Real count, total, monthly distribution, latest records and schema/date/group-label compatibility must be verified once network access is available.

## Executed test matrix

| Environment | PHP integration | Browser assertions | Result |
| --- | --- | --- | --- |
| WordPress 7.1.4 / PHP 8.3.36 / MariaDB 11.4 / Apache | 60 passed | 44 passed | Passed |
| WordPress 6.6.2 / PHP 8.2.25 / MariaDB 11.4 / Apache | 60 passed | 44 passed | Passed |

Chromium/Playwright ran real logged-out requests, logged-in administrator actions and 1440-pixel desktop / 390-pixel mobile layouts. PHP files passed `php -l`; production JS passed `node --check`. No PHP debug log was generated during final browser checks; no JavaScript page errors were observed. A separate two-route check verifies first-sync empty states after synthetic snapshots were removed.

`tests/zip-browser.cjs` exercises WordPress's normal plugin ZIP upload and activation on a third fresh, local WordPress site without a source bind mount. Packaging validates CRCs, safe paths, one plugin root and exclusion of tests/fixtures. The source release includes the resulting `artifacts/zip-install.txt` evidence.

The ZIP-upload site also logged WordPress core update-check warnings when optional WordPress.org access was unavailable; those warnings were not emitted by plugin code. The upload runner waits for the streamed installer to finish before assessing its result.

All fixture data is synthetic and confined to local QA WordPress databases and developer tests. Screenshots named `qa-*-synthetic.png` are layout evidence, **not actual Chamber transactions or financial results**. They are excluded from the runtime plugin ZIP. No zero-test run is represented as a pass.

## Covered behavior

- Currency parsing (`$328,000`, `$262.50`, `60`, tiny/zero/signed values); no arbitrary high-value cutoff; exact integer-cent totals and rounded averages.
- All twelve months, including zero months; year-scoped metrics and recent rows.
- Duplicate preservation, same-date descending row order, US/ISO dates and leap-day rejection.
- Quoted CSV commas/newlines, header reordering, schema validation, invalid dates/amounts/group diagnostics and blank-row exclusion.
- Column D never copied to the canonical model, cached snapshot, row diagnostics or rendered logged-out HTML, including transaction-list/filter/page responses.
- Public notes sanitized and escaped; search input escaped; malformed array-valued query parameters do not crash templates.
- Search, business/date/year filters, inclusive date ranges, invalid-range feedback, numeric amount sort, 20-row pagination and inline long-note expansion.
- Exactly four cards, standalone plugin header/footer, unique title, accessible twelve-month exact-dollar table and internal View All Transactions navigation.
- Mobile document width stays within the viewport; wide tables scroll inside their region.
- Last-good cache retention on network, HTML/login, empty and HTTP 403 responses; overlap lock, lock ownership and expired-lock recovery.
- Disabled sources cannot be made public; source-specific caches prevent cross-group data mixing; one-minute, 15-minute and 30-minute schedules.
- Logged-out refresh denied; authenticated save/refresh without nonce denied; nonce-protected settings persist; arbitrary backend URL rejected.
- Deactivation removes cron and rewrite rules, preserves cached data, and reactivation restores routes and scheduling.

## Findings corrected during development

The block theme added a second title/viewport through WordPress's head hooks. The standalone template now removes only those duplicate document hooks while retaining normal head/footer integration. Deactivation now removes in-memory plugin rewrite rules before flushing them, preventing stale route registration. Initial local installer problems (mount permissions and installation-mode rewrite flushing) were fixed in the test harness. A CSS variable inheritance issue found during review was also fixed; browser checks confirm that saved header/accent colors render correctly. These were rechecked before packaging.

## Live acceptance still unrun

The following baseline comes from the handoff and is **not a test result**:

- 56 transactions; total **$841,648.28**; largest **$328,000.00**; average **$15,029.43**.
- Latest date October 27, 2026; October 26 ties ordered by newest source row.
- Monthly USD: Jan 0; Feb 1,307; Mar 2,185; Apr 334,977.50; May 11,538.12; Jun 11,115; Jul 82; Aug 4,790; Sep 51.75; Oct 475,601.91; Nov 0; Dec 0.

`tests/live.php` is ready to perform an actual read-only server sync and print aggregates only. It compares to the dated baseline and reports differences; newer legitimate rows must not be removed to force a match. No real-data acceptance test has passed yet.

## Other limits and owner checks

- Official logo and exact brand colors could not be retrieved from the reference sites. The text wordmark and navy/teal defaults are provisional and configurable in settings.
- WP-Cron is traffic-driven. Real one-minute scheduling and the production host's outbound HTTPS/TLS/redirect behavior require host validation.
- Normal WordPress/plugin hook compatibility, SEO-plugin metadata, existing route collisions, CDN/page caching, and screen-reader operation should be reviewed on staging. Automated behavior checks are not a formal accessibility audit.
- Group validation expects each source row's `B2B Group` to equal its configured tab name, case-insensitively. Confirm against the real Monday CSV. Only Monday is initially configured; Tuesday and anticipated Wednesday remain distinct.
- Empty upstream sources retain last-good data intentionally. Five-megabyte downloads and 64-bit integer limits produce diagnostics.
- Column D is excluded; names, businesses, recipient and notes are intentionally public as requested. No source CSV, private email list or third-party brand asset was bundled.

## Network configuration saved

A draft preserves the package-manager preset and adds `docs.google.com`, `www.dubuquechamber.com` and `dubuqueb2b.org` for read-only source/reference checks. The draft save succeeded. The environment tool requires the owner to review/save settings and publish the environment; saving the draft did not apply or publish it. If Google's CSV export redirects to a separate download hostname, that observed destination may also need to be added. No broader wildcard network access was requested.

## Review and deployment

The site owner can upload the ZIP, activate it, confirm settings/verified branding, click Refresh Now and review both pages logged out. Perform this on staging first when available. The production website and its connected submission pipeline remain untouched. See README.md for the full installation, cron and troubleshooting instructions.
