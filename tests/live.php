<?php
/** Read-only live integration check. No fixtures, no writes to Sheets. */
require '/var/www/html/wp-load.php';
use CB2B\Plugin;
use CB2B\Data;
// Only this isolated QA host needs the session proxy's combined CA bundle.
if (is_file('/run/qa-ca-bundle.pem')) {
    add_filter('http_request_args', static function($args, $url) {
        if (str_starts_with($url, 'https://docs.google.com/')) { $args['sslcertificates'] = '/run/qa-ca-bundle.pem'; }
        return $args;
    }, 10, 2);
}
// Remove synthetic QA snapshots so a failed live fetch cannot be mistaken for real data.
delete_option(Plugin::key()); delete_option(Plugin::key() . '_status');
$result = Plugin::sync();
if (!empty($result['error'])) { fwrite(STDERR, 'LIVE DATA BLOCKED: ' . $result['error'] . "\n"); exit(2); }
$records = Data::filter(Plugin::snapshot()['records'], ['year'=>'2026']);
$metrics = Data::metrics($records);
$baseline = ['total'=>84164828,'count'=>56,'largest'=>32800000,'average'=>1502943,'months'=>[1=>0,130700,218500,33497750,1153812,1111500,8200,479000,5175,47560191,0,0]];
echo json_encode(['source'=>'live Monday Google Sheet', 'year'=>2026,'synced_central'=>Plugin::stamp(Plugin::snapshot()['synced']), 'metrics'=>$metrics, 'latest_date'=>$records[0]['date'] ?? null,'matches_2026_11_01_baseline'=>$metrics === $baseline,'validation_issues'=>$result['issues'] ?? []], JSON_PRETTY_PRINT) . "\n";
