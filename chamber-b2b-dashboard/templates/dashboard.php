<?php
if (!defined('ABSPATH')) { exit; }
use CB2B\Plugin;
use CB2B\Data;
$is_list = get_query_var('cb2b_page') === 'transactions';
$settings = Plugin::settings(); $group = Plugin::active(); $snapshot = Plugin::snapshot(); $status = Plugin::status();
$records = $snapshot['records'] ?? [];
// Scalar-only query parameters prevent array injection and keep malformed URLs harmless.
$input = static function (string $name, string $default = ''): string {
    return isset($_GET[$name]) && is_string($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : $default;
};
$year = $input('report_year', '2026');
if (!preg_match('/^(19|20|21)\d{2}$/D', $year)) { $year = '2026'; }
$years = array_unique(array_merge(['2026', $year], array_map(static fn($r) => substr($r['date'], 0, 4), $records))); rsort($years);
$filters = ['year' => $year, 'search' => $is_list ? substr($input('search'), 0, 200) : '', 'from' => '', 'to' => '', 'business' => $is_list ? $input('business') : '', 'sort' => $is_list && $input('sort') === 'amount' ? 'amount' : 'date', 'order' => $is_list && $input('order') === 'asc' ? 'asc' : 'desc'];
$filter_error = '';
if ($is_list) {
    foreach (['from', 'to'] as $field) {
        $raw = $input($field);
        if ($raw !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw) || Data::date($raw) !== $raw)) { $filter_error = 'Enter valid dates in the date-range fields.'; }
        elseif ($raw !== '') { $filters[$field] = $raw; }
    }
    if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) { $filter_error = 'The start date must be on or before the end date.'; }
}
$year_records = Data::filter($records, ['year' => $year]);
$metrics = Data::metrics($year_records);
$filtered = $is_list ? Data::filter($records, $filters) : $year_records;
if ($filter_error) { $filtered = []; }
$page_size = 20; $pages = max(1, (int) ceil(count($filtered) / $page_size));
$page = max(1, min($pages, (int) $input('pg', '1')));
$visible = $is_list ? array_slice($filtered, ($page - 1) * $page_size, $page_size) : array_slice($filtered, 0, 5);
$base = home_url('/dashboard/'); $list_url = home_url('/dashboard/transactions/');
$stale = !empty($status['error']) || (isset($snapshot['synced']) && time() - $snapshot['synced'] > max(120, $settings['interval'] * 120));
$title = ($is_list ? 'Transactions' : 'Closed Business Dashboard') . ' · ' . ($group['name'] ?? 'Chamber B2B');
// Standard hooks keep WordPress integrations functional, while this document omits theme templates.
remove_action('wp_head', '_wp_render_title_tag', 1);
remove_action('wp_head', '_block_template_render_title_tag', 1);
remove_action('wp_head', '_block_template_viewport_meta_tag', 0);
?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html($title); ?></title><meta name="description" content="Read-only Chamber B2B closed business totals and referral transactions."><?php wp_head(); ?></head>
<body class="cb2b-page"><a class="cb2b-skip" href="#main">Skip to content</a>
<header class="cb2b-header"><div class="cb2b-shell cb2b-header-inner"><a class="cb2b-brand" href="<?php echo esc_url($base); ?>">
<?php if ($settings['logo']): ?><img src="<?php echo esc_url($settings['logo']); ?>" alt="Dubuque Area Chamber of Commerce"><?php else: ?><span class="cb2b-monogram" aria-hidden="true">B2B</span><span>Dubuque Area<br><strong>Chamber of Commerce</strong></span><?php endif; ?></a>
<nav aria-label="Dashboard navigation"><a href="<?php echo esc_url(add_query_arg('report_year', $year, $base)); ?>" <?php if (!$is_list) { echo 'aria-current="page"'; } ?>>Overview</a><a href="<?php echo esc_url(add_query_arg('report_year', $year, $list_url)); ?>" <?php if ($is_list) { echo 'aria-current="page"'; } ?>>Transactions</a><a href="<?php echo esc_url(home_url('/dashboard/historical/')); ?>">Historical</a></nav></div></header>
<main id="main" class="cb2b-shell"><section class="cb2b-intro"><div><p class="cb2b-eyebrow">Connections that grow our community</p><h1><?php echo esc_html($group['name'] ?? 'Chamber B2B'); ?></h1><p class="cb2b-subtitle"><?php echo $is_list ? 'All closed business transactions' : 'Closed business dashboard'; ?> <span aria-hidden="true">/</span> <?php echo esc_html($year); ?></p></div>
<div class="cb2b-sync"><span class="cb2b-dot <?php echo $stale || !$records ? 'cb2b-dot-stale' : ''; ?>" aria-hidden="true"></span> Last updated<br><strong><?php echo isset($snapshot['synced']) ? esc_html(Plugin::stamp($snapshot['synced'])) : 'Awaiting first sync'; ?></strong></div></section>
<?php if (!$records): ?><div class="cb2b-notice" role="status">Live transactions are not available yet. An administrator needs to complete a successful Sheet sync in Settings → Chamber B2B. No sample transactions are shown.</div>
<?php elseif ($stale): ?><div class="cb2b-notice" role="status">Showing the last successful snapshot. The scheduled refresh is delayed or the source could not be reached. Totals may be out of date.</div><?php endif; ?>
<?php if (!$is_list): ?>
<form class="cb2b-year-form" method="get" action="<?php echo esc_url($base); ?>"><label for="report_year">Reporting year</label><select id="report_year" name="report_year"><?php foreach ($years as $option): ?><option <?php selected($year, $option); ?> value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option><?php endforeach; ?></select><button type="submit">Apply year</button></form>
<section class="cb2b-metrics" aria-label="<?php echo esc_attr($year); ?> headline metrics">
<?php foreach (['total' => 'Total Closed Business', 'count' => 'Total Transactions', 'largest' => 'Largest Single Transaction', 'average' => 'Average Transaction'] as $key => $label): ?>
<article class="cb2b-metric"><h2><?php echo esc_html($label); ?></h2><p><?php echo esc_html($key === 'count' ? number_format($metrics[$key]) : Data::money($metrics[$key])); ?></p><span><?php echo esc_html($year); ?> <?php echo $key === 'count' ? 'submitted transactions' : 'USD'; ?></span></article><?php endforeach; ?></section>
<section class="cb2b-panel"><div class="cb2b-section-heading"><div><h2>Business, month by month</h2><p>Monthly closed business totals and transaction counts · <?php echo esc_html($year); ?></p></div><span class="cb2b-pill">Jan – Dec</span></div>
<?php $month_labels = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']; ?>
<div class="cb2b-month-grid" aria-label="<?php echo esc_attr($year); ?> monthly closed business">
<?php foreach ($metrics['months'] as $month => $amount): $transaction_count = $metrics['month_counts'][$month]; ?>
<article class="cb2b-month-card"><h3><?php echo esc_html($month_labels[$month - 1]); ?></h3><p class="cb2b-month-value"><?php echo esc_html(Data::money($amount)); ?></p><p class="cb2b-month-count"><?php echo esc_html(number_format($transaction_count)); ?> <?php echo $transaction_count === 1 ? 'transaction' : 'transactions'; ?></p></article>
<?php endforeach; ?></div></section>
<?php else: ?>
<section class="cb2b-panel"><h2>Find a transaction</h2><p>All filters apply together within the selected reporting year. These list filters do not change the overview’s year totals.</p>
<form class="cb2b-filters" method="get" action="<?php echo esc_url($list_url); ?>">
<label>Reporting year<select name="report_year"><?php foreach ($years as $option): ?><option value="<?php echo esc_attr($option); ?>" <?php selected($year, $option); ?>><?php echo esc_html($option); ?></option><?php endforeach; ?></select></label>
<label class="cb2b-search">Search public fields<input type="search" name="search" maxlength="200" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Name, business, recipient or details"></label>
<label>From date<input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label><label>Through date<input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
<label>Submitting business<select name="business"><option value="">All businesses</option><?php $businesses = array_unique(array_column($year_records, 'business')); natcasesort($businesses); foreach ($businesses as $business): if ($business === '') { continue; } ?><option value="<?php echo esc_attr($business); ?>" <?php selected($filters['business'], $business); ?>><?php echo esc_html($business); ?></option><?php endforeach; ?></select></label>
<label>Sort by<select name="sort"><option value="date" <?php selected($filters['sort'], 'date'); ?>>Date</option><option value="amount" <?php selected($filters['sort'], 'amount'); ?>>Amount</option></select></label><label>Order<select name="order"><option value="desc" <?php selected($filters['order'], 'desc'); ?>>Highest / newest first</option><option value="asc" <?php selected($filters['order'], 'asc'); ?>>Lowest / oldest first</option></select></label>
<div class="cb2b-filter-actions"><button type="submit">Apply filters</button><a href="<?php echo esc_url(add_query_arg('report_year', $year, $list_url)); ?>">Clear filters</a></div></form>
<?php if ($filter_error): ?><p role="alert" class="cb2b-error"><?php echo esc_html($filter_error); ?></p><?php endif; ?></section>
<?php endif; ?>
<section class="cb2b-panel"><div class="cb2b-section-heading"><div><h2><?php echo $is_list ? 'Transactions' : 'Recent closed business'; ?></h2><p><?php echo $is_list ? esc_html(number_format(count($filtered)) . ' matching transactions · ' . $year) : 'The five most recent transactions for ' . esc_html($year); ?></p></div><?php if (!$is_list): ?><a class="cb2b-button" href="<?php echo esc_url(add_query_arg('report_year', $year, $list_url)); ?>">View All Transactions <span aria-hidden="true">→</span></a><?php endif; ?></div>
<?php if (!$visible): ?><p class="cb2b-empty"><?php echo $records ? 'No transactions match this reporting period or these filters.' : 'Transactions will appear after the first successful sync.'; ?></p><?php else: ?>
<div class="cb2b-table-scroll" tabindex="0" role="region" aria-label="Transactions table, horizontally scrollable on small screens"><table class="cb2b-transactions"><caption class="cb2b-sr-only"><?php echo $is_list ? 'Matching closed business transactions' : 'Five most recent closed business transactions'; ?></caption><thead><tr><?php foreach (['Date', 'Submitted By', 'Submitting Business', 'Recipient', 'Amount', 'Details'] as $header): ?><th scope="col"><?php echo esc_html($header); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($visible as $r): ?><tr><td><time datetime="<?php echo esc_attr($r['date']); ?>"><?php echo esc_html((new DateTimeImmutable($r['date'], Data::timezone()))->format('M j, Y')); ?></time></td><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($r['business']); ?></td><td><?php echo esc_html($r['recipient']); ?></td><td class="cb2b-amount"><?php echo esc_html(Data::money($r['cents'])); ?></td><td class="cb2b-notes"><?php if (mb_strlen($r['details']) > 160): ?><details><summary><?php echo esc_html(mb_substr($r['details'], 0, 120) . '…'); ?><span class="cb2b-read-more">Read full note</span></summary><p><?php echo nl2br(esc_html($r['details'])); ?></p></details><?php else: ?><?php echo $r['details'] === '' ? '—' : nl2br(esc_html($r['details'])); ?><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if ($is_list && $pages > 1): $query = ['report_year' => $year, 'search' => $filters['search'], 'from' => $filters['from'], 'to' => $filters['to'], 'business' => $filters['business'], 'sort' => $filters['sort'], 'order' => $filters['order']]; ?>
<nav class="cb2b-pagination" aria-label="Transaction pages"><?php if ($page > 1): ?><a class="cb2b-button" href="<?php echo esc_url(add_query_arg($query + ['pg' => $page - 1], $list_url)); ?>">← Previous</a><?php endif; ?><span>Page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?></span><?php if ($page < $pages): ?><a class="cb2b-button" href="<?php echo esc_url(add_query_arg($query + ['pg' => $page + 1], $list_url)); ?>">Next →</a><?php endif; ?></nav><?php endif; ?></section>
</main><footer class="cb2b-shell cb2b-footer"><p>Stronger connections. Shared success.</p><p>Dubuque Area Chamber of Commerce · B2B reporting · USD · Central time</p></footer><?php wp_footer(); ?></body></html>
