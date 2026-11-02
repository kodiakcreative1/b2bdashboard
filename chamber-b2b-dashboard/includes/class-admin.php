<?php
namespace CB2B;
if (!defined('ABSPATH')) { exit; }

final class Admin {
    public static function menu(): void { add_options_page('Chamber B2B Dashboard', 'Chamber B2B', 'manage_options', 'chamber-b2b', [self::class, 'page']); }
    public static function assets(string $hook): void {
        if ($hook !== 'settings_page_chamber-b2b' || !current_user_can('manage_options')) { return; }
        wp_enqueue_media();
        wp_enqueue_script('cb2b-admin', CB2B_URL . 'assets/admin.js', ['jquery', 'media-views'], CB2B_VERSION, true);
    }
    private static function authorize(string $action): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
        check_admin_referer($action);
    }
    private static function redirect(string $message, bool $error = false): void {
        set_transient('cb2b_notice_' . get_current_user_id(), ['message' => $message, 'error' => $error], 120);
        wp_safe_redirect(admin_url('options-general.php?page=chamber-b2b')); exit;
    }
    public static function save(): void {
        self::authorize('cb2b_save');
        $input = wp_unslash($_POST);
        $sheet = trim(sanitize_text_field($input['spreadsheet'] ?? ''));
        if (preg_match('~^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)(?:/[^\s]*)?$~D', $sheet, $m)) { $sheet = $m[1]; }
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $sheet)) { self::redirect('Enter a valid Google Sheets URL or spreadsheet ID.', true); }
        $groups = []; $ids = [];
        foreach (array_slice((array) ($input['groups'] ?? []), 0, 25) as $g) {
            if (!is_array($g)) { continue; }
            $id = sanitize_key($g['id'] ?? ''); $name = sanitize_text_field($g['name'] ?? ''); $tab = sanitize_text_field($g['tab'] ?? ''); $gid = trim((string) ($g['gid'] ?? ''));
            if (!$id && !$name && !$tab && $gid === '') { continue; }
            if (!$id || !$name || !$tab || !preg_match('/^\d{1,15}$/D', $gid) || isset($ids[$id])) { self::redirect('Each group needs a unique key, display name, exact tab name and numeric gid.', true); }
            $ids[$id] = true;
            $groups[] = ['id' => $id, 'name' => $name, 'tab' => $tab, 'gid' => $gid, 'enabled' => !empty($g['enabled'])];
        }
        $active = sanitize_key($input['active'] ?? ''); $found = false;
        foreach ($groups as $g) { if ($g['id'] === $active && $g['enabled']) { $found = true; } }
        if (!$found) { self::redirect('The active group must match an enabled group key.', true); }
        $interval = (int) ($input['interval'] ?? 1);
        if (!in_array($interval, [1, 15, 30], true)) { $interval = 1; }
        $logo = esc_url_raw($input['logo'] ?? '', ['https']);
        if (!empty($input['logo']) && !$logo) { self::redirect('Use an HTTPS logo URL or choose an image from the media library.', true); }
        $defaults = Plugin::defaults();
        update_option(Plugin::OPTION, ['spreadsheet' => $sheet, 'groups' => $groups, 'active' => $active, 'interval' => $interval, 'logo' => $logo, 'primary' => sanitize_hex_color($input['primary'] ?? '') ?: $defaults['primary'], 'accent' => sanitize_hex_color($input['accent'] ?? '') ?: $defaults['accent']], false);
        Plugin::schedule();
        self::redirect('Settings saved. Click Refresh Now to fetch the selected source.');
    }
    public static function refresh(): void {
        self::authorize('cb2b_refresh');
        $status = Plugin::sync();
        self::redirect(empty($status['error']) ? 'Refresh complete. Valid public records cached; see diagnostics below.' : $status['error'], !empty($status['error']));
    }
    public static function group_row(array $g, $i): void { ?>
        <tr>
        <?php foreach (['id' => 'Group key', 'name' => 'Display name', 'tab' => 'Exact sheet tab / B2B Group', 'gid' => 'Numeric gid'] as $key => $label): ?>
            <td><input aria-label="<?php echo esc_attr($label); ?>" name="groups[<?php echo esc_attr($i); ?>][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($g[$key] ?? ''); ?>" <?php echo $key === 'gid' ? 'inputmode="numeric"' : ''; ?>></td>
        <?php endforeach; ?>
        <td><label><input type="checkbox" name="groups[<?php echo esc_attr($i); ?>][enabled]" value="1" <?php checked(!empty($g['enabled'])); ?>> Enabled</label></td>
        <td><button type="button" class="button cb2b-remove">Remove</button></td></tr>
    <?php }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $s = Plugin::settings(); $snapshot = Plugin::snapshot(); $status = Plugin::status();
        $notice = get_transient('cb2b_notice_' . get_current_user_id()); delete_transient('cb2b_notice_' . get_current_user_id()); ?>
        <div class="wrap"><h1>Chamber B2B Dashboard</h1>
        <?php if ($notice): ?><div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>
        <p>Read-only reports. Saving here never edits Google Sheets, Gravity Forms, Zapier or the theme. Only the enabled active group is public.</p>
        <p><a href="<?php echo esc_url(home_url('/dashboard/')); ?>">Open dashboard</a> · <a href="<?php echo esc_url(home_url('/dashboard/transactions/')); ?>">Open transactions</a></p>
        <?php if (get_option('permalink_structure') === ''): ?><div class="notice notice-warning"><p>Pretty permalinks are required for /dashboard/ routes. Select a non-Plain structure in Settings → Permalinks.</p></div><?php endif; ?>
        <?php if (get_page_by_path('dashboard')): ?><div class="notice notice-warning"><p>An existing dashboard page shares this path. This plugin controls /dashboard/ while active; deactivation restores the normal page route.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cb2b_save"><?php wp_nonce_field('cb2b_save'); ?>
        <table class="form-table"><tr><th><label for="spreadsheet">Google spreadsheet URL / ID</label></th><td><input class="large-text" id="spreadsheet" name="spreadsheet" value="<?php echo esc_attr($s['spreadsheet']); ?>" required><p class="description">Public read access is required. Fetches happen on this WordPress server; no API key is needed.</p></td></tr></table>
        <h2>Group sources</h2><p>The exact tab name must match the B2B Group values in that tab. Current known tabs: Monday (0), Tuesday (395825866), Thursday (125109628). Wednesday is a separate future group; do not reuse Tuesday’s gid. Inactive sources are never fetched or offered publicly.</p>
        <div style="overflow-x:auto"><table class="widefat"><thead><tr><th>Group key</th><th>Display name</th><th>Exact tab / group value</th><th>gid</th><th>Availability</th><th>Action</th></tr></thead><tbody id="cb2b-groups"><?php foreach ($s['groups'] as $i => $g) { self::group_row($g, $i); } ?></tbody></table></div>
        <template id="cb2b-group-template"><?php self::group_row([], '__INDEX__'); ?></template>
        <p><button type="button" class="button" id="cb2b-add">Add group</button></p>
        <table class="form-table">
        <tr><th><label for="active">Active group key</label></th><td><input id="active" name="active" value="<?php echo esc_attr($s['active']); ?>" required><p class="description">Enter the unique key of one enabled group above, e.g. monday.</p></td></tr>
        <tr><th><label for="interval">Refresh frequency</label></th><td><select id="interval" name="interval"><?php foreach ([1, 15, 30] as $minutes): ?><option value="<?php echo (int) $minutes; ?>" <?php selected($s['interval'], $minutes); ?>><?php echo (int) $minutes; ?> minute(s)</option><?php endforeach; ?></select><p class="description">WP-Cron depends on traffic. For reliable timing, have your host run WordPress due cron events every minute.</p></td></tr>
        <tr><th><label for="cb2b-logo">Chamber logo</label></th><td><input class="large-text" id="cb2b-logo" name="logo" type="url" value="<?php echo esc_attr($s['logo']); ?>"><button class="button" id="cb2b-media" type="button">Choose image</button><p class="description">Choose a verified Chamber logo. No official logo is bundled; blank uses a text wordmark.</p></td></tr>
        <?php foreach (['primary' => 'Header color', 'accent' => 'Accent color'] as $key => $label): ?><tr><th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" type="color" value="<?php echo esc_attr($s[$key]); ?>"><p class="description">Keep strong contrast against white. Defaults are provisional, not verified official brand colors.</p></td></tr><?php endforeach; ?>
        </table><?php submit_button('Save settings'); ?></form>
        <h2>Sync diagnostics</h2>
        <dl><dt>Source status</dt><dd><?php echo esc_html($status['error'] ?? '') ?: (empty($snapshot) ? 'Not synced yet.' : 'Last attempt succeeded.'); ?></dd>
        <dt>Last attempted sync</dt><dd><?php echo isset($status['attempted']) ? esc_html(Plugin::stamp($status['attempted'])) : 'Never'; ?></dd>
        <dt>Last successful sync</dt><dd><?php echo isset($snapshot['synced']) ? esc_html(Plugin::stamp($snapshot['synced'])) : 'Never'; ?></dd>
        <dt>Cached valid records (all years)</dt><dd><?php echo count($snapshot['records'] ?? []); ?></dd>
        <dt>Invalid / excluded rows in last successful parse</dt><dd><?php echo (int) ($status['invalid_rows'] ?? 0); ?></dd></dl>
        <?php if (!empty($status['issues'])): ?><ul><?php foreach ($status['issues'] as $issue): ?><li><?php echo esc_html($issue); ?></li><?php endforeach; ?></ul><p>At most 100 row diagnostics are displayed. Row numbers refer to CSV records, including the header.</p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="cb2b_refresh"><?php wp_nonce_field('cb2b_refresh'); submit_button('Refresh Now', 'secondary'); ?></form>
        <p>A failed or empty download retains the last successful snapshot. Changing spreadsheet, tab or gid uses a separate cache. Deactivation stops scheduled refreshes and retains settings/data for safe reactivation.</p>
        </div>
    <?php }
}
