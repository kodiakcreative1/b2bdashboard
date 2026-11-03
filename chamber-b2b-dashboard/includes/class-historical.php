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
        if (trim($csv) === '' || preg_match('/^\s*(?:<!doctype|<html|<head|<body)/i', $csv)) {
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
            if (isset($rows[strtolower($group)])) { $issues[] = "Row $line: duplicate group."; continue; }
            if ($before > PHP_INT_MAX - $in2025 || $before + $in2025 !== $grand) {
                $issues[] = "Row $line: historical subtotals do not equal grand total."; continue;
            }
            $rows[strtolower($group)] = ['group' => sanitize_text_field($group), 'start' => (int) $start, 'through2024' => $before, 'year2025' => $in2025, 'grand' => $grand];
        }
        fclose($stream);
        if (!$rows) { throw new \RuntimeException('No valid historical rows. Previous historical data retained.'); }
        return ['rows' => $rows, 'issues' => $issues];
    }
    public static function for_active(): ?array {
        $group = Plugin::active(); if (!$group) { return null; }
        $rows = self::snapshot()['rows'] ?? [];
        foreach ([$group['tab'] ?? '', $group['name'] ?? '', $group['id'] ?? ''] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if (isset($rows[$candidate])) { return $rows[$candidate]; }
        }
        return null;
    }
    public static function sync(): array {
        $key = self::key(); $status = ['attempted' => time(), 'error' => ''];
        try {
            $sheet = Plugin::settings()['spreadsheet'];
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($sheet) . '/export?format=csv&gid=' . self::GID;
            $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'limit_response_size' => 1024 * 1024, 'headers' => ['Accept' => 'text/csv']]);
            if (is_wp_error($response)) { throw new \RuntimeException('Unable to reach the historical Google Sheet.'); }
            if (wp_remote_retrieve_response_code($response) !== 200) { throw new \RuntimeException('Historical Google Sheet returned HTTP ' . (int) wp_remote_retrieve_response_code($response)); }
            $body = wp_remote_retrieve_body($response);
            if (strlen($body) >= 1024 * 1024) { throw new \RuntimeException('Historical CSV exceeds 1 MB safety limit.'); }
            $parsed = self::parse($body);
            update_option($key, ['rows' => $parsed['rows'], 'synced' => time()], false);
            $status['issues'] = $parsed['issues'];
        } catch (\Throwable $e) { $status['error'] = $e->getMessage(); }
        update_option($key . '_status', $status, false);
        return $status;
    }
}
