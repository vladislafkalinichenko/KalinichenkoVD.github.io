<?php
declare(strict_types=1);
function community_db(): PDO {
    $pdo=db();static $ready=false;if($ready)return $pdo;
    foreach([
      'CREATE TABLE IF NOT EXISTS community_posts (id VARCHAR(40) PRIMARY KEY, kind VARCHAR(10) NOT NULL, parent_id VARCHAR(40) NOT NULL, title VARCHAR(120) NOT NULL, author VARCHAR(40) NOT NULL, body TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, host_token VARCHAR(64) NOT NULL, hidden INTEGER NOT NULL DEFAULT 0)',
      'CREATE TABLE IF NOT EXISTS community_blocks (host_token VARCHAR(64) PRIMARY KEY, created_at VARCHAR(30) NOT NULL)',
      'CREATE TABLE IF NOT EXISTS community_words (word VARCHAR(100) PRIMARY KEY)',
      'CREATE TABLE IF NOT EXISTS site_ratings (visitor VARCHAR(64) PRIMARY KEY, score INTEGER NOT NULL)',
      'CREATE TABLE IF NOT EXISTS site_views (page VARCHAR(120) NOT NULL, day VARCHAR(10) NOT NULL, views INTEGER NOT NULL, PRIMARY KEY (page,day))',
      'CREATE TABLE IF NOT EXISTS site_visitors (visitor VARCHAR(64) NOT NULL, day VARCHAR(10) NOT NULL, PRIMARY KEY (visitor,day))',
      'CREATE TABLE IF NOT EXISTS community_limits (actor VARCHAR(64) NOT NULL, bucket INTEGER NOT NULL, requests INTEGER NOT NULL, PRIMARY KEY(actor,bucket))'
    ] as $sql)$pdo->exec($sql);
    $ready=true;return $pdo;
}
function privacy_key(PDO $pdo): string {
    $q=$pdo->query("SELECT value FROM app_meta WHERE name='privacy_key'");$key=$q->fetchColumn();
    if(!$key){$key=bin2hex(random_bytes(32));try{$q=$pdo->prepare('INSERT INTO app_meta(name,value) VALUES (?,?)');$q->execute(['privacy_key',$key]);}catch(PDOException $e){$key=$pdo->query("SELECT value FROM app_meta WHERE name='privacy_key'")->fetchColumn();if(!$key)throw $e;}}
    return $key;
}
function host_token(PDO $pdo): string {return hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',privacy_key($pdo));}
function visitor_token(PDO $pdo): string {
    $v=$_COOKIE['mixlab_visitor']??'';
    if(!preg_match('/^[a-f0-9]{48}$/D',$v)){
       $v=$_SESSION['visitor']??bin2hex(random_bytes(24));$_SESSION['visitor']=$v;
       setcookie('mixlab_visitor',$v,['expires'=>time()+31536000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
    }
    return hash_hmac('sha256',$v,privacy_key($pdo));
}
function current_day(): string {return (new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->format('Y-m-d');}
function bump(PDO $pdo,string $table,array $keys,string $column): void {
    $where=implode(' AND ',array_map(static fn($k)=>$k.'=?',array_keys($keys)));
    $q=$pdo->prepare("UPDATE $table SET $column=$column+1 WHERE $where");$q->execute(array_values($keys));
    if(!$q->rowCount()){
        try{$columns=implode(',',array_keys($keys)).','.$column;$marks=implode(',',array_fill(0,count($keys)+1,'?'));$q=$pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)");$q->execute([...array_values($keys),1]);}
        catch(PDOException $e){$q=$pdo->prepare("UPDATE $table SET $column=$column+1 WHERE $where");$q->execute(array_values($keys));if(!$q->rowCount())throw $e;}
    }
}
function rate_limit(PDO $pdo,string $actor,int $limit): void {
    $bucket=(int)floor(time()/60);bump($pdo,'community_limits',['actor'=>$actor,'bucket'=>$bucket],'requests');
    $q=$pdo->prepare('SELECT requests FROM community_limits WHERE actor=? AND bucket=?');$q->execute([$actor,$bucket]);
    if((int)$q->fetchColumn()>$limit)fail('Слишком много действий. Подожди минуту.',429);
    $q=$pdo->prepare('DELETE FROM community_limits WHERE bucket<?');$q->execute([$bucket-120]);
}
function check_message(PDO $pdo,string $body): void {
    $q=$pdo->prepare('SELECT host_token FROM community_blocks WHERE host_token=?');$q->execute([host_token($pdo)]);
    if($q->fetchColumn())fail('Публикация сообщений для этого подключения ограничена.',403);
    foreach($pdo->query('SELECT word FROM community_words')->fetchAll(PDO::FETCH_COLUMN) as $word){
        if(preg_match('/'.preg_quote($word,'/').'/iu',$body))fail('Измени текст: в нём есть запрещённое выражение.',422);
    }
    rate_limit($pdo,host_token($pdo),20);
}
function public_posts(PDO $pdo,string $kind,string $parent=''): array {
    $q=$pdo->prepare('SELECT id,title,author,body,created_at FROM community_posts WHERE kind=? AND parent_id=? AND hidden=0 ORDER BY created_at DESC,id DESC');$q->execute([$kind,$parent]);return $q->fetchAll();
}
function ratings(PDO $pdo): array {
    $row=$pdo->query('SELECT COUNT(*) AS votes, AVG(score) AS average FROM site_ratings')->fetch();
    $q=$pdo->prepare('SELECT score FROM site_ratings WHERE visitor=?');$q->execute([visitor_token($pdo)]);
    return ['votes'=>(int)$row['votes'],'average'=>$row['average']===null?null:round((float)$row['average'],2),'mine'=>(int)$q->fetchColumn()];
}
function site_stats(PDO $pdo): array {
    $today=current_day();$q=$pdo->prepare('SELECT COALESCE(SUM(views),0) FROM site_views WHERE day=?');$q->execute([$today]);
    $vis=$pdo->prepare('SELECT COUNT(*) FROM site_visitors WHERE day=?');$vis->execute([$today]);
    $pages=$pdo->query('SELECT page,SUM(views) AS views FROM site_views GROUP BY page ORDER BY views DESC,page ASC')->fetchAll();
    return ['total'=>(int)$pdo->query('SELECT COALESCE(SUM(views),0) FROM site_views')->fetchColumn(),'today'=>(int)$q->fetchColumn(),'visitors'=>(int)$pdo->query('SELECT COUNT(DISTINCT visitor) FROM site_visitors')->fetchColumn(),'visitors_today'=>(int)$vis->fetchColumn(),'pages'=>$pages];
}
