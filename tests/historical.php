<?php
/** Reference figures are test expectations only; runtime data must always come from Sheets. */
require '/var/www/html/wp-load.php';
use CB2B\Data;
use CB2B\Historical;
use CB2B\Plugin;
$n = 0;
function hcheck($ok, $label) { global $n; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } $n++; echo "PASS: $label\n"; }
function rejects($fn,$label) { try { $fn(); } catch (RuntimeException $e) { hcheck(true,$label); return; } hcheck(false,$label); }
function hcsv($rows,$headers=null) { $s=fopen('php://temp','r+'); fputcsv($s,$headers??Historical::HEADERS,',','"',''); foreach($rows as $r) { fputcsv($s,$r,',','"',''); } rewind($s); return stream_get_contents($s); }
$values=[['Monday','2021','$1,868,857.90','$929,216.00','$2,798,073.90'],['Tuesday','2014','$15,253,960.30','$127,499.39','$15,381,459.69'],['Thursday','2016','$1,203,220.93','$440,773.77','$1,643,994.70']];
$csv=hcsv($values); $parsed=Historical::parse($csv);
$expected=['monday'=>[2021,186885790,92921600,279807390],'tuesday'=>[2014,1525396030,12749939,1538145969],'thursday'=>[2016,120322093,44077377,164399470]];
foreach($expected as $key=>$x) { $r=$parsed['rows'][$key]; hcheck([$r['start'],$r['through2024'],$r['year2025'],$r['grand']]===$x,"$key matches verified figures in cents"); hcheck($r['through2024']+$r['year2025']===$r['grand'],"$key historical subtotals reconcile exactly"); }
hcheck(count($parsed['rows'])===3,'Exactly one summary per group');
hcheck(Historical::parse("\xEF\xBB\xBF".$csv)['rows']===$parsed['rows'],'BOM accepted');
hcheck(Historical::parse(hcsv(array_map('array_reverse',$values),array_reverse(Historical::HEADERS)))['rows']===$parsed['rows'],'Reordered historical columns mapped by header');
hcheck(count(Historical::parse($csv."\n,,,,\n")['rows'])===3,'Blank historical rows ignored');
foreach(['', '<html>Login</html>', '<?xml version="1.0"?>','Bad,Headers'] as $bad) { rejects(fn()=>Historical::parse($bad),'Invalid/HTML/XML/schema input rejected'); }
$invalid=$values; $invalid[0][4]='1.00'; rejects(fn()=>Historical::parse(hcsv($invalid)),'Mismatched subtotals reject the entire import');
$invalid=$values; $invalid[0][1]='2026'; rejects(fn()=>Historical::parse(hcsv($invalid)),'Impossible reporting start year rejected');
$invalid=$values; $invalid[0][2]='12,34'; rejects(fn()=>Historical::parse(hcsv($invalid)),'Malformed currency rejected');
$invalid=$values; $invalid[]=[' monday ','2021','1','2','3']; rejects(fn()=>Historical::parse(hcsv($invalid)),'Normalized duplicate group rejected');
$invalid=$values; $invalid[]=['Tuesday']; rejects(fn()=>Historical::parse(hcsv($invalid)),'Partial-width rows fail closed');
$headers=Historical::HEADERS; $headers[4]='2025 Total'; rejects(fn()=>Historical::parse(hcsv($values,$headers)),'Duplicate/missing required header rejected');
rejects(fn()=>Historical::parse(hcsv([['Monday','2021','92233720368547758.07','0.01','1']])),'Positive integer overflow rejected');
rejects(fn()=>Historical::parse(hcsv([['Monday','2021','-92233720368547758.07','-0.01','1']])),'Negative integer overflow rejected');
$signed=Historical::parse(hcsv([['Monday','2021','10.50','-0.50','10.00']])); hcheck($signed['rows']['monday']['grand']===1000,'Signed adjustments sum in integer cents');
$mode='success'; $calls=0;
$mock=function($pre,$args,$url) use(&$mode,&$calls,$csv,$values) {
 if(!str_contains($url,'gid='.Historical::GID)) return $pre;
 $calls++;
 hcheck(str_starts_with($url,'https://docs.google.com/spreadsheets/d/'),'Historical fetch restricted to generated Google URL');
 if($mode==='network')return new WP_Error('qa','Synthetic outage');
 if($mode==='fallback' && str_contains($url,'/export?'))return new WP_Error('qa','Synthetic export redirect outage');
 $body=match($mode){'html'=>'<html>Login</html>','empty'=>hcsv([]),'invalid'=>hcsv([['Monday','2021','1','2','4']]),'partial'=>hcsv([$values[0]]),default=>$csv};
 if($mode==='size')$body=str_repeat('x',1024*1024);
 return ['headers'=>['content-type'=>'text/csv'],'body'=>$body,'response'=>['code'=>$mode==='403'?403:200,'message'=>'QA'],'cookies'=>[]];
};
add_filter('pre_http_request',$mock,10,3);
$current=Plugin::snapshot(); $settings=Plugin::settings();
$result=Historical::sync(); hcheck(empty($result['error']),'Historical sync succeeds independently');
$good=Historical::snapshot(); hcheck(count($good['rows'])===3,'Historical summaries cached separately');
hcheck(Plugin::snapshot()===$current,'Historical sync never alters current transaction cache');
hcheck(Plugin::settings()===$settings,'Historical sync never changes existing settings');
foreach(['network','403','html','empty','invalid','partial','size'] as $failure) { $mode=$failure; $r=Historical::sync(); hcheck(!empty($r['error']) && Historical::snapshot()===$good,"$failure retains entire last-good historical snapshot"); }
$mode='fallback'; $before=$calls; hcheck(empty(Historical::sync()['error']) && $calls===$before+2,'Read-only query fallback succeeds after export transport failure');
$lock=Historical::key().'_lock'; $token=time().':qa'; add_option($lock,$token,'',false); $before=$calls;
hcheck(!empty(Historical::sync()['error'])&&$calls===$before,'Historical refresh overlap is blocked before HTTP');
Plugin::release($lock,$token); add_option($lock,(time()-360).':expired','',false); $mode='success';
hcheck(empty(Historical::sync()['error']),'Expired historical lock safely recovered');
foreach(['monday','tuesday','thursday'] as $group) {
 $s=$settings; $s['groups']=[['id'=>$group,'name'=>'Customized Chamber display','tab'=>' '.ucfirst($group).' ','gid'=>'0','enabled'=>true]]; $s['active']=$group; update_option(Plugin::OPTION,$s,false);
 hcheck(Historical::for_active()['grand']===$expected[$group][3],"$group maps using configured tab despite customized display name");
}
$s['groups'][0]['tab']='Wednesday'; $s['groups'][0]['id']='monday'; $s['active']='monday'; $s['groups'][0]['name']='Monday'; update_option(Plugin::OPTION,$s,false);
hcheck(Historical::for_active()===null,'Missing configured tab cannot fall back to an unrelated old name/id');
$s['groups'][0]['enabled']=false; update_option(Plugin::OPTION,$s,false); hcheck(Historical::for_active()===null,'Disabled group has no public history');
$s=$settings; $s['spreadsheet']='AnotherSpreadsheetID123456789'; update_option(Plugin::OPTION,$s,false); hcheck(Historical::snapshot()===[],'Different workbook uses a different historical cache');
update_option(Plugin::OPTION,$settings,false);
foreach([1,15,30] as $minutes) { $s=$settings; $s['interval']=$minutes; update_option(Plugin::OPTION,$s,false); Plugin::schedule(); hcheck(wp_get_schedule(Plugin::HISTORICAL_CRON)==='cb2b_'.$minutes,'Independent historical cron respects interval '.$minutes); }
update_option(Plugin::OPTION,$settings,false);
$before=Historical::snapshot(); Plugin::deactivate();
hcheck(!wp_next_scheduled(Plugin::CRON)&&!wp_next_scheduled(Plugin::HISTORICAL_CRON),'Deactivation clears both cron events');
hcheck(!isset(get_option('rewrite_rules')['^dashboard/historical/?$']),'Deactivation clears historical route');
Plugin::activate(); hcheck(Historical::snapshot()===$before && Plugin::settings()===$settings,'Reactivation preserves historical cache and settings');
hcheck(isset(get_option('rewrite_rules')['^dashboard/historical/?$']),'Activation registers historical route');
$before=Plugin::settings(); delete_option('cb2b_installed_version'); Plugin::upgrade();
hcheck(get_option('cb2b_installed_version')===CB2B_VERSION && Plugin::settings()===$before && wp_next_scheduled(Plugin::HISTORICAL_CRON),'ZIP-update initialization adds historical route/schedule without resetting settings');
remove_filter('pre_http_request',$mock,10);
update_option(Historical::key().'_status',['attempted'=>time(),'error'=>''],false);
echo "\n$n historical assertions passed. Reference fixtures are test-only.\n";
