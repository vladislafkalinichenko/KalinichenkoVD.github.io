<?php
declare(strict_types=1);
ini_set('display_errors','0');require __DIR__.'/server/exchange.php';
header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);session_start();$_SESSION['rss_csrf']??=bin2hex(random_bytes(24));
$e=static fn($s):string=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$fmtdate=static function(string $date):string {return $date===''?'Дата не указана':(new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');};
$error='';$uploadError='';$sourceError='';$preview=null;$detail=null;$currentUrl=rss_default_url();$inputUrl=$currentUrl;
$cache=['items'=>[],'updated_at'=>'','checked_at'=>'','error'=>'','title'=>'Внешний источник'];$items=[];$p=exchange_params([]);
try {
    $candidate=$_GET['rss_url']??($_SESSION['rss_url']??$currentUrl);if(!is_string($candidate))throw new InvalidArgumentException('Проверь адрес RSS-ленты.');
    $currentUrl=rss_validate_url($candidate);$inputUrl=$currentUrl;
    if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='source'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['rss_csrf'],$_POST['csrf'])){http_response_code(403);throw new InvalidArgumentException('Обнови страницу и снова подключи ленту.');}
        if(isset($_POST['site_default'])&&empty($_SESSION['admin'])){http_response_code(401);throw new InvalidArgumentException('Основную ленту сайта может сохранить только администратор.');}
        $rawUrl=$_POST['rss_url']??null;if(!is_string($rawUrl))throw new InvalidArgumentException('Введи адрес RSS-ленты.');$inputUrl=$rawUrl;$newUrl=rss_validate_url($rawUrl);
        $hits=array_values(array_filter($_SESSION['rss_connect_hits']??[],static fn($t)=>$t>time()-60));if(count($hits)>=5)throw new InvalidArgumentException('Подключено несколько источников подряд. Повтори через минуту.');$hits[]=time();$_SESSION['rss_connect_hits']=$hits;
        $newCache=rss_external(false,null,$newUrl);
        if($newCache['updated_at']==='')throw new InvalidArgumentException('Ленту не удалось подключить. Проверь, что по адресу доступен RSS 2.0 размером до 512 КБ. Прежний источник сохранён.');
        if(isset($_POST['site_default']))rss_save_default($newUrl);
        $_SESSION['rss_url']=$newUrl;header('Location: rss.html?'.http_build_query(['rss_url'=>$newUrl],'','&',PHP_QUERY_RFC3986),true,303);exit;
    }
}catch(InvalidArgumentException $ex){if(http_response_code()===200)http_response_code(422);$sourceError=$ex->getMessage();}
catch(Throwable $ex){http_response_code(503);$sourceError='Не удалось сохранить источник. Повтори позже.';error_log('MIXLAB RSS source change failed');}
try{
    $p=exchange_params($_GET);$own=exchange_own(db());
    try{$cache=rss_external(($_GET['refresh']??'')==='1',null,$currentUrl,$p['source']!=='own');if(isset($_GET['rss_url'])&&$cache['updated_at']!=='')$_SESSION['rss_url']=$currentUrl;}catch(Throwable $ex){$cache['error']='Внешнюю ленту пока не удалось загрузить.';error_log('MIXLAB RSS cache unavailable');}
    if(isset($_GET['item'])){
        if(!is_string($_GET['item'])||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$_GET['item']))throw new InvalidArgumentException('Некорректный адрес публикации.');
        foreach($own as $item)if($item['id']===$_GET['item']){$detail=$item;break;}
        if(!$detail){http_response_code(404);$error='Публикация не найдена. Выбери другую новость в ленте.';}
    }
    $items=exchange_select(array_merge($own,$cache['items']),$p);
}catch(InvalidArgumentException $ex){http_response_code(422);$error=$ex->getMessage();}
catch(Throwable $ex){http_response_code(503);$error='Не удалось загрузить новости. Повтори позже.';error_log('MIXLAB RSS page: '.$ex->getMessage());}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'upload')==='upload'){
    try{
        if((int)($_SERVER['CONTENT_LENGTH']??0)>RSS_MAX_BYTES+16384){http_response_code(413);throw new InvalidArgumentException('Файл слишком большой. Максимум — 512 КБ.');}
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['rss_csrf'],$_POST['csrf'])){http_response_code(403);throw new InvalidArgumentException('Обнови страницу и снова выбери файл.');}
        $file=$_FILES['rss_file']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Выбери доступный RSS-файл размером до 512 КБ.');
        $raw=file_get_contents($file['tmp_name'],false,null,0,RSS_MAX_BYTES+1);$preview=rss_parse($raw===false?'':$raw);$preview['items']=exchange_select($preview['items'],exchange_params([]));
    }catch(InvalidArgumentException $ex){if(http_response_code()===200)http_response_code(422);$uploadError=$ex->getMessage();}
    catch(Throwable $ex){http_response_code(422);$uploadError='Не удалось прочитать RSS-файл.';}
}
$total=count($items);$pages=max(1,(int)ceil($total/12));$page=min($p['page'],$pages);$visible=array_slice($items,($page-1)*12,12);
$url=static fn(array $changes):string=>'rss.html?'.http_build_query(array_merge($p,['rss_url'=>$currentUrl],$changes),'','&',PHP_QUERY_RFC3986);
function rss_cards(array $items,callable $e,callable $fmtdate):void { ?>
<div class="rss-grid"><?php foreach($items as $x): ?><article class="rss-item"<?= $x['origin']==='own'?' id="news-'.$e($x['id']).'"':'' ?> data-origin="<?= $e($x['origin']) ?>"><div class="rss-meta"><span class="rss-source"><?= $e($x['source']) ?></span><?php if($x['published_at']!==''): ?><time datetime="<?= $e($x['published_at']) ?>"><?= $e($fmtdate($x['published_at'])) ?></time><?php else: ?><span>Дата не указана</span><?php endif; ?></div><h3><?php if($x['link']!==''): ?><a href="<?= $e($x['link']) ?>"<?= $x['origin']!=='own'?' rel="noopener noreferrer"':'' ?>><?= $e($x['title']) ?></a><?php else: ?><?= $e($x['title']) ?><?php endif; ?></h3><?php if($x['description']!==''): ?><p><?= $e(exchange_text($x['description'],250)) ?><?= preg_match_all('/./us',$x['description'])>250?'…':'' ?></p><?php endif; ?><?php if($x['link']!==''): ?><a class="text-link" href="<?= $e($x['link']) ?>"<?= $x['origin']!=='own'?' rel="noopener noreferrer"':'' ?>><?= $x['origin']==='own'?'Читать новость':'Открыть источник' ?> →</a><?php endif; ?></article><?php endforeach; ?></div>
<?php }
ob_start(); ?>
<div class="rss-toolbar"><a class="btn primary" href="feed.php">RSS-подписка MIXLAB</a><a class="btn" href="<?= $e($url(['refresh'=>'1'])) ?>">Обновить ленту</a></div>
<section class="rss-settings" aria-labelledby="source-heading"><h2 id="source-heading">Внешняя RSS-лента</h2><p>Вставь адрес RSS 2.0 и подключи источник. Его новости появятся вместе с публикациями MIXLAB. Выбранная лента запоминается в этом браузере на время сеанса.</p>
<form action="rss.html" method="post" class="rss-connect"><input type="hidden" name="action" value="source"><input type="hidden" name="csrf" value="<?= $e($_SESSION['rss_csrf']) ?>"><label for="rss-url">Адрес RSS-ленты<input id="rss-url" name="rss_url" type="url" required maxlength="2048" value="<?= $e($inputUrl) ?>" placeholder="https://example.org/rss.xml"></label><button class="btn primary" type="submit">Подключить ленту</button><?php if(!empty($_SESSION['admin'])): ?><label class="rss-default"><input type="checkbox" name="site_default" value="1">Сделать основной лентой сайта для всех посетителей</label><?php endif; ?></form>
<?php if($sourceError): ?><p class="notice" role="alert"><?= $e($sourceError) ?></p><?php endif; ?>
<p class="rss-status">Примеры: <a class="text-link" href="<?= $e('rss.html?'.http_build_query(['rss_url'=>RSS_SOURCE_URL])) ?>">Sound On Sound</a> · <a class="text-link" href="<?= $e('rss.html?'.http_build_query(['rss_url'=>'https://feeds.bbci.co.uk/news/technology/rss.xml'])) ?>">BBC Technology</a>. Основной источник: <a class="text-link" href="<?= $e('rss.html?'.http_build_query(['rss_url'=>rss_default_url()])) ?>">Открыть основную ленту</a>.</p></section>
<h2>Лента новостей</h2><p class="rss-status">Публикации MIXLAB и заголовки <strong id="active-rss-title"><?= $e($cache['title']) ?></strong>. Адрес источника: <a class="text-link" id="active-rss-url" href="<?= $e($currentUrl) ?>"><?= $e($currentUrl) ?></a>. Внешние публикации открываются на сайте источника.</p>
<?php if($cache['updated_at']!==''): ?><p class="rss-status">Внешняя лента обновлена: <time datetime="<?= $e($cache['updated_at']) ?>"><?= $e($fmtdate($cache['updated_at'])) ?></time> МСК. Проверено: <?= $e($fmtdate($cache['checked_at'])) ?>. Автопроверка — раз в 15 минут при открытии страницы, ручная — не чаще раза в минуту.</p><?php endif; ?>
<?php if($cache['error']): ?><p class="notice" role="status"><?= $e($cache['updated_at']!==''?$cache['error']:'Внешний источник пока недоступен. Новости MIXLAB доступны ниже.') ?></p><?php endif; ?>
<?php if($error): ?><p class="notice" role="alert"><?= $e($error) ?></p><?php endif; ?>
<form class="rss-filter" action="rss.html" method="get"><input type="hidden" name="rss_url" value="<?= $e($currentUrl) ?>"><label for="rss-query">Поиск по новостям<input id="rss-query" name="q" type="search" maxlength="120" value="<?= $e($p['q']) ?>" placeholder="Например, вокал или компрессор"></label><label for="rss-source">Источник<select id="rss-source" name="source"><?php foreach(['all'=>'Все источники','own'=>'MIXLAB','external'=>$cache['title']] as $key=>$label): ?><option value="<?= $key ?>"<?= $p['source']===$key?' selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><label for="rss-sort">Порядок<select id="rss-sort" name="sort"><?php foreach(['newest'=>'Сначала новые','oldest'=>'Сначала старые','title'=>'По заголовку'] as $key=>$label): ?><option value="<?= $key ?>"<?= $p['sort']===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><div class="actions"><button class="btn primary" type="submit">Показать</button><a class="btn" href="<?= $e($url(['source'=>'all','q'=>'','sort'=>'newest','page'=>1])) ?>">Сбросить</a></div></form>
<p class="rss-count">Найдено новостей: <span id="rss-total"><?= $total ?></span></p>
<?php if($detail): ?><article class="rss-detail" id="news-<?= $e($detail['id']) ?>"><p class="rss-meta">MIXLAB · <?= $e($fmtdate($detail['published_at'])) ?> МСК · <?= $e($detail['category']) ?></p><h2><?= $e($detail['title']) ?></h2><p class="news-body"><?= $e($detail['description']) ?></p><a class="btn" href="<?= $e($url([])) ?>">← Ко всем новостям</a></article>
<?php else: ?>
<?php if(!$items): ?><p class="notice">Новостей по этим условиям пока нет. Попробуй изменить поиск или источник.</p><?php else:rss_cards($visible,$e,$fmtdate);endif; ?>
<?php if($pages>1): ?><nav class="rss-pagination" aria-label="Страницы новостей"><?php if($page>1): ?><a class="btn" href="<?= $e($url(['page'=>$page-1])) ?>">← Назад</a><?php endif; ?><span>Страница <?= $page ?> из <?= $pages ?></span><?php if($page<$pages): ?><a class="btn" href="<?= $e($url(['page'=>$page+1])) ?>">Далее →</a><?php endif; ?></nav><?php endif; ?>
<?php endif; ?>
<div class="rss-tools"><section aria-labelledby="rss-export"><h2 id="rss-export">Скачать новости</h2><p>Скачивается вся выборка с текущими поиском, источником и порядком.</p><div class="actions"><?php foreach(['rss'=>'RSS','csv'=>'CSV','xml'=>'XML','txt'=>'TXT','wrapped'=>'XML + TXT'] as $format=>$label):$query=http_build_query(['format'=>$format,'rss_url'=>$currentUrl,'source'=>$p['source'],'q'=>$p['q'],'sort'=>$p['sort'],'download'=>'1'],'','&',PHP_QUERY_RFC3986); ?><a class="btn" href="feed.php?<?= $e($query) ?>"><?= $label ?></a><?php endforeach; ?></div><details><summary>Какой формат выбрать</summary><p>RSS — для новостного приложения. CSV — для таблиц, с разделителем «;» и кодировкой UTF-8. XML — отдельные поля каждой новости. TXT — текст ASCII с названиями полей и табуляцией; кириллица и переносы записаны обратимыми JSON-последовательностями. XML + TXT — тот же текст с описанием полей внутри XML.</p></details><details><summary>Адрес для RSS-приложения</summary><code><?= $e(NEWS_BASE.'feed.php') ?></code><p>Эта подписка содержит только собственные новости MIXLAB.</p></details></section>
<section aria-labelledby="rss-file"><h2 id="rss-file">Открыть RSS-файл</h2><p>Выбери RSS 2.0, чтобы увидеть новости из файла. Файл используется только для просмотра и не публикуется в общей ленте.</p><form class="rss-upload" action="<?= $e('rss.html?'.http_build_query(['rss_url'=>$currentUrl]).'#rss-preview') ?>" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $e($_SESSION['rss_csrf']) ?>"><label for="rss-file-input">RSS или XML, до 512 КБ<input id="rss-file-input" name="rss_file" type="file" accept=".xml,.rss,application/rss+xml,application/xml,text/xml" required></label><button class="btn" type="submit">Открыть файл</button></form></section></div>
<?php if($uploadError||$preview!==null): ?><section class="rss-preview" id="rss-preview" aria-labelledby="preview-title"><h2 id="preview-title">Новости из файла</h2><?php if($uploadError): ?><p class="notice" role="alert"><?= $e($uploadError) ?></p><?php else: ?><p><?= $e($preview['title']?:'RSS-лента') ?>. Записей: <?= count($preview['items']) ?>.<?php if($preview['skipped']): ?> Пропущено пустых записей и повторов: <?= $preview['skipped'] ?>.<?php endif; ?></p><?php if(count($preview['items'])>50): ?><p class="small">Показаны первые 50 записей из файла.</p><?php endif;rss_cards(array_slice($preview['items'],0,50),$e,$fmtdate);endif; ?></section><?php else: ?><div id="rss-preview"></div><?php endif; ?>
<?php
$fragment=ob_get_clean();$template=file_get_contents(__DIR__.'/rss.html');$a=strpos($template,'<!-- RSS:START -->');$b=strpos($template,'<!-- RSS:END -->');echo substr($template,0,$a+strlen('<!-- RSS:START -->')).$fragment.substr($template,$b);
