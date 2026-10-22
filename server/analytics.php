<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/community.php';
function analytics_params(array $input): array {
    $defaults=['report'=>'search','q'=>'','category'=>'','author'=>'','from'=>'','to'=>'','sort'=>'newest','page'=>'1'];
    $p=[];
    foreach($defaults as $key=>$default) {
        $value=$input[$key]??$default;
        if(!is_string($value)||!preg_match('//u',$value)||strlen($value)>500)throw new InvalidArgumentException('Проверь параметры запроса.');
        $p[$key]=trim($value);
    }
    if(!in_array($p['report'],['search','filter','categories','timeline','ratings'],true))throw new InvalidArgumentException('Выбери один из пяти отчётов.');
    if(!in_array($p['sort'],['newest','oldest','title','category'],true))throw new InvalidArgumentException('Неизвестный порядок сортировки.');
    foreach(['from','to'] as $k)if($p[$k]!=='') {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$p[$k]);
        if(!$date||$date->format('Y-m-d')!==$p[$k])throw new InvalidArgumentException('Укажи корректную дату.');
    }
    if($p['from']!==''&&$p['to']!==''&&$p['from']>$p['to'])throw new InvalidArgumentException('Начало периода должно быть не позже конца.');
    if(!preg_match('/^[1-9][0-9]{0,5}$/D',$p['page']))throw new InvalidArgumentException('Некорректный номер страницы.');
    $p['page']=(int)$p['page'];
    return $p;
}
function analytics_run(PDO $pdo,array $p): array {
    // SQLite's built-in LOWER is ASCII-only. Keep Russian search case-insensitive.
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->sqliteCreateFunction('mix_lower',static function($s){
        return strtolower(strtr((string)$s,array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY))));
    },1);
    $lower=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'mix_lower':'LOWER';
    $where=[];$args=[];
    $join=' FROM news n JOIN authors a ON a.id=n.author_id';
    if($p['report']==='search'&&$p['q']!=='') {
        $like='%'.strtr($p['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $where[]="($lower(n.title) LIKE $lower(?) ESCAPE '!' OR $lower(n.body) LIKE $lower(?) ESCAPE '!' OR $lower(n.category) LIKE $lower(?) ESCAPE '!' OR $lower(n.source) LIKE $lower(?) ESCAPE '!')";
        $args=[$like,$like,$like,$like];
    }
    if($p['report']!=='ratings') {
        if($p['category']!==''){$where[]='n.category=?';$args[]=$p['category'];}
        if($p['report']==='filter'&&$p['author']!==''){$where[]='a.id=?';$args[]=$p['author'];}
        if($p['from']!==''){$where[]='SUBSTR(n.published_at,1,10)>=?';$args[]=$p['from'];}
        if($p['to']!==''){$where[]='SUBSTR(n.published_at,1,10)<=?';$args[]=$p['to'];}
    }
    $clause=$where?' WHERE '.implode(' AND ',$where):'';
    $query=static function(string $sql,array $values=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($values);return $q->fetchAll();};
    $pairs=$pdo->query('SELECT DISTINCT n.category,a.id AS author_id,a.name AS author_name'.$join.' ORDER BY n.category,a.name,a.id')->fetchAll();
    $categories=[];$authors=[];
    foreach($pairs as $pair){
        if($p['report']!=='filter'||$p['author']===''||$p['author']===$pair['author_id'])$categories[$pair['category']]=$pair['category'];
        if($p['category']===''||$p['category']===$pair['category'])$authors[$pair['author_id']]=['id'=>$pair['author_id'],'name'=>$pair['author_name']];
    }
    $categories=array_values($categories);$authors=array_values($authors);
    $result=['categories'=>$categories,'authors'=>$authors,'pairs'=>$pairs,'metadata'=>catalog_metadata($pdo),'author_profiles'=>catalog_authors($pdo),'rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'sql'=>'','params'=>$args,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)];
    if(in_array($p['report'],['search','filter'],true)) {
        $total=(int)$query('SELECT COUNT(*) AS n'.$join.$clause,$args)[0]['n'];
        $pages=max(1,(int)ceil($total/20));$page=min($p['page'],$pages);$offset=($page-1)*20;
        $order=['newest'=>'n.published_at DESC, n.id DESC','oldest'=>'n.published_at ASC, n.id ASC','title'=>'n.title ASC, n.id ASC','category'=>'n.category ASC, n.published_at DESC, n.id ASC'][$p['sort']];
        $sql='SELECT n.id,n.title,n.body,n.published_at,n.category,n.source,a.id AS author_id,a.name AS author_name'.$join.$clause.' ORDER BY '.$order.' LIMIT 20 OFFSET '.$offset;
        return array_merge($result,['rows'=>$query($sql,$args),'total'=>$total,'pages'=>$pages,'page'=>$page,'sql'=>$sql]);
    }
    if($p['report']==='categories')$sql='SELECT n.category AS label, COUNT(*) AS amount, MIN(SUBSTR(n.published_at,1,10)) AS first_date, MAX(SUBSTR(n.published_at,1,10)) AS last_date'.$join.$clause.' GROUP BY n.category ORDER BY amount DESC, n.category ASC';
    elseif($p['report']==='timeline')$sql='SELECT SUBSTR(n.published_at,1,7) AS label, COUNT(*) AS amount'.$join.$clause.' GROUP BY SUBSTR(n.published_at,1,7) ORDER BY label ASC';
    else {
        $sql='SELECT score AS label, COUNT(*) AS amount FROM site_ratings GROUP BY score ORDER BY score ASC';$args=[];
        $summary=$query('SELECT COUNT(*) AS votes, AVG(score) AS average, MIN(score) AS minimum, MAX(score) AS maximum FROM site_ratings')[0];
        $result['summary']=$summary;
    }
    $rows=$query($sql,$args);
    if($p['report']==='ratings') {
        $counts=array_column($rows,'amount','label');$rows=[];
        for($i=1;$i<=5;$i++)$rows[]=['label'=>(string)$i,'amount'=>(int)($counts[$i]??0)];
    }
    // Show gaps as zero in the monthly chart, rather than implying missing months do not exist.
    if($p['report']==='timeline'&&$rows) {
        $counts=array_column($rows,'amount','label');$first=new DateTimeImmutable($rows[0]['label'].'-01');$last=new DateTimeImmutable(end($rows)['label'].'-01');
        $rows=[];
        for($d=$first;$d<=$last;$d=$d->modify('+1 month')){$label=$d->format('Y-m');$rows[]=['label'=>$label,'amount'=>(int)($counts[$label]??0)];if(count($rows)>1200)break;}
    }
    return array_merge($result,['rows'=>$rows,'total'=>array_sum(array_column($rows,'amount')),'sql'=>$sql,'params'=>$args]);
}
