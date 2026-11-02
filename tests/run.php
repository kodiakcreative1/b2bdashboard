<?php
/** Runs against the isolated WordPress site. All records below are synthetic QA fixtures. */
require '/var/www/html/wp-load.php';
use CB2B\Data;
use CB2B\Plugin;
$passed = 0;
function check($condition, $label) {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $passed++; echo "PASS: $label\n";
}
function throws($fn, $label) { try { $fn(); } catch (RuntimeException $e) { check(true, $label); return; } check(false, $label); }
function csv(array $rows, ?array $headers = null): string {
    $s = fopen('php://temp', 'r+'); fputcsv($s, $headers ?? Data::HEADERS, ',', '"', '');
    foreach ($rows as $row) { fputcsv($s, $row, ',', '"', ''); }
    rewind($s); return stream_get_contents($s);
}
$private = 'COLUMN_D_PRIVATE_QA@example.invalid';
$rows = [
 ['10/26/2026','QA Alpha','QA Business',$private,'Monday','QA Recipient','$328,000',"Quoted, multiline\nnote <script>alert(1)</script>"],
 ['10/27/2026','QA Beta','QA Business',$private,'Monday','QA Recipient','$262.50','Details search needle'],
 ['10/26/2026','QA Gamma','QA Business 2',$private,'Monday','QA Recipient','60','Dues'],
 ['10/26/2026','QA Delta','QA Business 2',$private,'Monday','QA Recipient','7','Donation'],
 ['10/26/2026','QA Alpha','QA Business',$private,'Monday','QA Recipient','$328,000',"Quoted, multiline\nnote <script>alert(1)</script>"],
 ['1/1/2025','QA Prior Year','QA Business',$private,'Monday','QA Recipient','1.25','Prior year'],
 ['2/30/2026','Invalid Date','QA',$private,'Monday','QA','50','invalid'],
 ['10/1/2026','Invalid Amount','QA',$private,'Monday','QA','12,34','invalid'],
 ['10/1/2026','Wrong Group','QA',$private,'Tuesday','QA','50','wrong group'],
 array_fill(0, 8, '')
];
$rows[0][7] .= str_repeat(' Long QA note.', 30);
$rows[4] = $rows[0];
$fixture = csv($rows);
$parsed = Data::parse($fixture, 'Monday'); $records = $parsed['records'];
check(count($records) === 6, 'Valid rows preserved including exact duplicates, tiny amounts and dues');
check(count($parsed['issues']) === 3 && $parsed['blank_rows'] === 1, 'Invalid date, amount, group and blank-row diagnostics');
check(strpos(json_encode($parsed), $private) === false, 'Column D excluded from canonical model and diagnostics');
check(strpos(json_encode($records), '<script>') === false, 'HTML sanitized from notes');
check(strpos($records[1]['details'], "\n") !== false, 'Quoted commas and embedded newlines preserved');
check($records[0]['name'] === 'QA Beta', 'Newest transaction first');
check($records[1]['row'] === 6 && $records[2]['row'] === 5, 'Same-date ordering uses descending sheet row');
$year = Data::filter($records, ['year' => '2026']); $metrics = Data::metrics($year);
check($metrics['count'] === 5 && $metrics['total'] === 65632950, 'Year filter and integer-cent total');
check($metrics['largest'] === 32800000 && $metrics['average'] === 13126590, 'Largest and exact rounded average');
check(count($metrics['months']) === 12 && $metrics['months'][1] === 0 && $metrics['months'][10] === 65632950, 'All twelve months, including zero months');
check(Data::metrics([['date'=>'2026-01-01','cents'=>1],['date'=>'2026-01-01','cents'=>0]])['average'] === 1, 'Half-cent average rounds exactly');
check(Data::cents('$262.50') === 26250 && Data::cents('60') === 6000 && Data::cents('0') === 0, 'Currency formats and zero');
check(Data::cents('100000000000.00') === 10000000000000, 'No arbitrary high-value transaction cutoff');
check(Data::cents('-$12.34') === -1234 && Data::money(-1234) === '-$12.34', 'Signed USD values');
foreach (['$1,00','1e6','NaN','1.234','1.','1.2.3','', '=100+5'] as $invalid) { check(Data::cents($invalid) === null, 'Reject invalid money ' . $invalid); }
check(Data::date('2/29/2024') === '2024-02-29' && Data::date('2/29/2026') === null, 'Leap-date validation');
check(Data::date('2026-10-27') === '2026-10-27' && Data::date('10/27/2026 14:15:01') === '2026-10-27', 'ISO date and timestamp parsing');
check(count(Data::filter($records, ['year'=>'2026','search'=>'needle'])) === 1, 'Search notes');
check(count(Data::filter($records, ['year'=>'2026','search'=>'QA Gamma'])) === 1, 'Search names');
check(count(Data::filter($records, ['year'=>'2026','business'=>'QA Business 2'])) === 2, 'Exact business filter');
check(count(Data::filter($records, ['year'=>'2026','from'=>'2026-10-27','to'=>'2026-10-27'])) === 1, 'Inclusive date range');
$sorted = Data::filter($records, ['year'=>'2026','sort'=>'amount','order'=>'asc']);
check($sorted[0]['cents'] === 700 && $sorted[4]['cents'] === 32800000, 'Numeric amount sort');
throws(fn()=>Data::parse('<html>Login</html>', 'Monday'), 'Reject HTML/login response');
throws(fn()=>Data::parse('not,a,valid,header', 'Monday'), 'Reject unexpected schema');
throws(fn()=>Data::parse(csv([]), 'Monday'), 'Reject empty replacement snapshots');
check(count(Data::parse(csv([['10/27/2026','','',$private,'Monday','','0','Valid zero-value submission']]), 'Monday')['records']) === 1, 'Valid date, amount and group count even when optional public fields are blank');
$headers = array_reverse(Data::HEADERS);
$reordered = csv([array_reverse($rows[0])], $headers);
check(count(Data::parse($reordered, 'Monday')['records']) === 1, 'Header mapping tolerates reordered columns');
$http_mode = 'success'; $http_calls = 0;
$mock = function($pre, $args, $url) use (&$http_mode, &$http_calls, $fixture) {
    if (strpos($url, 'https://docs.google.com/spreadsheets/d/') !== 0) { return $pre; }
    $http_calls++;
    check(str_ends_with($url, 'export?format=csv&gid=0'), 'Sync uses configured read-only Google CSV endpoint');
    if ($http_mode === 'network') { return new WP_Error('test_network', 'Synthetic outage'); }
    $body = match($http_mode) { 'html' => '<html>Login</html>', 'empty' => csv([]), default => $fixture };
    return ['headers'=>['content-type'=>'text/csv'],'body'=>$body,'response'=>['code'=>$http_mode === '403' ? 403 : 200,'message'=>'QA'],'cookies'=>[]];
};
add_filter('pre_http_request', $mock, 10, 3);
$result = Plugin::sync();
check(empty($result['error']) && count(Plugin::snapshot()['records']) === 6, 'Successful sync persists sanitized snapshot');
check(!str_contains(serialize(Plugin::snapshot()), $private), 'Private email absent from persisted public cache');
$good = Plugin::snapshot();
foreach (['network','html','empty','403'] as $mode) {
    $http_mode = $mode; $result = Plugin::sync();
    check(!empty($result['error']) && Plugin::snapshot() === $good, 'Last-good cache survives ' . $mode . ' failure');
}
$lock = Plugin::key() . '_lock'; $token = time() . ':qa-lock'; add_option($lock, $token, '', false); $calls = $http_calls;
check(!empty(Plugin::sync()['error']) && $calls === $http_calls, 'Overlap lock blocks another download');
Plugin::release($lock, 'wrong-token'); check(get_option($lock) === $token, 'A different owner cannot release the lock');
Plugin::release($lock, $token); check(get_option($lock) === false, 'Lock owner can release it');
add_option($lock, (time()-180) . ':expired', '', false); $http_mode = 'success';
check(empty(Plugin::sync()['error']), 'Expired lock recovery');
$settings = Plugin::settings(); $original_key = Plugin::key();
$changed = $settings; $changed['groups'][] = ['id'=>'thursday','name'=>'Thursday B2B Group','tab'=>'Thursday','gid'=>'125109628','enabled'=>false];
update_option(Plugin::OPTION, $changed, false);
check(Plugin::active()['id'] === 'monday' && Plugin::key() === $original_key, 'Future inactive group does not change public source');
$changed['active'] = 'thursday'; update_option(Plugin::OPTION, $changed, false);
check(Plugin::active() === null && !empty(Plugin::sync()['error']), 'Disabled groups cannot become public or sync');
$changed['groups'][1]['enabled'] = true; update_option(Plugin::OPTION, $changed, false);
check(Plugin::key() !== $original_key && Plugin::snapshot() === [], 'Group-specific cache prevents cross-group leakage');
update_option(Plugin::OPTION, $settings, false);
foreach ([1,15,30] as $minutes) {
    $s = $settings; $s['interval']=$minutes; update_option(Plugin::OPTION,$s,false); Plugin::schedule();
    check(wp_get_schedule(Plugin::CRON) === 'cb2b_' . $minutes, 'Cron refresh interval ' . $minutes);
}
update_option(Plugin::OPTION, $settings, false); Plugin::schedule();
$before_deactivate = Plugin::snapshot();
Plugin::deactivate();
check(wp_next_scheduled(Plugin::CRON) === false, 'Deactivation removes scheduled refresh');
check(!isset(get_option('rewrite_rules')['^dashboard/?$']), 'Deactivation removes dashboard rewrite rule');
Plugin::activate();
check(isset(get_option('rewrite_rules')['^dashboard/?$']) && wp_next_scheduled(Plugin::CRON) !== false, 'Reactivation restores routes and scheduler');
check(Plugin::snapshot() === $before_deactivate, 'Deactivation retains last-good data');
remove_filter('pre_http_request', $mock, 10);
// Leave only explicit synthetic local-QA data for browser checks. Never included in the ZIP.
update_option(Plugin::key() . '_status', ['attempted'=>time(),'error'=>'','issues'=>[]], false);
$display = $good;
for ($i=0; $i<23; $i++) { $r = $records[0]; $r['name'] = 'QA Pagination ' . $i; $r['row']=100+$i; $display['records'][]=$r; }
usort($display['records'], fn($a,$b)=>strcmp($b['date'],$a['date']) ?: ($b['row']<=>$a['row']));
update_option(Plugin::key(), $display, false);
echo "\n$passed assertions passed. Browser fixture is synthetic, local-only.\n";
