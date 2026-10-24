<?php
declare(strict_types=1);
ini_set('display_errors','0');require __DIR__.'/server/exchange.php';require_once __DIR__.'/server/admin_auth.php';
header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);session_start();$_SESSION['rss_csrf']??=bin2hex(random_bytes(24));
$e=static fn($s):string=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$fmtdate=static function(string $date):string {return $date===''?'Дата не указана':(new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');};
$error='';$uploadError='';$sourceError='';$preview=null;$detail=null;$inputUrl='';$selectedUrl='';$feeds=[];$items=[];$p=exchange_params([]);
$notice=$_SESSION['rss_notice']??'';unset($_SESSION['rss_notice']);
try {
    $action=$_POST['action']??'upload';
    if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['source','remove_source','login','logout'],true)){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['rss_csrf'],$_POST['csrf'])){http_response_code(403);throw new InvalidArgumentException('Обнови страницу и повтори действие.');}
        if($action==='logout'){$_SESSION['admin']=false;$_SESSION['csrf']=bin2hex(random_bytes(24));$_SESSION['rss_csrf']=bin2hex(random_bytes(24));header('Location: rss.html',true,303);exit;}
        if(empty($_SESSION['admin'])){
            if($failure=admin_login($_POST['password']??null)){http_response_code($failure[1]);throw new InvalidArgumentException($failure[0]);}
        }
        if($action==='login'){header('Location: rss.html#source-heading',true,303);exit;}
        $rawUrl=$_POST['rss_url']??null;if(!is_string($rawUrl))throw new InvalidArgumentException('Введи адрес RSS-ленты.');$inputUrl=$rawUrl;$newUrl=rss_validate_url($rawUrl);
        if($action==='remove_source'){
            rss_remove_source($newUrl);$_SESSION['rss_notice']='Источник отключён. Его новости больше не входят в общую ленту.';
        }else{
            if(in_array($newUrl,array_column(rss_sources(),'url'),true))throw new InvalidArgumentException('Эта RSS-лента уже подключена.');
            $newCache=rss_external(false,null,$newUrl);
            if($newCache['updated_at']==='')throw new InvalidArgumentException('Ленту не удалось подключить. Проверь, что по адресу доступен RSS 2.0 размером до 512 КБ. Список источников не изменён.');
            rss_add_source($newUrl,$newCache['title']);$_SESSION['rss_notice']='RSS-лента подключена для всех посетителей и сохранена до её удаления администратором.';
        }
        header('Location: rss.html#source-heading',true,303);exit;
    }
}catch(InvalidArgumentException $ex){if(http_response_code()===200)http_response_code(422);$sourceError=$ex->getMessage();}
catch(Throwable $ex){http_response_code(503);$sourceError='Не удалось сохранить источник. Повтори позже.';error_log('MIXLAB RSS source change failed');}
try{
    $p=exchange_params($_GET);$own=exchange_own(db());
    $collection=rss_collection(($_GET['refresh']??'')==='1',$p['source']!=='own');$feeds=$collection['feeds'];$external=$collection['items'];
    if(isset($_GET['rss_url'])&&$_GET['rss_url']!==''){
        if(!is_string($_GET['rss_url']))throw new InvalidArgumentException('Проверь адрес RSS-ленты.');
        $selectedUrl=rss_validate_url($_GET['rss_url']);
        if(!in_array($selectedUrl,array_column($feeds,'url'),true))throw new InvalidArgumentException('Эта RSS-лента не подключена. Выбери источник из списка.');
        foreach($feeds as $feed)if($feed['url']===$selectedUrl){$external=$feed['items'];break;}
    }
    if(isset($_GET['item'])){
        if(!is_string($_GET['item'])||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$_GET['item']))throw new InvalidArgumentException('Некорректный адрес публикации.');
        foreach($own as $item)if($item['id']===$_GET['item']){$detail=$item;break;}
        if(!$detail){http_response_code(404);$error='Публикация не найдена. Выбери другую новость в ленте.';}
    }
    $items=exchange_select(array_merge($own,$external),$p);
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
$total=count($items);
$url=static fn(array $changes):string=>'rss.html?'.http_build_query(array_merge($p,['rss_url'=>$selectedUrl],$changes),'','&',PHP_QUERY_RFC3986);
function rss_cards(array $items,callable $e,callable $fmtdate):void { ?>
<div class="rss-grid"><?php foreach($items as $x): ?><article class="rss-item"<?= $x['origin']==='own'?' id="news-'.$e($x['id']).'"':'' ?> data-origin="<?= $e($x['origin']) ?>"><div class="rss-meta"><span class="rss-source"><?= $e($x['source']) ?></span><?php if($x['published_at']!==''): ?><time datetime="<?= $e($x['published_at']) ?>"><?= $e($fmtdate($x['published_at'])) ?></time><?php else: ?><span>Дата не указана</span><?php endif; ?></div><h3><?php if($x['link']!==''): ?><a href="<?= $e($x['link']) ?>"<?= $x['origin']!=='own'?' rel="noopener noreferrer"':'' ?>><?= $e($x['title']) ?></a><?php else: ?><?= $e($x['title']) ?><?php endif; ?></h3><?php if($x['description']!==''): ?><p class="news-body"><?= $e($x['description']) ?></p><?php endif; ?><?php if($x['link']!==''&&$x['origin']!=='own'): ?><a class="text-link" href="<?= $e($x['link']) ?>" rel="noopener noreferrer">Открыть источник →</a><?php endif; ?></article><?php endforeach; ?></div>
<?php }
ob_start(); ?>
<div class="rss-toolbar"><a class="btn primary" href="#news-list">Общая лента</a><a class="btn" href="#rss-export">Скачать новости ↓</a><a class="btn" href="#rss-file">Открыть RSS-файл ↓</a><a class="btn" href="feed.php">RSS-подписка MIXLAB</a><a class="btn" href="<?= $e($url(['refresh'=>'1'])) ?>">Обновить ленту</a></div>
<section class="rss-settings" aria-labelledby="source-heading"><h2 id="source-heading">Внешние RSS-ленты</h2><p>Подключённые источники сохраняются на сайте для всех посетителей до удаления администратором. Их новости дополняют публикации MIXLAB.</p>
<form action="rss.html#source-heading" method="post" class="rss-connect"><input type="hidden" name="csrf" value="<?= $e($_SESSION['rss_csrf']) ?>"><label for="rss-url">Адрес RSS-ленты<input id="rss-url" name="rss_url" type="url" required maxlength="2048" value="<?= $e($inputUrl) ?>" placeholder="https://example.org/rss.xml"></label><?php if(empty($_SESSION['admin'])): ?><label for="rss-password">Пароль администратора<input id="rss-password" name="password" type="password" autocomplete="current-password" required></label><?php endif; ?><button class="btn primary" name="action" value="source" type="submit">Подключить ленту</button><?php if(empty($_SESSION['admin'])): ?><button class="btn" name="action" value="login" type="submit" formnovalidate>Войти для управления</button><?php endif; ?></form>
<?php if($sourceError): ?><p class="notice" role="alert"><?= $e($sourceError) ?></p><?php endif; ?>
<?php if($notice): ?><p class="notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
<?php if(!empty($_SESSION['admin'])): ?><div class="rss-admin-actions"><a class="text-link" href="admin/news.html">Управление публикациями →</a><form action="rss.html" method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= $e($_SESSION['rss_csrf']) ?>"><button class="btn" type="submit">Выйти из управления</button></form></div><?php endif; ?>
<div class="rss-sources" id="connected-rss-sources"><?php foreach($feeds as $feed): ?><article class="rss-source-row"><h3><?= $e($feed['title']) ?></h3><a class="text-link" href="<?= $e($feed['url']) ?>" rel="noopener noreferrer"><?= $e($feed['url']) ?></a><p class="rss-status"><?php if($feed['updated_at']!==''): ?>Обновлено: <?= $e($fmtdate($feed['updated_at'])) ?> МСК. Проверено: <?= $e($fmtdate($feed['checked_at'])) ?>.<?php endif; ?><?php if($feed['error']): ?> <?= $e($feed['error']) ?><?php endif; ?></p><?php if(!empty($_SESSION['admin'])): ?><form action="rss.html#source-heading" method="post"><input type="hidden" name="action" value="remove_source"><input type="hidden" name="csrf" value="<?= $e($_SESSION['rss_csrf']) ?>"><input type="hidden" name="rss_url" value="<?= $e($feed['url']) ?>"><button class="btn" type="submit">Удалить источник «<?= $e($feed['title']) ?>»</button></form><?php endif; ?></article><?php endforeach; ?><?php if(!$feeds): ?><p>Внешние источники пока не подключены. В ленте доступны публикации MIXLAB.</p><?php endif; ?></div>
<p class="rss-status">Автопроверка источников — раз в 15 минут при открытии страницы, ручная — не чаще раза в минуту. При временной ошибке показывается сохранённая копия новостей.</p></section>
<section id="news-list" class="news-list" aria-labelledby="news-list-title"><h2 id="news-list-title">Общая лента новостей</h2><p class="rss-status">Новости MIXLAB и подключённых RSS-источников собраны в одном списке и отсортированы <?= $e(['newest'=>'по дате — сначала новые','oldest'=>'по дате — сначала старые','title'=>'по заголовку'][$p['sort']]) ?>. Внешние публикации открываются на сайте источника.</p>
<?php if($error): ?><p class="notice" role="alert"><?= $e($error) ?></p><?php endif; ?>
<form class="rss-filter" action="rss.html" method="get"><label for="rss-query">Поиск по новостям<input id="rss-query" name="q" type="search" maxlength="120" value="<?= $e($p['q']) ?>" placeholder="Например, вокал или компрессор"></label><label for="rss-source">Источник<select id="rss-source" name="source"><?php foreach(['all'=>'Все источники','own'=>'MIXLAB','external'=>'Все внешние источники'] as $key=>$label): ?><option value="<?= $key ?>"<?= $p['source']===$key?' selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><label for="rss-feed">RSS-лента<select id="rss-feed" name="rss_url"><option value="">Все подключённые ленты</option><?php foreach($feeds as $feed): ?><option value="<?= $e($feed['url']) ?>"<?= $selectedUrl===$feed['url']?' selected':'' ?>><?= $e($feed['title']) ?></option><?php endforeach; ?></select></label><label for="rss-sort">Порядок<select id="rss-sort" name="sort"><?php foreach(['newest'=>'Сначала новые','oldest'=>'Сначала старые','title'=>'По заголовку'] as $key=>$label): ?><option value="<?= $key ?>"<?= $p['sort']===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><div class="actions"><button class="btn primary" type="submit">Показать</button><a class="btn" href="<?= $e($url(['source'=>'all','q'=>'','sort'=>'newest','page'=>1,'rss_url'=>''])) ?>">Сбросить</a></div></form>
<p class="rss-count">Найдено новостей: <span id="rss-total"><?= $total ?></span></p>
<?php if(!$items): ?><p class="notice">Новостей по этим условиям пока нет. Попробуй изменить поиск или источник.</p><?php else:rss_cards($items,$e,$fmtdate);endif; ?>
</section>
<div class="rss-tools"><section aria-labelledby="rss-export"><h2 id="rss-export">Скачать новости</h2><p>Скачивается вся выборка с текущими поиском, источником и порядком.</p><div class="actions"><?php foreach(['rss'=>'RSS','csv'=>'CSV','xml'=>'XML','txt'=>'TXT','wrapped'=>'XML + TXT'] as $format=>$label):$query=http_build_query(['format'=>$format,'rss_url'=>$selectedUrl,'source'=>$p['source'],'q'=>$p['q'],'sort'=>$p['sort'],'download'=>'1'],'','&',PHP_QUERY_RFC3986); ?><a class="btn" href="feed.php?<?= $e($query) ?>"><?= $label ?></a><?php endforeach; ?></div><details><summary>Какой формат выбрать</summary><p>RSS — для новостного приложения. CSV — для таблиц, с разделителем «;» и кодировкой UTF-8. XML — отдельные поля каждой новости. TXT — текст ASCII с названиями полей и табуляцией; кириллица и переносы записаны обратимыми JSON-последовательностями. XML + TXT — тот же текст с описанием полей внутри XML.</p></details><details><summary>Адрес для RSS-приложения</summary><code><?= $e(NEWS_BASE.'feed.php') ?></code><p>Эта подписка содержит только собственные новости MIXLAB.</p></details></section>
<section aria-labelledby="rss-file"><h2 id="rss-file">Открыть RSS-файл</h2><p>Выбери RSS 2.0, чтобы увидеть новости из файла. Файл используется только для просмотра и не публикуется в общей ленте.</p><form class="rss-upload" action="<?= $e('rss.html?'.http_build_query(['rss_url'=>$selectedUrl]).'#rss-preview') ?>" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $e($_SESSION['rss_csrf']) ?>"><label for="rss-file-input">RSS или XML, до 512 КБ<input id="rss-file-input" name="rss_file" type="file" accept=".xml,.rss,application/rss+xml,application/xml,text/xml" required></label><button class="btn" type="submit">Открыть файл</button></form></section></div>
<?php if($uploadError||$preview!==null): ?><section class="rss-preview" id="rss-preview" aria-labelledby="preview-title"><h2 id="preview-title">Новости из файла</h2><?php if($uploadError): ?><p class="notice" role="alert"><?= $e($uploadError) ?></p><?php else: ?><p><?= $e($preview['title']?:'RSS-лента') ?>. Записей: <?= count($preview['items']) ?>.<?php if($preview['skipped']): ?> Пропущено пустых записей и повторов: <?= $preview['skipped'] ?>.<?php endif; ?></p><?php if(count($preview['items'])>50): ?><p class="small">Показаны первые 50 записей из файла.</p><?php endif;rss_cards(array_slice($preview['items'],0,50),$e,$fmtdate);endif; ?></section><?php else: ?><div id="rss-preview"></div><?php endif; ?>
<?php
$fragment=ob_get_clean();$template=file_get_contents(__DIR__.'/rss.html');$a=strpos($template,'<!-- RSS:START -->');$b=strpos($template,'<!-- RSS:END -->');echo substr($template,0,$a+strlen('<!-- RSS:START -->')).$fragment.substr($template,$b);
