<?php
// Ephemeral local QA installer. Do not put in the plugin package.
define('WP_INSTALLING', true);
$qa_url = 'http://localhost:' . (getenv('CB2B_QA_PORT') ?: '8088');
$_SERVER['HTTP_HOST'] = parse_url($qa_url, PHP_URL_HOST) . ':' . parse_url($qa_url, PHP_URL_PORT);
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_blog_installed()) {
    wp_install('B2B isolated QA', 'qa-admin', 'qa@example.invalid', true, '', 'Local-Only-QA-Password-2026!');
}
update_option('home', $qa_url);
update_option('siteurl', $qa_url);
update_option('permalink_structure', '/%postname%/');
update_option('timezone_string', 'America/Chicago');
if (getenv('CB2B_QA_SKIP_PLUGIN_ACTIVATE')) {
    flush_rewrite_rules();
    echo 'Fresh ZIP QA WordPress ' . get_bloginfo('version') . ' / PHP ' . PHP_VERSION . " ready.\n";
    return;
}
$result = activate_plugin('chamber-b2b-dashboard/chamber-b2b-dashboard.php');
if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message()); exit(1); }
require_once ABSPATH . 'wp-content/plugins/chamber-b2b-dashboard/chamber-b2b-dashboard.php';
CB2B\Plugin::routes();
flush_rewrite_rules();
echo 'Installed WordPress ' . get_bloginfo('version') . ' with PHP ' . PHP_VERSION . "; plugin active.\n";
