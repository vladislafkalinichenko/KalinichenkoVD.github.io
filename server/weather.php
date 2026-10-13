<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function weather_cities(): array {
    return ['obninsk'=>['Обнинск',55.0968,36.6101],'moscow'=>['Москва',55.7558,37.6176],'spb'=>['Санкт-Петербург',59.9343,30.3351],'kazan'=>['Казань',55.7961,49.1064],'ekb'=>['Екатеринбург',56.8389,60.6057],'novosibirsk'=>['Новосибирск',55.0084,82.9357],'krasnodar'=>['Краснодар',45.0355,38.9753],'vladivostok'=>['Владивосток',43.1155,131.8855]];
}
function weather_read(string $path): array {
    if (!is_file($path)) return [];
    try { $data=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);return is_array($data)?$data:[]; }
    catch (Throwable $e) {return [];}
}
function weather_valid(array $city): bool {
    foreach (['temperature','feels_like','wind','code'] as $field) if (!isset($city[$field]) || !is_numeric($city[$field]) || !is_finite((float)$city[$field])) return false;
    return isset($city['observed_at']) && is_string($city['observed_at']) && strtotime($city['observed_at'])!==false;
}
function weather_newer(array $a,array $b): bool {
    if (!weather_valid($a)) return false;
    if (!weather_valid($b)) return true;
    $aTime=strtotime($a['observed_at']);$bTime=strtotime($b['observed_at']);
    return $aTime>$bTime || ($aTime===$bTime && strtotime($a['updated_at']??'1970-01-01')>=strtotime($b['updated_at']??'1970-01-01'));
}
function weather_fetch(string $key): array {
    [$name,$latitude,$longitude]=weather_cities()[$key];
    $url='https://api.open-meteo.com/v1/forecast?'.http_build_query(['latitude'=>$latitude,'longitude'=>$longitude,'current'=>'temperature_2m,apparent_temperature,weather_code,wind_speed_10m','wind_speed_unit'=>'ms','timezone'=>'GMT']);
    $context=stream_context_create(['http'=>['timeout'=>8,'follow_location'=>0,'header'=>"User-Agent: MixlabStudentSite/1.0\r\nAccept: application/json\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $raw=@file_get_contents($url,false,$context,0,1000000);
    if ($raw===false) throw new RuntimeException('Weather provider unavailable');
    $current=json_decode($raw,true,32,JSON_THROW_ON_ERROR)['current']??[];
    $city=['name'=>$name,'temperature'=>$current['temperature_2m']??null,'feels_like'=>$current['apparent_temperature']??null,'wind'=>$current['wind_speed_10m']??null,'code'=>$current['weather_code']??null,'observed_at'=>($current['time']??'').'Z'];
    if (!weather_valid($city)) throw new RuntimeException('Invalid weather provider data');
    return $city;
}
// Clock and fetch callbacks are used only by isolated CLI tests, never from user input.
function weather_result(string $key,bool $refresh,?callable $fetch=null,?int $now=null): array {
    if (!isset(weather_cities()[$key])) throw new InvalidArgumentException('Выбери город из списка.');
    $now??=time();$stamp=gmdate('Y-m-d\TH:i:s\Z',$now);
    $dir=getenv('MIXLAB_WEATHER_CACHE_DIR') ?: config()['private_dir'];
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Weather cache unavailable');
    $file=$dir.'/weather-cache.json';
    $baseline=weather_read(dirname(__DIR__).'/data/weather.json')['cities'][$key]??[];
    $cache=weather_read($file);$entry=$cache[$key]??[];$saved=$entry['city']??[];
    $city=weather_newer($saved,$baseline) ? $saved : $baseline;
    if (!weather_valid($city)) $city=[];
    $checked=$city['updated_at']??null;
    $result=['city'=>$city,'checked_at'=>$checked,'served_at'=>$stamp,'status'=>'cached'];
    if (!$refresh) return $result;
    $lock=fopen($dir.'/weather-cache.lock','c');
    if (!$lock) throw new RuntimeException('Weather cache lock unavailable');
    if (!flock($lock,LOCK_EX|LOCK_NB)) {fclose($lock);return array_merge($result,['status'=>'busy']);}
    try {
        $cache=weather_read($file);$entry=$cache[$key]??[];
        $saved=$entry['city']??[];
        if (weather_newer($saved,$city)) {$city=$saved;$result['city']=$city;$result['checked_at']=$city['updated_at']??null;}
        if ($now-(int)($entry['attempt_at']??0)<60) return array_merge($result,['status'=>!empty($entry['failed'])?'error':'recent']);
        $entry['attempt_at']=$now;
        try {
            $fresh=($fetch??'weather_fetch')($key);
            if (!weather_valid($fresh) || ($city && strtotime($fresh['observed_at'])<strtotime($city['observed_at']))) throw new RuntimeException('Invalid or older weather data');
            $unchanged=(bool)$city;
            foreach (['observed_at','temperature','feels_like','wind','code'] as $field) if (($city[$field]??null)!=$fresh[$field]) $unchanged=false;
            $fresh['name']=weather_cities()[$key][0];$fresh['updated_at']=$stamp;
            $entry['city']=$fresh;$entry['failed']=false;
            $result=array_merge($result,['city'=>$fresh,'checked_at'=>$stamp,'status'=>$unchanged?'unchanged':'updated']);
        } catch (Throwable $e) {error_log('MIXLAB weather: '.$e->getMessage());$entry['failed']=true;$result['status']='error';}
        $cache[$key]=$entry;
        $temporary=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary,json_encode($cache,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Weather cache write failed');
        chmod($temporary,0600);if (!rename($temporary,$file)) throw new RuntimeException('Weather cache replace failed');
        return $result;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
