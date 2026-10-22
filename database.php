<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/server/analytics.php';
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$e=static fn($s):string=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$titles=['search'=>'Поиск по категории','filter'=>'Категория и автор','categories'=>'Сводка по категориям','timeline'=>'Динамика публикаций','ratings'=>'Распределение оценок'];
$error='';$result=null;
try {$p=analytics_params($_GET);$result=analytics_run(community_db(),$p);}
catch(InvalidArgumentException $ex){http_response_code(422);$error=$ex->getMessage();$p=analytics_params([]);}
catch(Throwable $ex){http_response_code(503);error_log('MIXLAB analytics: '.$ex->getMessage());$error='Не удалось получить данные из базы. Повтори запрос позже.';$p=analytics_params([]);}
$link=static fn(array $changes):string=>'database.html?'.http_build_query(array_merge($p,$changes),'','&',PHP_QUERY_RFC3986);
ob_start();
?>
<div class="database-actions actions"><a class="btn primary" href="admin/news.html">Добавить публикацию</a><a class="btn" href="#database-info">О базе данных</a><a class="btn" href="#database-authors">Авторы</a></div>
<?php if($result):$meta=$result['metadata']; ?>
<section class="database-info" id="database-info" aria-labelledby="database-info-title"><h2 id="database-info-title">О базе данных MIXLAB</h2>
<p>Каталог публикаций о сведении музыки и обновлений проекта. Данные хранятся на сервере в SQLite. Публикации и сведения об авторах связаны между собой; внешние новости Sound On Sound обрабатываются отдельно в RSS и в этот объём не входят.</p>
<dl class="database-facts"><div><dt>Публикаций</dt><dd id="database-count"><?= (int)$meta['publications'] ?></dd></div><div><dt>Авторских профилей</dt><dd><?= (int)$meta['authors'] ?></dd></div><div><dt>Категорий</dt><dd><?= (int)$meta['categories'] ?></dd></div><div><dt>Объём общей БД</dt><dd><?= number_format($meta['bytes']/1024,1,',',' ') ?> КБ</dd></div></dl>
<p><strong>Состав:</strong> заголовок, текст, дата публикации, категория, источник и автор. Таблица авторов содержит имя, роль и краткое описание. Общая БД также хранит обсуждения, оценки и статистику посещений; указанный размер относится ко всему файлу БД.</p>
<p><strong>Полнота:</strong> обязательные поля и связь с автором заполнены у <?= (int)$meta['complete'] ?> из <?= (int)$meta['publications'] ?> публикаций. Это полнота заполнения записей; каталог не претендует на охват всех тем звукорежиссуры.</p>
<p><strong>Актуальность:</strong> последняя публикация — <?= $meta['last_date']?$e((new DateTimeImmutable($meta['last_date']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i')).' МСК':'пока нет' ?>. Счётчики и результаты читаются из БД при открытии страницы; новые записи появляются после публикации администратором.</p>
<p><strong>Автор базы и проекта:</strong> Калиниченко Владислав Дмитриевич. <a class="text-link" href="admin/news.html">Ввод данных → Управление новостями</a> (после входа администратора).</p></section>
<?php endif; ?>
<nav class="report-tabs" aria-label="Поиск и отчёты">
<?php $i=0;foreach($titles as $key=>$title):$i++; ?>
<a class="btn" href="database.html?report=<?= $e($key) ?>"<?= $p['report']===$key?' aria-current="page"':'' ?>><?= $i ?>. <?= $e($title) ?></a>
<?php endforeach; ?>
</nav>
<?php if($error): ?><p class="notice" role="alert"><?= $e($error) ?></p><?php endif; ?>
<form class="report-form" action="database.html" method="get">
<input type="hidden" name="report" value="<?= $e($p['report']) ?>">
<?php if($p['report']==='search'): ?>
<label class="wide" for="db-query">Дополнительный поиск по тексту (необязательно)<input id="db-query" name="q" type="search" maxlength="120" value="<?= $e($p['q']) ?>" placeholder="Например, инструменты"></label>
<?php endif; ?>
<?php if($p['report']!=='ratings'): ?>
<?php if($p['report']==='filter'): ?><p class="small wide" id="linked-help">Категория и автор связаны: списки показывают только сочетания, для которых есть публикации. Чтобы расширить выбор, верни «Все категории» или «Все авторы». Без JavaScript используй «Обновить списки».</p><?php endif; ?>
<label for="db-category">Категория<select id="db-category" name="category"><option value="">Все категории</option><?php foreach($result['categories']??[] as $cat): ?><option value="<?= $e($cat) ?>"<?= $p['category']===$cat?' selected':'' ?>><?= $e($cat) ?></option><?php endforeach; ?></select></label>
<?php if($p['report']==='filter'): ?><label for="db-author">Автор<select id="db-author" name="author" aria-describedby="linked-help"><option value="">Все авторы</option><?php foreach($result['authors']??[] as $author): ?><option value="<?= $e($author['id']) ?>"<?= $p['author']===$author['id']?' selected':'' ?>><?= $e($author['name']) ?></option><?php endforeach; ?></select></label><button class="btn" type="submit" name="lists" value="1">Обновить списки</button><?php endif; ?>
<?php if(in_array($p['report'],['search','filter'],true)): ?><label for="db-sort">Сортировка<select id="db-sort" name="sort"><?php foreach(['newest'=>'Сначала новые','oldest'=>'Сначала старые','title'=>'По заголовку','category'=>'По категории'] as $key=>$label): ?><option value="<?= $key ?>"<?= $p['sort']===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label for="db-from">С даты включительно<input id="db-from" name="from" type="date" value="<?= $e($p['from']) ?>"></label>
<label for="db-to">По дату включительно<input id="db-to" name="to" type="date" value="<?= $e($p['to']) ?>"></label>
<p class="small wide">Пустые поля не ограничивают выборку.</p>
<?php else: ?><p class="wide">Все действующие оценки посетителей. Здесь показаны только обобщённые данные; поставить оценку можно на <a class="text-link" href="services/rating.html">странице рейтинга</a>.</p><?php endif; ?>
<div class="wide actions"><button class="btn primary" type="submit">Выполнить запрос</button> <a class="btn" href="database.html?report=<?= $e($p['report']) ?>">Сбросить</a></div>
</form>
<?php if($result):$rows=$result['rows']; ?>
<section class="report-result" aria-labelledby="report-title">
<p class="eyebrow">Результат запроса</p><h2 id="report-title"><?= $e($titles[$p['report']]) ?></h2>
<p>Всего <?= $p['report']==='ratings'?'оценок':'публикаций' ?>: <strong id="report-total"><?= $result['total'] ?></strong>. <span class="small">Получено <?= (new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i:s') ?> МСК.</span></p>
<?php if($result['total']===0): ?><p class="notice">По этому запросу данных пока нет. Измени условия или вернись после новых публикаций и оценок.</p><?php endif; ?>
<?php if(in_array($p['report'],['search','filter'],true)&&$rows): ?>
<div class="report-table-wrap"><table class="report-table"><caption>Новости из общей базы сайта</caption><thead><tr><th scope="col">Дата, UTC</th><th scope="col">Публикация</th><th scope="col">Категория</th><th scope="col">Источник</th><th scope="col">Автор</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><time datetime="<?= $e($row['published_at']) ?>"><?= $e(substr($row['published_at'],0,10)) ?></time></td><td><a href="services/news.html#news-<?= $e($row['id']) ?>"><?= $e($row['title']) ?></a><small><?= $e(preg_replace('/^(.{0,200}).*/us','$1',$row['body'])) ?><?= preg_match_all('/./us',$row['body'])>200?'…':'' ?></small></td><td><?= $e($row['category']) ?></td><td><?= $e($row['source']) ?></td><td><a href="#author-<?= $e($row['author_id']) ?>"><?= $e($row['author_name']) ?></a></td></tr><?php endforeach; ?>
</tbody></table></div>
<nav class="report-pagination" aria-label="Страницы результатов"><?php if($result['page']>1): ?><a class="btn" href="<?= $e($link(['page'=>$result['page']-1])) ?>">← Назад</a><?php endif; ?><span>Страница <?= $result['page'] ?> из <?= $result['pages'] ?></span><?php if($result['page']<$result['pages']): ?><a class="btn" href="<?= $e($link(['page'=>$result['page']+1])) ?>">Далее →</a><?php endif; ?></nav>
<?php elseif(!in_array($p['report'],['search','filter'],true)): ?>
<?php if($p['report']==='ratings'):$s=$result['summary']; ?><div class="report-kpis"><div><span>Средняя оценка</span><strong><?= $s['average']===null?'—':number_format((float)$s['average'],2,',',' ') ?> / 5</strong></div><div><span>Минимальная</span><strong><?= $e($s['minimum']??'—') ?></strong></div><div><span>Максимальная</span><strong><?= $e($s['maximum']??'—') ?></strong></div></div><?php endif; ?>
<?php if($rows):$max=max(1,...array_map('intval',array_column($rows,'amount'))); ?>
<?php if($p['report']==='timeline'):$n=count($rows);$points=[];foreach($rows as $j=>$row){$points[]=sprintf('%.2f,%.2f',60+$j*700/max(1,$n-1),215-170*(int)$row['amount']/$max);} ?>
<figure><div class="report-chart-scroll"><svg class="report-line" viewBox="0 0 820 270" role="img" aria-labelledby="timeline-title timeline-desc"><title id="timeline-title">Публикации по месяцам</title><desc id="timeline-desc">Число публикаций за каждый месяц. Точные значения приведены в таблице ниже; пропущенные месяцы имеют ноль публикаций.</desc><path d="M60 35 V215 H760" fill="none" stroke="#91a2ba"/><text x="10" y="50" fill="#c8d3e3"><?= $max ?></text><text x="30" y="220" fill="#c8d3e3">0</text><polyline points="<?= implode(' ',$points) ?>" fill="none" stroke="#89e7e0" stroke-width="3"/><?php foreach($points as $point):[$x,$y]=explode(',',$point); ?><circle cx="<?= $x ?>" cy="<?= $y ?>" r="4" fill="#b5a3ff"/><?php endforeach; ?><text x="60" y="250" fill="#c8d3e3"><?= $e($rows[0]['label']) ?></text><text x="760" y="250" text-anchor="end" fill="#c8d3e3"><?= $e(end($rows)['label']) ?></text></svg></div><figcaption>По горизонтали — месяцы, по вертикали — количество публикаций.</figcaption></figure>
<?php else: ?><div class="report-bars" role="group" aria-label="Количество по группам"><?php foreach($rows as $row): ?><div><div class="report-bar-label"><span><?= $e($row['label']) ?><?= $p['report']==='ratings'?' / 5':'' ?></span><strong><?= (int)$row['amount'] ?></strong></div><div class="report-track" aria-hidden="true"><div class="report-fill" style="width:<?= round((int)$row['amount']/$max*100,2) ?>%"></div></div></div><?php endforeach; ?></div><?php endif; ?>
<div class="report-table-wrap"><table class="report-table"><caption>Точные значения диаграммы</caption><thead><tr><th scope="col"><?= ['categories'=>'Категория','timeline'=>'Месяц, UTC','ratings'=>'Оценка'][$p['report']] ?></th><th scope="col">Количество</th><th scope="col">Доля</th><?php if($p['report']==='categories'): ?><th scope="col">Первая публикация</th><th scope="col">Последняя публикация</th><?php endif; ?></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= $e($row['label']) ?></td><td><?= (int)$row['amount'] ?></td><td><?= number_format($result['total']?(int)$row['amount']/$result['total']*100:0,1,',',' ') ?>%</td><?php if($p['report']==='categories'): ?><td><?= $e($row['first_date']) ?></td><td><?= $e($row['last_date']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div>
<?php endif;endif; ?>
<details><summary>SQL этого запроса</summary><pre class="report-sql"><code><?= $e($result['sql']) ?></code></pre><p>Параметры передаются отдельно от текста SQL. Запрос выполняется на сервере при отправке формы.</p></details>
</section>
<?php endif; ?>
<?php if($result): ?><section class="database-authors" id="database-authors" aria-labelledby="authors-heading"><h2 id="authors-heading">Авторы публикаций</h2><div class="grid grid-2"><?php foreach($result['author_profiles'] as $author): ?><article class="card" id="author-<?= $e($author['id']) ?>"><h3><?= $e($author['name']) ?></h3><p class="small"><?= $e($author['role']) ?></p><p><?= $e($author['bio']) ?></p><a class="text-link" href="database.html?<?= $e(http_build_query(['report'=>'filter','author'=>$author['id']])) ?>">Публикации автора →</a></article><?php endforeach; ?></div></section>
<?php if($p['report']==='filter'): ?><script type="application/json" id="database-pairs"><?= json_encode($result['pairs'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><?php endif; ?>
<?php endif;
$fragment=ob_get_clean();$template=file_get_contents(__DIR__.'/database.html');
$a=strpos($template,'<!-- DATABASE:START -->');$b=strpos($template,'<!-- DATABASE:END -->');
echo substr($template,0,$a+strlen('<!-- DATABASE:START -->')).$fragment.substr($template,$b);
