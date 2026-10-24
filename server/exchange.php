<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
const NEWS_BASE='https://vlad-mixlab.alwaysdata.net/';
const RSS_SOURCE_URL='https://www.soundonsound.com/latest_news.xml';
const RSS_SOURCE_NAME='Sound On Sound';
const EXCHANGE_FIELDS=['id','title','description','published_at','link','source','category'];
const RSS_MAX_BYTES=524288;
function exchange_text(string $value,int $limit=10000):string {
    $value=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
    preg_match('/^.{0,'.min($limit,65535).'}/us',$value,$m);return trim($m[0]??'');
}
function exchange_plain(string $s,int $limit=10000):string {
    return exchange_text(html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8'),$limit);
}
function exchange_url(string $url):string {
    $url=trim($url);$p=parse_url($url);
    return $p&&in_array(strtolower($p['scheme']??''),['https','http'],true)&&!empty($p['host'])&&!isset($p['user'])&&!isset($p['pass'])&&!preg_match('/[\x00-\x20\x7f]/',$url)?$url:'';
}
function exchange_date(string $s):string {
    if(trim($s)==='')return '';
    try{return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}catch(Throwable $e){return '';}
}
function exchange_own(PDO $pdo):array {
    $rows=$pdo->query('SELECT id,title,body,published_at,category FROM news ORDER BY published_at DESC,id DESC')->fetchAll();$items=[];
    foreach($rows as $r)$items[]=['id'=>$r['id'],'title'=>$r['title'],'description'=>$r['body'],'published_at'=>$r['published_at'],'link'=>NEWS_BASE.'rss.html#news-'.rawurlencode($r['id']),'source'=>'MIXLAB','category'=>$r['category'],'origin'=>'own'];
    return $items;
}
function rss_parse(string $raw,string $source='Загруженный файл',bool $external=false):array {
    if($raw===''||strlen($raw)>RSS_MAX_BYTES)throw new InvalidArgumentException('Размер RSS-файла должен быть от 1 байта до 512 КБ.');
    $doc=new DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;$doc->validateOnParse=false;
    $old=libxml_use_internal_errors(true);
    try{$ok=$doc->loadXML($raw,LIBXML_NONET|LIBXML_NOBLANKS);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    if(!$ok||$doc->doctype)throw new InvalidArgumentException('Некорректный XML. Файлы с DTD и сущностями не поддерживаются.');
    $xp=new DOMXPath($doc);
    if($doc->documentElement->tagName!=='rss'||$doc->documentElement->getAttribute('version')!=='2.0'||$xp->query('/rss/channel')->length!==1)throw new InvalidArgumentException('Нужна лента RSS 2.0 с одним каналом.');
    $title=exchange_plain($xp->evaluate('string(/rss/channel/title)'),200);
    $nodes=$xp->query('/rss/channel/item');
    if($nodes->length>500)throw new InvalidArgumentException('В одном файле поддерживается не более 500 записей.');
    $items=[];$seen=[];$skipped=0;
    foreach($nodes as $node){
        $get=static fn(string $name):string=>$xp->evaluate('string(./'.$name.')',$node);
        $headline=exchange_plain($get('title'),300);$description=exchange_plain($get('description'),10000);
        if($headline===''&&$description===''){$skipped++;continue;}
        $url=exchange_url($get('link'));$guid=exchange_text($get('guid'),2048);
        $id=$guid!==''?$guid:($url!==''?$url:hash('sha256',$headline.$description));
        $dedup=$url!==''?$url:$id;
        if(isset($seen[$dedup])){$skipped++;continue;}$seen[$dedup]=true;
        $items[]=['id'=>$id,'title'=>$headline!==''?$headline:exchange_text($description,100),'description'=>$external?'':$description,'published_at'=>exchange_date($get('pubDate')),'link'=>$url,'source'=>$source,'category'=>exchange_plain($get('category'),100),'origin'=>$external?'external':'upload'];
        // External publications retain attribution and original links, without copying article bodies.
    }
    return ['title'=>$title,'items'=>$items,'skipped'=>$skipped];
}
function rss_cache_dir():string {return getenv('MIXLAB_RSS_CACHE_DIR')?:config()['private_dir'].'/rss';}
require_once __DIR__.'/rss_sources.php';
function rss_external(bool $force=false,?callable $download=null,?string $url=null,bool $fetch=true):array {
    $url=rss_validate_url($url??rss_default_url());$dir=rss_cache_dir();
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('RSS cache unavailable');
    $path=rss_cache_file($url);$lock=fopen($path.'.lock','c');if(!$lock)throw new RuntimeException('RSS lock unavailable');
    $load=static function()use($path,$url):array{$v=json_decode(@file_get_contents($path)?:'null',true);return is_array($v)&&($v['source_url']??'')===$url?$v:['items'=>[],'checked_at'=>'','updated_at'=>'','error'=>'','source_url'=>$url,'title'=>parse_url($url,PHP_URL_HOST)];};
    $state=$load();if(!$fetch){fclose($lock);return $state;}
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);$state['refreshing']=true;return $state;}
    try{
        $state=$load();$age=time()-(strtotime($state['checked_at'])?:0);if($age<(($force||$state['error']!=='')?60:900)&&(($state['parser_version']??0)===2||$state['error']!==''))return $state;
        $state['checked_at']=gmdate('c');
        try{
            if(!$download)rss_fetch_budget();
            $parsed=rss_parse(($download??'rss_download')($url),parse_url($url,PHP_URL_HOST),true);
            $state['title']=$parsed['title']?:parse_url($url,PHP_URL_HOST);
            foreach($parsed['items'] as &$item){$item['source']=$state['title'];$item['feed_url']=$url;}unset($item);
            $state['items']=$parsed['items'];$state['updated_at']=$state['checked_at'];$state['error']='';$state['parser_version']=2;
        }catch(Throwable $e){$state['error']='Не удалось обновить этот источник. Если лента уже загружалась, показана её сохранённая копия. Проверь адрес RSS и повтори позже.';error_log('MIXLAB RSS: refresh failed');}
        $tmp=tempnam($dir,'rss-');if($tmp===false)throw new RuntimeException('RSS temp file unavailable');
        try{if(file_put_contents($tmp,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false||!rename($tmp,$path))throw new RuntimeException('RSS cache write failed');}finally{if(is_file($tmp))unlink($tmp);}
        // Bound disposable source snapshots; saved site settings are outside this pattern.
        $files=glob($dir.'/'.str_repeat('[a-f0-9]',64).'.json')?:[];if(count($files)>100){usort($files,static fn($a,$b)=>filemtime($b)<=>filemtime($a));foreach(array_slice($files,100) as $old)if($old!==$path)@unlink($old);}
        return $state;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function rss_collection(bool $force=false,bool $fetch=true,?string $only=null):array {
    $sources=rss_sources();
    if($only!==null&&$only!==''){
        $only=rss_validate_url($only);$sources=array_values(array_filter($sources,static fn($source)=>$source['url']===$only));
        if(!$sources)throw new InvalidArgumentException('Эта RSS-лента не подключена. Выбери источник из списка.');
    }
    $items=[];$feeds=[];$seen=[];$deadline=microtime(true)+12;
    foreach($sources as $source){
        try{$state=rss_external($force,null,$source['url'],$fetch&&microtime(true)<$deadline);}
        catch(Throwable $e){$state=['items'=>[],'updated_at'=>'','checked_at'=>'','title'=>$source['title']?:parse_url($source['url'],PHP_URL_HOST),'error'=>'Источник временно недоступен.'];}
        $feeds[]=array_merge($source,$state);
        foreach($state['items'] as $item){$key=$item['link']?:$item['id'];if(isset($seen[$key]))continue;$seen[$key]=true;$items[]=$item;}
    }
    return ['items'=>$items,'feeds'=>$feeds];
}
function exchange_params(array $input):array {
    $p=[];foreach(['source'=>'all','q'=>'','sort'=>'newest','page'=>'1'] as $key=>$default){$v=$input[$key]??$default;if(!is_string($v)||!preg_match('//u',$v)||strlen($v)>400)throw new InvalidArgumentException('Проверь параметры поиска.');$p[$key]=trim($v);}
    if(!in_array($p['source'],['all','own','external'],true)||!in_array($p['sort'],['newest','oldest','title'],true)||!preg_match('/^[1-9][0-9]{0,5}$/D',$p['page']))throw new InvalidArgumentException('Неизвестный источник или порядок сортировки.');
    $p['page']=(int)$p['page'];return $p;
}
function exchange_select(array $items,array $p):array {
    $items=array_values(array_filter($items,static function($x)use($p){return ($p['source']==='all'||$p['source']===$x['origin'])&&($p['q']===''||preg_match('/'.preg_quote($p['q'],'/').'/iu',implode(' ',[$x['title'],$x['description'],$x['source'],$x['category']]))); }));
    usort($items,static function($a,$b)use($p){if($p['sort']==='title')return strcmp($a['title'],$b['title'])?:strcmp($a['id'],$b['id']);if($a['published_at']===''||$b['published_at']==='')return ($a['published_at']===''?1:0)<=>($b['published_at']===''?1:0);$cmp=(strtotime($a['published_at'])<=>strtotime($b['published_at']))?:strcmp($a['id'],$b['id']);return $p['sort']==='oldest'?$cmp:-$cmp;});return $items;
}
function exchange_element(DOMDocument $doc,DOMNode $parent,string $name,string $value):DOMElement {
    $el=$doc->createElement($name);$el->appendChild($doc->createTextNode(exchange_text($value,100000)));$parent->appendChild($el);return $el;
}
function exchange_rss(array $items,string $self=NEWS_BASE.'feed.php'):string {
    $doc=new DOMDocument('1.0','UTF-8');$doc->formatOutput=true;$rss=$doc->createElement('rss');$rss->setAttribute('version','2.0');$doc->appendChild($rss);$ch=$doc->createElement('channel');$rss->appendChild($ch);
    exchange_element($doc,$ch,'title','MIXLAB — новости');exchange_element($doc,$ch,'link',NEWS_BASE.'rss.html');exchange_element($doc,$ch,'description','Новости MIXLAB и музыкальных технологий.');exchange_element($doc,$ch,'language','ru');exchange_element($doc,$ch,'ttl','15');
    $atom=$doc->createElementNS('http://www.w3.org/2005/Atom','atom:link');$atom->setAttribute('href',$self);$atom->setAttribute('rel','self');$atom->setAttribute('type','application/rss+xml');$ch->appendChild($atom);
    $dates=array_filter(array_column($items,'published_at'));if($dates)exchange_element($doc,$ch,'lastBuildDate',gmdate(DATE_RSS,strtotime(max($dates))));
    foreach($items as $x){$item=$doc->createElement('item');$ch->appendChild($item);exchange_element($doc,$item,'title',$x['title']);if($x['link']!=='')exchange_element($doc,$item,'link',$x['link']);
        // RSS descriptions may contain encoded HTML. Escape source plain text before XML serialization.
        exchange_element($doc,$item,'description',htmlspecialchars($x['description'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
        $guid=exchange_element($doc,$item,'guid',$x['link']!==''?$x['link']:'urn:mixlab:'.hash('sha256',$x['id']));$guid->setAttribute('isPermaLink',$x['link']!==''?'true':'false');
        if($x['published_at']!=='')exchange_element($doc,$item,'pubDate',gmdate(DATE_RSS,strtotime($x['published_at'])));
        if($x['category']!=='')exchange_element($doc,$item,'category',$x['category']);
        if($x['origin']==='external'){$source=exchange_element($doc,$item,'source',$x['source']);$source->setAttribute('url',$x['feed_url']??RSS_SOURCE_URL);}
    }
    return $doc->saveXML();
}
function exchange_ascii(array $items):string {
    $out=implode("\t",EXCHANGE_FIELDS)."\n";
    foreach($items as $x){$cells=[];foreach(EXCHANGE_FIELDS as $f)$cells[]=json_encode((string)$x[$f],JSON_THROW_ON_ERROR);$out.=implode("\t",$cells)."\n";}return $out;
}
function exchange_xml(array $items):string {
    $doc=new DOMDocument('1.0','UTF-8');$doc->formatOutput=true;$root=$doc->createElement('news');$root->setAttribute('version','1');$root->setAttribute('count',(string)count($items));$doc->appendChild($root);
    foreach($items as $x){$item=$doc->createElement('item');$root->appendChild($item);foreach(EXCHANGE_FIELDS as $f)exchange_element($doc,$item,$f,(string)$x[$f]);}return $doc->saveXML();
}
function exchange_wrapped(array $items):string {
    $doc=new DOMDocument('1.0','UTF-8');$doc->formatOutput=true;$root=$doc->createElement('news-transfer');$root->setAttribute('version','1');$doc->appendChild($root);
    $meta=exchange_element($doc,$root,'fields',implode("\t",EXCHANGE_FIELDS));$meta->setAttribute('delimiter','tab');$meta->setAttribute('encoding','US-ASCII');$meta->setAttribute('escaping','json-string');
    $payload=$doc->createElement('payload');$payload->appendChild($doc->createTextNode(exchange_ascii($items)));$root->appendChild($payload);return $doc->saveXML();
}
function exchange_csv(array $items):string {
    $file=fopen('php://temp','r+');fwrite($file,"\xEF\xBB\xBF");fputcsv($file,EXCHANGE_FIELDS,';', '"','');
    foreach($items as $x){$row=[];foreach(EXCHANGE_FIELDS as $f){$v=(string)$x[$f];if(preg_match('/^[\s]*[=+@-]/u',$v))$v="'".$v;$row[]=$v;}fputcsv($file,$row,';','"','');}
    rewind($file);$out=stream_get_contents($file);fclose($file);return $out;
}
