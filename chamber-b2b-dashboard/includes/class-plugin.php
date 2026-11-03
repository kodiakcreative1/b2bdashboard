<?php
namespace CB2B;
if (!defined('ABSPATH')) { exit; }

final class Plugin {
    public const OPTION = 'cb2b_settings';
    public const CRON = 'cb2b_sync';
    public static function defaults(): array {
        return ['spreadsheet' => '19_wSAJv4c7Rw08pRR6wp58FsQnr4kw1UZ3faR-apKeY', 'groups' => [['id' => 'monday', 'name' => 'Monday B2B Group', 'tab' => 'Monday', 'gid' => '0', 'enabled' => true]], 'active' => 'monday', 'interval' => 1, 'logo' => '', 'primary' => '#153c55', 'accent' => '#007f82'];
    }
    public static function settings(): array { return array_replace(self::defaults(), (array) get_option(self::OPTION, [])); }
    public static function active(): ?array {
        $s = self::settings();
        foreach ($s['groups'] as $group) { if ($group['id'] === $s['active'] && !empty($group['enabled'])) { return $group; } }
        return null;
    }
    public static function key(): string {
        $s = self::settings(); $g = self::active();
        return 'cb2b_data_' . substr(hash('sha256', $s['spreadsheet'] . '|' . ($g['id'] ?? '') . '|' . ($g['gid'] ?? '') . '|' . ($g['tab'] ?? '')), 0, 24);
    }
    public static function boot(): void {
        add_action('init', [self::class, 'routes']);
        add_filter('query_vars', static function ($vars) { $vars[] = 'cb2b_page'; return $vars; });
        add_filter('template_include', [self::class, 'template'], 99);
        add_filter('cron_schedules', static function ($schedules) {
            foreach ([1, 15, 30] as $minutes) { $schedules['cb2b_' . $minutes] = ['interval' => $minutes * 60, 'display' => "B2B every $minutes minute(s)"]; }
            return $schedules;
        });
        add_action(self::CRON, [self::class, 'sync']);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_enqueue_scripts', [Admin::class, 'assets']);
        add_action('admin_post_cb2b_save', [Admin::class, 'save']);
        add_action('admin_post_cb2b_refresh', [Admin::class, 'refresh']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_filter('redirect_canonical', static fn($url) => get_query_var('cb2b_page') ? false : $url);
    }
    public static function routes(): void {
        add_rewrite_rule('^dashboard/historical/?, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/historical/?, $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        Historical::sync();
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
, 'index.php?cb2b_page=historical', 'top');
        add_rewrite_rule('^dashboard/transactions/?, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/transactions/?$'], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/transactions/?$'], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
], $wp_rewrite->{$property}['^dashboard/transactions/?, $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
, 'index.php?cb2b_page=historical', 'top');
        add_rewrite_rule('^dashboard/transactions/?, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/transactions/?$'], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/transactions/?$'], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
, 'index.php?cb2b_page=historical', 'top');
        add_rewrite_rule('^dashboard/transactions/?, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/transactions/?$'], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
, 'index.php?cb2b_page=transactions', 'top');
        add_rewrite_rule('^dashboard/?$', 'index.php?cb2b_page=dashboard', 'top');
    }
    public static function activate(): void {
        if (PHP_INT_SIZE < 8) { wp_die('Chamber B2B requires 64-bit PHP for safe USD totals.'); }
        if (!get_option(self::OPTION)) { add_option(self::OPTION, self::defaults(), '', false); }
        self::routes(); self::schedule(); flush_rewrite_rules();
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        global $wp_rewrite;
        // Remove our in-memory rules before flushing; otherwise deactivation persists them again.
        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            unset($wp_rewrite->{$property}['^dashboard/transactions/?$'], $wp_rewrite->{$property}['^dashboard/?$']);
        }
        flush_rewrite_rules();
    }
    public static function schedule(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 5, 'cb2b_' . self::settings()['interval'], self::CRON);
    }
    public static function template(string $template): string {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return $template; }
        global $wp_query;
        $wp_query->is_404 = false; $wp_query->is_home = false;
        status_header(200);
        // Exclude plugin output from shared page caches: status and filters must remain current.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        return CB2B_DIR . 'templates/dashboard.php';
    }
    public static function assets(): void {
        if (!in_array(get_query_var('cb2b_page'), ['dashboard', 'transactions', 'historical'], true)) { return; }
        wp_enqueue_style('cb2b-dashboard', CB2B_URL . 'assets/dashboard.css', [], CB2B_VERSION);
        $s = self::settings();
        wp_add_inline_style('cb2b-dashboard', ':root{--cb-primary:' . sanitize_hex_color($s['primary']) . ';--cb-accent:' . sanitize_hex_color($s['accent']) . ';}');
        wp_enqueue_script('cb2b-dashboard', CB2B_URL . 'assets/dashboard.js', [], CB2B_VERSION, true);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function stamp(int $timestamp): string { return wp_date('M j, Y g:i a T', $timestamp, Data::timezone()); }
    /** Atomic option lock, including compare-and-delete when recovering an expired lock. */
    public static function release(string $key, string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
    public static function sync(): array {
        $group = self::active();
        if (!$group) { return ['error' => 'Select an enabled active group in settings.']; }
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 120) { self::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A refresh is already in progress. Try again shortly.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $s = self::settings();
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet']) . '/export?format=csv&gid=' . rawurlencode($group['gid']);
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 5 * 1024 * 1024, 'headers' => ['Accept' => 'text/csv'], 'user-agent' => 'ChamberB2B/' . CB2B_VERSION]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach Google Sheets. Check outbound HTTPS access, DNS and TLS trust on this WordPress server.'); }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) { throw new \RuntimeException('Google Sheets returned HTTP ' . (int) $code . '. Check public read access, spreadsheet ID and gid.'); }
            $csv = wp_remote_retrieve_body($response);
            if (strlen($csv) >= 5 * 1024 * 1024) { throw new \RuntimeException('CSV reached the 5 MB safety limit; snapshot retained. Contact the plugin maintainer.'); }
            $parsed = Data::parse($csv, $group['tab']);
            $snapshot = ['records' => $parsed['records'], 'synced' => time()];
            update_option($key, $snapshot, false);
            $status['issues'] = array_slice($parsed['issues'], 0, 100);
            $status['invalid_rows'] = count($parsed['issues']); $status['blank_rows'] = $parsed['blank_rows'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); self::release($lock, $token); }
        return $status;
    }
}
