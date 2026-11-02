<?php
/**
 * Plugin Name: Chamber B2B Dashboard
 * Description: Standalone, read-only Chamber B2B closed business reporting from Google Sheets.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: Chamber B2B
 * License: GPL-2.0-or-later
 * Text Domain: chamber-b2b-dashboard
 */
if (!defined('ABSPATH')) { exit; }
define('CB2B_VERSION', '0.1.0');
define('CB2B_DIR', plugin_dir_path(__FILE__));
define('CB2B_URL', plugin_dir_url(__FILE__));
require_once CB2B_DIR . 'includes/class-data.php';
require_once CB2B_DIR . 'includes/class-plugin.php';
require_once CB2B_DIR . 'includes/class-admin.php';
CB2B\Plugin::boot();
register_activation_hook(__FILE__, ['CB2B\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['CB2B\\Plugin', 'deactivate']);
