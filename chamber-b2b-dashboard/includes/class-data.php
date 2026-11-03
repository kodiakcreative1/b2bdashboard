<?php
namespace CB2B;
if (!defined('ABSPATH')) { exit; }

final class Data {
    public const HEADERS = ['Date', 'Submitted by Name', 'Submitted by Business', 'Submitted by Email', 'B2B Group', 'Recipient of Closed Business', 'Value of Closed Business', 'Details'];
    public static function timezone(): \DateTimeZone { return new \DateTimeZone('America/Chicago'); }
    public static function date(string $value): ?string {
        $value = trim($value);
        foreach (['!n/j/Y', '!n/j/y', '!Y-m-d', '!n/j/Y G:i:s', '!n/j/Y g:i:s A', '!n/j/Y G:i', '!F j, Y', '!M j, Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, self::timezone());
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                $year = (int) $date->format('Y');
                if ($year >= 1900 && $year <= 2199) { return $date->format('Y-m-d'); }
            }
        }
        return null;
    }
    public static function cents(string $value): ?int {
        $value = trim(str_replace(["\xc2\xa0", '−'], [' ', '-'], $value));
        if (!preg_match('/^(?<sign>-)?\$?(?<whole>\d{1,3}(?:,\d{3})+|\d+)(?:\.(?<fraction>\d{1,2}))?$/D', $value, $m)) { return null; }
        $whole = str_replace(',', '', $m['whole']);
        $digits = ltrim($whole . str_pad($m['fraction'] ?? '', 2, '0'), '0') ?: '0';
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) { return null; }
        $cents = (int) $digits;
        return empty($m['sign']) ? $cents : -$cents;
    }
    /** The private Email column is never copied into the canonical/public model. */
    public static function parse(string $csv, string $group): array {
        if (trim($csv) === '' || preg_match('/^\s*(?:<!doctype|<html|<head|<body|<\?xml)/i', $csv)) {
            throw new \RuntimeException('Source returned an empty or HTML/login response. Check public read access and the group gid.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv); rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if (!$headers) { fclose($stream); throw new \RuntimeException('CSV header row is missing.'); }
        $headers = array_map(static fn($s) => trim((string) $s), $headers);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $map = array_flip($headers);
        foreach (self::HEADERS as $header) {
            if (!isset($map[$header]) || count(array_keys($headers, $header, true)) !== 1) {
                fclose($stream); throw new \RuntimeException('CSV schema mismatch: missing or duplicated header “' . $header . '”. Restore the expected column headers.');
            }
        }
        $records = []; $issues = []; $index = 1; $blank = 0; $absolute_sum = 0;
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $index++;
            if (!array_filter($row, static fn($v) => trim((string) $v) !== '')) { $blank++; continue; }
            if (count($row) !== count($headers)) { $issues[] = "Row $index: column count does not match the header."; continue; }
            $get = static fn($key) => trim((string) ($row[$map[$key]] ?? ''));
            $date = self::date($get('Date')); $cents = self::cents($get('Value of Closed Business'));
            if (!$date || $cents === null) { $issues[] = "Row $index: invalid date or USD amount; excluded from totals."; continue; }
            if (strcasecmp($get('B2B Group'), trim($group)) !== 0) { $issues[] = "Row $index: group does not match the configured tab name; excluded."; continue; }
            if ($absolute_sum > PHP_INT_MAX - abs($cents)) { fclose($stream); throw new \RuntimeException('Total exceeds the supported integer range.'); }
            $absolute_sum += abs($cents);
            $records[] = ['date' => $date, 'name' => sanitize_text_field($get('Submitted by Name')), 'business' => sanitize_text_field($get('Submitted by Business')), 'recipient' => sanitize_text_field($get('Recipient of Closed Business')), 'cents' => $cents, 'details' => sanitize_textarea_field($get('Details')), 'row' => $index];
        }
        fclose($stream);
        if (!$records) { throw new \RuntimeException('No valid transactions found. Previous data retained; check the selected tab and validation of dates, amounts and group names.'); }
        usort($records, static fn($a, $b) => strcmp($b['date'], $a['date']) ?: ($b['row'] <=> $a['row']));
        return ['records' => $records, 'issues' => $issues, 'blank_rows' => $blank];
    }
    public static function metrics(array $records): array {
        $sum = 0; $largest = null; $months = array_fill(1, 12, 0); $month_counts = array_fill(1, 12, 0);
        foreach ($records as $r) { $sum += $r['cents']; $largest = $largest === null ? $r['cents'] : max($largest, $r['cents']); $month = (int) substr($r['date'], 5, 2); $months[$month] += $r['cents']; $month_counts[$month]++; }
        $count = count($records);
        // Exact round-half-away-from-zero, without floating point division.
        $average = $count ? (intdiv(abs($sum), $count) + ((abs($sum) % $count) * 2 >= $count ? 1 : 0)) * ($sum < 0 ? -1 : 1) : 0;
        return ['total' => $sum, 'count' => $count, 'largest' => $largest ?? 0, 'average' => $average, 'months' => $months, 'month_counts' => $month_counts];
    }
    public static function money(int $cents): string { return ($cents < 0 ? '-$' : '$') . number_format(intdiv(abs($cents), 100)) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT); }
    public static function filter(array $records, array $filters): array {
        $records = array_values(array_filter($records, static function ($r) use ($filters) {
            if (substr($r['date'], 0, 4) !== (string) $filters['year']) { return false; }
            if (!empty($filters['from']) && $r['date'] < $filters['from']) { return false; }
            if (!empty($filters['to']) && $r['date'] > $filters['to']) { return false; }
            if (!empty($filters['business']) && $r['business'] !== $filters['business']) { return false; }
            $haystack = implode(' ', [$r['date'], $r['name'], $r['business'], $r['recipient'], $r['details'], self::money($r['cents'])]);
            return empty($filters['search']) || stripos($haystack, $filters['search']) !== false;
        }));
        $sort = $filters['sort'] ?? 'date'; $direction = ($filters['order'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($records, static function ($a, $b) use ($sort, $direction) {
            $cmp = $sort === 'amount' ? $a['cents'] <=> $b['cents'] : strcmp($a['date'], $b['date']);
            return ($cmp * $direction) ?: ($b['row'] <=> $a['row']);
        });
        return $records;
    }
}
