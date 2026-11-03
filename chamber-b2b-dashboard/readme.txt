=== Chamber B2B Dashboard ===
Contributors: chamberb2b
Tags: dashboard, business, reporting, google-sheets
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone read-only Chamber B2B reporting, with cached Google Sheet transactions and private email exclusion.

== Description ==
Public dashboard and searchable transaction list. All production assets are included. Only Monday is enabled initially. Configure the sheet and branding under Settings > Chamber B2B; click Refresh Now to verify live data on your server. Development live-sheet verification was blocked by workspace egress policy. No sample records ship in this plugin. See README.md for full instructions and beta limitations.

== Installation ==
1. Upload the plugin ZIP via Plugins > Add New > Upload Plugin.
2. Activate; confirm a non-Plain permalink structure.
3. Open Settings > Chamber B2B; configure/confirm source, group and branding.
4. Click Refresh Now; review diagnostics and real totals while logged out.

== Frequently Asked Questions ==
= Does this modify the source sheet, forms, Zapier or theme? =
No. It performs read-only HTTP CSV downloads and writes only its own WordPress configuration/cache/scheduled events.

= Is email visible publicly? =
The Submitted by Email column is excluded from the public model and cache. No public JSON or export endpoint is registered.

= Are refreshes precisely once per minute? =
No. WP-Cron depends on traffic. Use a host-managed cron for reliable timing. Network errors retain the last successful snapshot.

== Changelog ==
= 0.1.2 =
Independent Historical dashboard, validated read-only summaries, separate cron/cache/refresh controls, upgrade-safe routes and settings preservation.

= 0.1.1 =
Twelve monthly dollar/count cards and consistent transaction-table borders.

= 0.1.0 =
Initial review beta: standalone routes, server-side CSV sync/cache, public reporting and protected settings.
