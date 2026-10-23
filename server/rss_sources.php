<?php
declare(strict_types=1);
function rss_validate_url(string $url):string {
    $url=trim($url);$p=parse_url($url);
    if(strlen($url)>2048||!$p||!in_array(strtolower($p['scheme']??''),['https','http'],true)||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new InvalidArgumentException('Введи полный адрес внешней RSS-ленты: https://сайт/лента.xml, без логина и пароля.');
    $scheme=strtolower($p['scheme']);$host=strtolower($p['host']??'');
    if(!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)||str_contains($host,'..')||(!str_contains($host,'.'))||$host===parse_url(NEWS_BASE,PHP_URL_HOST)||isset($p['port'])&&$p['port']!==($scheme==='https'?443:80))throw new InvalidArgumentException('Нужен адрес внешнего общедоступного сайта на стандартном HTTP или HTTPS порту.');
    if(filter_var($host,FILTER_VALIDATE_IP)!==false&&!rss_public_ipv4($host))throw new InvalidArgumentException('Укажи общедоступную внешнюю RSS-ленту.');
    return $scheme.'://'.$host.($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'');
}
function rss_public_ipv4(string $ip):bool {
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    $n=unpack('N',inet_pton($ip))[1];
    foreach([[0x64400000,0xffc00000],[0xc0000000,0xffffff00],[0xc0000200,0xffffff00],[0xc6120000,0xfffe0000],[0xc6336400,0xffffff00],[0xcb007100,0xffffff00],[0xe0000000,0xf0000000],[0xf0000000,0xf0000000]] as [$network,$mask])if(($n&$mask)===$network)return false;
    return true;
}
function rss_resolve_ip(string $host,?callable $resolver=null):string {
    $ips=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)?[$host]:($resolver?$resolver($host):@gethostbynamel($host));
    if(!$ips)throw new RuntimeException('RSS DNS unavailable');
    foreach($ips as $ip)if(!is_string($ip)||!rss_public_ipv4($ip))throw new InvalidArgumentException('RSS-лента должна находиться на общедоступном внешнем сайте.');
    return $ips[0];
}
function rss_redirect_url(string $base,string $location):string {
    if(preg_match('~^[a-z][a-z0-9+.-]*:~i',$location))return rss_validate_url($location);
    $p=parse_url($base);$prefix=$p['scheme'].'://'.$p['host'];
    if(str_starts_with($location,'//'))return rss_validate_url($p['scheme'].':'.$location);
    if(str_starts_with($location,'?'))return rss_validate_url($prefix.($p['path']??'/').$location);
    $path=str_starts_with($location,'/')?$location:preg_replace('~/[^/]*$~','/',($p['path']??'/')).$location;
    return rss_validate_url($prefix.$path);
}
function rss_http_request(string $url,string $ip,float $timeout):array {
    $p=parse_url($url);$target=$p['scheme'].'://'.$ip.($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'');
    // Resolve once, check every result, and connect to that exact public IP. Never re-resolve in the transport.
    $context=stream_context_create(['http'=>['timeout'=>$timeout,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,'protocol_version'=>1.0,'header'=>['Host: '.$p['host'],'User-Agent: MIXLAB-RSS/2.0 (+'.NEWS_BASE.')','Accept: application/rss+xml, application/xml, text/xml','Accept-Encoding: identity','Connection: close']],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$p['host'],'SNI_enabled'=>true]]);
    $stream=@fopen($target,'rb',false,$context);if(!$stream)throw new RuntimeException('RSS connection failed');
    try {
        $meta=stream_get_meta_data($stream);$headers=$meta['wrapper_data']??[];$status=0;$location='';
        foreach($headers as $line){if(preg_match('~^HTTP/\S+ (\d{3})~',$line,$m))$status=(int)$m[1];if(stripos($line,'Location:')===0)$location=trim(substr($line,9));if(stripos($line,'Content-Length:')===0&&(int)trim(substr($line,15))>RSS_MAX_BYTES)throw new RuntimeException('RSS too large');}
        if(in_array($status,[301,302,303,307,308],true))return ['status'=>$status,'location'=>$location,'body'=>''];
        if($status!==200)throw new RuntimeException('RSS HTTP status '.$status);
        $body='';$deadline=microtime(true)+$timeout;
        while(!feof($stream)){
            if(microtime(true)>$deadline)throw new RuntimeException('RSS timeout');
            $chunk=fread($stream,min(8192,RSS_MAX_BYTES+1-strlen($body)));if($chunk===false)throw new RuntimeException('RSS read failed');$body.=$chunk;
            if(strlen($body)>RSS_MAX_BYTES)throw new RuntimeException('RSS too large');
            if((stream_get_meta_data($stream)['timed_out']??false))throw new RuntimeException('RSS timeout');
        }
        return ['status'=>200,'location'=>'','body'=>$body];
    }finally{fclose($stream);}
}
function rss_download(string $url=RSS_SOURCE_URL,?callable $resolver=null,?callable $request=null):string {
    $url=rss_validate_url($url);$seen=[];$deadline=microtime(true)+12;
    for($hop=0;$hop<4;$hop++){
        if(isset($seen[$url]))throw new RuntimeException('RSS redirect loop');$seen[$url]=true;
        $ip=rss_resolve_ip(parse_url($url,PHP_URL_HOST),$resolver);$timeout=min(4.0,$deadline-microtime(true));if($timeout<=0)throw new RuntimeException('RSS timeout');
        $response=($request??'rss_http_request')($url,$ip,$timeout);
        if($response['status']===200){if(strlen($response['body'])>RSS_MAX_BYTES)throw new RuntimeException('RSS too large');return $response['body'];}
        if(!in_array($response['status'],[301,302,303,307,308],true)||$response['location']==='')throw new RuntimeException('RSS HTTP error');
        $url=rss_redirect_url($url,$response['location']);
    }
    throw new RuntimeException('Too many RSS redirects');
}
function rss_default_url():string {
    $path=rss_cache_dir().'/default-source.json';$v=json_decode(@file_get_contents($path)?:'{}',true);
    try{return rss_validate_url(is_array($v)&&is_string($v['url']??null)?$v['url']:RSS_SOURCE_URL);}catch(InvalidArgumentException $e){return RSS_SOURCE_URL;}
}
function rss_save_default(string $url):void {
    $url=rss_validate_url($url);$dir=rss_cache_dir();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('RSS directory unavailable');
    $tmp=tempnam($dir,'default-');if($tmp===false)throw new RuntimeException('RSS settings unavailable');
    try{if(file_put_contents($tmp,json_encode(['url'=>$url],JSON_THROW_ON_ERROR))===false||!rename($tmp,$dir.'/default-source.json'))throw new RuntimeException('RSS settings write failed');}finally{if(is_file($tmp))unlink($tmp);}
}
function rss_sources():array {
    $path=rss_cache_dir().'/sources.json';
    // Migrate the previous shared default lazily. An explicitly empty list stays empty.
    if(!is_file($path))return [['url'=>rss_default_url(),'title'=>'','added_at'=>'']];
    $data=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($data)||!is_array($data['sources']??null)||count($data['sources'])>8)throw new RuntimeException('Invalid RSS source settings');
    $out=[];
    foreach($data['sources'] as $source){
        if(!is_array($source)||!is_string($source['url']??null)||!is_string($source['title']??null))throw new RuntimeException('Invalid RSS source');
        $source['url']=rss_validate_url($source['url']);$out[$source['url']]=$source;
    }
    return array_values($out);
}
function rss_change_sources(callable $change):void {
    $dir=rss_cache_dir();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('RSS directory unavailable');
    $lock=fopen($dir.'/sources.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('RSS settings locked');
    try{
        $sources=$change(rss_sources());$tmp=tempnam($dir,'sources-');if($tmp===false)throw new RuntimeException('RSS settings unavailable');
        try{chmod($tmp,0600);if(file_put_contents($tmp,json_encode(['sources'=>array_values($sources)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false||!rename($tmp,$dir.'/sources.json'))throw new RuntimeException('RSS settings write failed');}finally{if(is_file($tmp))unlink($tmp);}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function rss_add_source(string $url,string $title):void {
    $url=rss_validate_url($url);
    rss_change_sources(static function(array $sources)use($url,$title):array{
        foreach($sources as $source)if($source['url']===$url)throw new InvalidArgumentException('Эта RSS-лента уже подключена.');
        if(count($sources)>=8)throw new InvalidArgumentException('Можно подключить до 8 лент. Удали ненужный источник перед добавлением нового.');
        $sources[]=['url'=>$url,'title'=>exchange_plain($title,200),'added_at'=>gmdate('c')];return $sources;
    });
}
function rss_remove_source(string $url):void {
    $url=rss_validate_url($url);
    rss_change_sources(static function(array $sources)use($url):array{
        $filtered=array_values(array_filter($sources,static fn($source)=>$source['url']!==$url));
        if(count($filtered)===count($sources))throw new InvalidArgumentException('Этот источник уже отключён.');
        return $filtered;
    });
}
function rss_cache_file(string $url):string {return rss_cache_dir().'/'.hash('sha256',rss_validate_url($url)).'.json';}
function rss_fetch_budget():void {
    $lock=fopen(rss_cache_dir().'/budget.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('RSS busy');
    try{$hits=json_decode(stream_get_contents($lock)?:'[]',true);$hits=array_values(array_filter(is_array($hits)?$hits:[],static fn($t)=>is_int($t)&&$t>time()-60));if(count($hits)>=30)throw new RuntimeException('RSS source requests limited');$hits[]=time();rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($hits));}finally{flock($lock,LOCK_UN);fclose($lock);}
}
