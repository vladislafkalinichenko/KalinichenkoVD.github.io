<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/community.php';
function analytics_params(array $input): array {
    $defaults=['report'=>'search','q'=>'','category'=>'','from'=>'','to'=>'','sort'=>'newest','page'=>'1'];
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
    if($p['report']==='search'&&$p['q']!=='') {
        $like='%'.strtr($p['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $where[]="($lower(title) LIKE $lower(?) ESCAPE '!' OR $lower(body) LIKE $lower(?) ESCAPE '!' OR $lower(category) LIKE $lower(?) ESCAPE '!' OR $lower(source) LIKE $lower(?) ESCAPE '!')";
        $args=[$like,$like,$like,$like];
    }
    if($p['report']!=='ratings') {
        if($p['category']!==''){$where[]='category=?';$args[]=$p['category'];}
        if($p['from']!==''){$where[]='SUBSTR(published_at,1,10)>=?';$args[]=$p['from'];}
        if($p['to']!==''){$where[]='SUBSTR(published_at,1,10)<=?';$args[]=$p['to'];}
    }
    $clause=$where?' WHERE '.implode(' AND ',$where):'';
    $query=static function(string $sql,array $values=[])use($pdo):array{$q=$pdo->prepare($sql);$q->execute($values);return $q->fetchAll();};
    $categories=$pdo->query('SELECT DISTINCT category FROM news ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    $result=['categories'=>$categories,'rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'sql'=>'','params'=>$args,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)];
    if(in_array($p['report'],['search','filter'],true)) {
        $total=(int)$query('SELECT COUNT(*) AS n FROM news'.$clause,$args)[0]['n'];
        $pages=max(1,(int)ceil($total/20));$page=min($p['page'],$pages);$offset=($page-1)*20;
        $order=['newest'=>'published_at DESC, id DESC','oldest'=>'published_at ASC, id ASC','title'=>'title ASC, id ASC','category'=>'category ASC, published_at DESC, id ASC'][$p['sort']];
        $sql='SELECT id,title,body,published_at,category,source FROM news'.$clause.' ORDER BY '.$order.' LIMIT 20 OFFSET '.$offset;
        return array_merge($result,['rows'=>$query($sql,$args),'total'=>$total,'pages'=>$pages,'page'=>$page,'sql'=>$sql]);
    }
    if($p['report']==='categories')$sql='SELECT category AS label, COUNT(*) AS amount, MIN(SUBSTR(published_at,1,10)) AS first_date, MAX(SUBSTR(published_at,1,10)) AS last_date FROM news'.$clause.' GROUP BY category ORDER BY amount DESC, category ASC';
    elseif($p['report']==='timeline')$sql='SELECT SUBSTR(published_at,1,7) AS label, COUNT(*) AS amount FROM news'.$clause.' GROUP BY SUBSTR(published_at,1,7) ORDER BY label ASC';
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
