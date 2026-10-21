<?php
declare(strict_types=1);
ini_set('display_errors','0');require __DIR__.'/server/exchange.php';
header('X-Content-Type-Options: nosniff');header('Cache-Control: no-cache, must-revalidate');
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){header('Allow: GET, HEAD');http_response_code(405);exit;}
    $format=$_GET['format']??'rss';if(!is_string($format)||!in_array($format,['rss','xml','csv','txt','wrapped'],true))throw new InvalidArgumentException('Неизвестный формат.');
    $p=exchange_params(array_merge(['source'=>'own'],$_GET));$items=exchange_own(db());
    if($p['source']!=='own')$items=array_merge($items,rss_external()['items']);$items=exchange_select($items,$p);
    $self=NEWS_BASE.'feed.php?'.http_build_query(['source'=>$p['source'],'q'=>$p['q'],'sort'=>$p['sort']],'','&',PHP_QUERY_RFC3986);
    $out=match($format){'rss'=>exchange_rss($items,$self),'xml'=>exchange_xml($items),'csv'=>exchange_csv($items),'txt'=>exchange_ascii($items),'wrapped'=>exchange_wrapped($items)};
    $mime=match($format){'rss'=>'application/rss+xml; charset=UTF-8','xml','wrapped'=>'application/xml; charset=UTF-8','csv'=>'text/csv; charset=UTF-8','txt'=>'text/plain; charset=US-ASCII'};
    header('Content-Type: '.$mime);
    if($format!=='rss'||isset($_GET['download'])){ $ext=match($format){'rss','xml','wrapped'=>'xml','csv'=>'csv','txt'=>'txt'};header('Content-Disposition: attachment; filename="mixlab-news-'.$format.'.'.$ext.'"');}
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $out;
}catch(InvalidArgumentException $e){http_response_code(422);header('Content-Type: text/plain; charset=UTF-8');echo $e->getMessage();}
catch(Throwable $e){error_log('MIXLAB exchange: '.$e->getMessage());http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');echo 'Не удалось подготовить ленту. Повтори позже.';}
