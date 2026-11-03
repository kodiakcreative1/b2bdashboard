<?php
if (!defined('ABSPATH')) { exit; }
use CB2B\Plugin;
use CB2B\Data;
use CB2B\Historical;
$group = Plugin::active();
$settings = Plugin::settings();
$row = Historical::for_active();
$snapshot = Historical::snapshot();
$status = Historical::status();
$stale = !empty($status['error']) || (isset($snapshot['synced']) && time() - $snapshot['synced'] > max(120, $settings['interval'] * 120));
remove_action('wp_head', '_wp_render_title_tag', 1);
remove_action('wp_head', '_block_template_render_title_tag', 1);
remove_action('wp_head', '_block_template_viewport_meta_tag', 0);
?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Historical Chamber B2B closed business summaries through 2025, separate from current transactions."><title>Historical Closed Business · <?php echo esc_html($group['name'] ?? 'Chamber B2B'); ?></title><?php wp_head(); ?></head>
<body class="cb2b-page"><a class="cb2b-skip" href="#main">Skip to content</a>
<header class="cb2b-header"><div class="cb2b-shell cb2b-header-inner"><a class="cb2b-brand" href="<?php echo esc_url(home_url('/dashboard/')); ?>"><?php if ($settings['logo']): ?><img src="<?php echo esc_url($settings['logo']); ?>" alt="Dubuque Area Chamber of Commerce"><?php else: ?><span class="cb2b-monogram" aria-hidden="true">B2B</span><span>Dubuque Area<br><strong>Chamber of Commerce</strong></span><?php endif; ?></a>
<nav aria-label="Dashboard navigation"><a href="<?php echo esc_url(home_url('/dashboard/')); ?>">Overview</a><a href="<?php echo esc_url(home_url('/dashboard/transactions/')); ?>">Transactions</a><a href="<?php echo esc_url(home_url('/dashboard/historical/')); ?>" aria-current="page">Historical</a></nav></div></header>
<main class="cb2b-shell" id="main"><section class="cb2b-intro"><div><p class="cb2b-eyebrow">Connections that grow our community</p><h1><?php echo esc_html($group['name'] ?? 'Chamber B2B'); ?></h1><p class="cb2b-subtitle">Historical closed business / through 2025</p></div><div class="cb2b-sync">Historical data updated<br><strong><?php echo isset($snapshot['synced']) ? esc_html(Plugin::stamp($snapshot['synced'])) : 'Awaiting first sync'; ?></strong></div></section>
<?php if (!$row): ?><div class="cb2b-notice" role="status">Historical figures are not available for this group yet. An administrator can refresh the Historical sheet in Settings → Chamber B2B.</div>
<?php else: ?>
<?php if ($stale): ?><div class="cb2b-notice" role="status">Showing the last successful historical snapshot. The scheduled refresh is delayed or the source could not be reached.</div><?php endif; ?>
<section class="cb2b-metrics" aria-label="Historical summary">
<?php foreach ([['Historical Closed Business', Data::money($row['grand']), 'Through December 2025'], ['Through 2024 Total', Data::money($row['through2024']), $row['start'] . '–2024 cumulative'], ['2025 Closed Business', Data::money($row['year2025']), 'Calendar year 2025'], ['Group Established', (string) $row['start'], 'Historical reporting start']] as $card): ?>
<article class="cb2b-metric"><h2><?php echo esc_html($card[0]); ?></h2><p><?php echo esc_html($card[1]); ?></p><span><?php echo esc_html($card[2]); ?></span></article>
<?php endforeach; ?></section>
<section class="cb2b-panel"><div class="cb2b-section-heading"><div><h2>Historical breakdown</h2><p>Summary figures, separate from current transaction records.</p></div></div><div class="cb2b-month-grid">
<?php foreach ([[$row['start'] . '–2024', $row['through2024'], 'Cumulative'], ['2025', $row['year2025'], 'Annual total'], ['Through 2025', $row['grand'], 'Grand total']] as $item): ?>
<article class="cb2b-month-card"><h3><?php echo esc_html($item[0]); ?></h3><p class="cb2b-month-value"><?php echo esc_html(Data::money($item[1])); ?></p><p class="cb2b-month-count"><?php echo esc_html($item[2]); ?></p></article>
<?php endforeach; ?></div></section>
<?php endif; ?></main><footer class="cb2b-shell cb2b-footer"><p>Stronger connections. Shared success.</p><p>Dubuque Area Chamber of Commerce · Historical reporting · USD</p></footer><?php wp_footer(); ?></body></html>
