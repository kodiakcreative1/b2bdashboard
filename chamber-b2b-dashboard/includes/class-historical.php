<?php
namespace CB2B;
if (!defined('ABSPATH')) { exit; }

/** Read-only historical summaries. Totals represent business through 2025. */
final class Historical {
    public const GID = '884257498';
    public const HEADERS = ['B2B Group', 'Starting Year', 'Through 2024 Total', '2025 Total', 'Grand Total'];
    public static function key(): string {
        return 'cb2b_historical_' . substr(hash('sha256', Plugin::settings()['spreadsheet'] . '|' . self::GID), 0, 24);
    }
    public static function snapshot(): array { return (array) get_option(self::key(), []); }
    public static function status(): array { return (array) get_option(self::key() . '_status', []); }
    public static function parse(string $csv): array {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (trim($csv) === '' || preg_match('/^\s*(?:<!doctype|<html|<head|<body|<\?xml)/i', $csv)) {
            throw new \RuntimeException('Historical source is empty or returned HTML.');
        }
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $csv); rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if (!$headers) { fclose($stream); throw new \RuntimeException('Historical headers missing.'); }
        $headers = array_map(static fn($h) => trim((string) $h), $headers);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $map = array_flip($headers);
        foreach (self::HEADERS as $header) {
            if (!isset($map[$header]) || count(array_keys($headers, $header, true)) !== 1) {
                fclose($stream); throw new \RuntimeException('Historical header missing or duplicated: ' . $header);
            }
        }
        $rows = []; $issues = []; $line = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if (!array_filter($values, static fn($v) => trim((string) $v) !== '')) { continue; }
            if (count($values) !== count($headers)) { $issues[] = "Row $line: column count mismatch."; continue; }
            $get = static fn($key) => trim((string) ($values[$map[$key]] ?? ''));
            $group = $get('B2B Group'); $start = $get('Starting Year');
            $before = Data::cents($get('Through 2024 Total'));
            $in2025 = Data::cents($get('2025 Total'));
            $grand = Data::cents($get('Grand Total'));
            if ($group === '' || !preg_match('/^(19|20)\d{2}$/D', $start) || (int) $start > 2024 || $before === null || $in2025 === null || $grand === null) {
                $issues[] = "Row $line: invalid group, starting year or USD amount."; continue;
            }
            $group_key = self::normalize_group($group);
            if ($group_key === '') { $issues[] = "Row $line: invalid group name."; continue; }
            if (isset($rows[$group_key])) { $issues[] = "Row $line: duplicate group."; continue; }
            if (($in2025 > 0 && $before > PHP_INT_MAX - $in2025) || ($in2025 < 0 && $before < -PHP_INT_MAX - $in2025) || $before + $in2025 !== $grand) {
                $issues[] = "Row $line: historical subtotals do not equal grand total."; continue;
            }
            $rows[$group_key] = ['group' => sanitize_text_field($group), 'start' => (int) $start, 'through2024' => $before, 'year2025' => $in2025, 'grand' => $grand];
        }
        fclose($stream);
        if ($issues) { throw new \RuntimeException('Historical CSV validation failed: ' . implode(' ', array_slice($issues, 0, 20)) . ' Previous historical snapshot retained.'); }
        if (!$rows) { throw new \RuntimeException('No valid historical rows. Previous historical data retained.'); }
        return ['rows' => $rows, 'issues' => $issues];
    }
    public static function normalize_group(string $name): string {
        return strtolower(trim(preg_replace('/\s+/', ' ', sanitize_text_field($name))));
    }
    public static function for_active(): ?array {
        $group = Plugin::active(); if (!$group) { return null; }
        $rows = self::snapshot()['rows'] ?? [];
        $tab = self::normalize_group((string) ($group['tab'] ?? ''));
        if ($tab !== '') { return $rows[$tab] ?? null; }
        foreach ([$group['tab'] ?? '', $group['name'] ?? '', $group['id'] ?? ''] as $candidate) {
            $candidate = self::normalize_group((string) $candidate);
            if (isset($rows[$candidate])) { return $rows[$candidate]; }
        }
        return null;
    }
    public static function sync(): array {
        $key = self::key(); $lock = $key . '_lock'; $old = get_option($lock);
        if ($old && (int) explode(':', (string) $old)[0] < time() - 300) { Plugin::release($lock, (string) $old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { return ['error' => 'A historical refresh is already in progress.']; }
        $status = array_replace(self::status(), ['attempted' => time(), 'error' => '']);
        try {
            $sheet = Plugin::settings()['spreadsheet'];
            $base = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($sheet);
            // Google may redirect exports to a host blocked by the site's outbound policy.
            // The documented query endpoint serves the same read-only tab on docs.google.com.
            $urls = [$base . '/export?format=csv&gid=' . self::GID,
                     $base . '/gviz/tq?tqx=out:csv&gid=' . self::GID];
            $body = null; $error = 'Unable to reach the historical Google Sheet.';
            foreach ($urls as $url) {
                $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 1024 * 1024, 'headers' => ['Accept' => 'text/csv']]);
                if (is_wp_error($response)) { continue; }
                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) { $error = 'Historical Google Sheet returned HTTP ' . (int) $code . '. Check public read access.'; continue; }
                $candidate = wp_remote_retrieve_body($response);
                if (strlen($candidate) >= 1024 * 1024) { throw new \RuntimeException('Historical CSV exceeds 1 MB safety limit.'); }
                if (trim($candidate) === '' || preg_match('/^\s*(?:<!doctype|<html|<head|<body|<\?xml)/i', $candidate)) {
                    $error = 'Historical source is empty or returned HTML. Check public read access.'; continue;
                }
                $body = $candidate; break;
            }
            if ($body === null) { throw new \RuntimeException($error); }
            $parsed = self::parse($body);
            $previous = self::snapshot()['rows'] ?? [];
            if (array_diff_key($previous, $parsed['rows'])) {
                throw new \RuntimeException('Historical import is missing previously cached groups. Previous historical snapshot retained.');
            }
            update_option($key, ['rows' => $parsed['rows'], 'synced' => time()], false);
            $status['issues'] = $parsed['issues'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        finally { update_option($key . '_status', $status, false); Plugin::release($lock, $token); }
        return $status;
    }
}
