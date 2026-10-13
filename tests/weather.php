<?php
// Isolated cache and injected provider: no live API, credentials or production data.
declare(strict_types=1);
require dirname(__DIR__).'/server/weather.php';
function check(bool $condition,string $message): void {if (!$condition) throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/mixlab-weather-'.bin2hex(random_bytes(8));mkdir($root,0700);putenv('MIXLAB_WEATHER_CACHE_DIR='.$root);
$base=weather_read(dirname(__DIR__).'/data/weather.json')['cities']['obninsk'];
$now=max(time(),strtotime($base['observed_at']))+3600;
$calls=0;
$fetch=function()use(&$calls,$base,$now):array {$calls++;$fresh=$base;$fresh['observed_at']=gmdate('Y-m-d\TH:i:s\Z',$now);$fresh['temperature']=9.25;return $fresh;};
try {
 $a=weather_result('obninsk',true,$fetch,$now);check($a['status']==='updated'&&$calls===1,'Refresh must contact provider');
 check($a['checked_at']===gmdate('Y-m-d\TH:i:s\Z',$now),'Successful check time');
 $b=weather_result('obninsk',true,$fetch,$now+1);check($b['status']==='recent'&&$calls===1,'Short repeated click uses recent result');
 check($b['checked_at']===$a['checked_at'],'Do not pretend to contact source during cooldown');
 $c=weather_result('obninsk',true,$fetch,$now+61);check($c['status']==='unchanged'&&$calls===2,'Same forecast gets explicit unchanged status');
 check($c['city']['observed_at']===$a['city']['observed_at']&&$c['checked_at']!==$a['checked_at'],'Forecast time stays, check time advances');
 $d=weather_result('obninsk',false,$fetch,$now+62);check($d['city']['temperature']===9.25&&$calls===2,'Later client receives shared refreshed weather');
 $failed=weather_result('obninsk',true,static function(){throw new RuntimeException('Expected test outage');},$now+122);
 check($failed['status']==='error'&&$failed['checked_at']===$c['checked_at']&&$failed['city']['temperature']===9.25,'Outage preserves last data and successful check time');
 $repeat=weather_result('obninsk',true,$fetch,$now+123);check($repeat['status']==='error'&&$calls===2,'Failure is not described as success');
 try {weather_result('../../etc/passwd',true,$fetch,$now+200);throw new RuntimeException('Accepted invalid city');}catch(InvalidArgumentException $e){}
 echo "OK: provider refresh, honest check/forecast timestamps, cooldown, shared cache, unchanged result, failure preservation, invalid city.\n";
} finally {foreach(glob($root.'/*') as $f)unlink($f);rmdir($root);}
