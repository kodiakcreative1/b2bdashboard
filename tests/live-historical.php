<?php
/** Actual read-only Google sync; prints aggregate summaries only. */
require '/var/www/html/wp-load.php';
use CB2B\Historical;
use CB2B\Plugin;
if (wp_get_environment_type() !== 'local') { exit(1); }
add_filter('http_request_args', static function($args,$url) {
    if (str_starts_with($url,'https://docs.google.com/') && is_file('/run/qa-ca-bundle.pem')) { $args['sslcertificates']='/run/qa-ca-bundle.pem'; }
    return $args;
},10,2);
$settings=Plugin::settings(); $current=Plugin::snapshot();
if (isset($argv[1])) {
 $rows=Historical::parse(file_get_contents($argv[1]))['rows'];
 echo "Source: actual read-only Google CSV downloaded outside WordPress; validated with plugin parser.\n";
} else {
 $result=Historical::sync();
 if (!empty($result['error'])) { fwrite(STDERR,'LIVE HISTORICAL BLOCKED: '.$result['error']."\n"); exit(2); }
 $rows=Historical::snapshot()['rows'];
}
$expected=['monday'=>[2021,186885790,92921600,279807390],'tuesday'=>[2014,1525396030,12749939,1538145969],'thursday'=>[2016,120322093,44077377,164399470]];
foreach($expected as $key=>$x) {
 $r=$rows[$key]??[];
 if ([$r['start']??null,$r['through2024']??null,$r['year2025']??null,$r['grand']??null]!==$x) { fwrite(STDERR,'Live reference mismatch: '.$key."\n"); exit(1); }
 echo 'PASS: LIVE '.ucfirst($key).' start '.$r['start'].': '.CB2B\Data::money($r['through2024']).' + '.CB2B\Data::money($r['year2025']).' = '.CB2B\Data::money($r['grand'])."\n";
}
if(Plugin::settings()!==$settings || Plugin::snapshot()!==$current) { throw new RuntimeException('Historical sync modified current settings/data'); }
echo "PASS: Actual Google historical data validated for all three groups; current transaction cache and settings unchanged.\n";
