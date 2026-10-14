<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/http.php';
require dirname(__DIR__).'/server/community.php';
try {
    $pdo=community_db();$action=$_GET['action']??'status';
    if($_SERVER['REQUEST_METHOD']==='GET'){
       if($action==='status'){visitor_token($pdo);respond(['backend'=>'php-pdo','csrf'=>$_SESSION['csrf'],'authenticated'=>!empty($_SESSION['admin'])]);}
       if($action==='guestbook')respond(['items'=>public_posts($pdo,'guest')]);
       if($action==='forum'){
          $items=public_posts($pdo,'topic');foreach($items as &$item)$item['replies']=array_reverse(public_posts($pdo,'reply',$item['id']));unset($item);respond(['items'=>$items]);
       }
       if($action==='rating')respond(ratings($pdo));
       if($action==='stats')respond(site_stats($pdo));
       if($action==='moderation'){
          require_admin();$items=$pdo->query('SELECT * FROM community_posts ORDER BY created_at DESC,id DESC')->fetchAll();
          $votes=$pdo->query('SELECT visitor AS id,score FROM site_ratings ORDER BY score,visitor')->fetchAll();
          foreach($votes as &$vote)$vote['score']=(int)$vote['score'];unset($vote);
          respond(['items'=>$items,'words'=>$pdo->query('SELECT word FROM community_words ORDER BY word')->fetchAll(PDO::FETCH_COLUMN),'blocks'=>$pdo->query('SELECT host_token FROM community_blocks ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN),'rating_items'=>$votes,'rating'=>ratings($pdo)]);
       }
       fail('Такого действия нет.',404);
    }
    $input=read_input();
    if($action==='visit'){
        $page=text_field($input,'page',120);if($page==='xml.php')$page='xml.html';
        $known=json_decode(file_get_contents(dirname(__DIR__).'/data/pages.json'),true,512,JSON_THROW_ON_ERROR);
        $allowed=array_column($known,'url');$allowed[]='admin/community.html';$allowed[]='404.html';
        if(!in_array($page,$allowed,true))fail('Страница не найдена.',422);
        rate_limit($pdo,visitor_token($pdo),120);bump($pdo,'site_views',['page'=>$page,'day'=>current_day()],'views');
        try{$q=$pdo->prepare('INSERT INTO site_visitors(visitor,day) VALUES (?,?)');$q->execute([visitor_token($pdo),current_day()]);}catch(PDOException $e){$q=$pdo->prepare('SELECT visitor FROM site_visitors WHERE visitor=? AND day=?');$q->execute([visitor_token($pdo),current_day()]);if(!$q->fetchColumn())throw $e;}
        respond(site_stats($pdo));
    }
    if(in_array($action,['guest_create','topic_create','reply_create'],true)){
        $author=text_field($input,'author',40);$body=text_field($input,'body',$action==='guest_create'?1000:2000);
        $kind=$action==='guest_create'?'guest':($action==='topic_create'?'topic':'reply');$title=$kind==='topic'?text_field($input,'title',120):'';$parent='';
        if($kind==='reply'){$parent=text_field($input,'parent_id',40);$q=$pdo->prepare("SELECT id FROM community_posts WHERE id=? AND kind='topic' AND hidden=0");$q->execute([$parent]);if(!$q->fetchColumn())fail('Тема не найдена.',404);}
        check_message($pdo,$author.' '.$title.' '.$body);$id=bin2hex(random_bytes(16));
        $q=$pdo->prepare('INSERT INTO community_posts(id,kind,parent_id,title,author,body,created_at,host_token) VALUES (?,?,?,?,?,?,?,?)');$q->execute([$id,$kind,$parent,$title,$author,$body,gmdate('Y-m-d\TH:i:s\Z'),host_token($pdo)]);respond(['ok'=>true,'id'=>$id],201);
    }
    if($action==='rate'){
        $score=$input['score']??null;if(!is_int($score)||$score<0||$score>5)fail('Выбери оценку от 1 до 5.',422);
        $visitor=visitor_token($pdo);$pdo->beginTransaction();$q=$pdo->prepare('DELETE FROM site_ratings WHERE visitor=?');$q->execute([$visitor]);
        if($score){$q=$pdo->prepare('INSERT INTO site_ratings(visitor,score) VALUES (?,?)');$q->execute([$visitor,$score]);}$pdo->commit();respond(ratings($pdo));
    }
    require_admin();
    if($action==='post_delete'){
        $id=text_field($input,'id',40);$q=$pdo->prepare('SELECT kind,hidden FROM community_posts WHERE id=?');$q->execute([$id]);$post=$q->fetch();
        if(!$post)fail('Сообщение уже удалено или не найдено.',404);
        if(!(int)$post['hidden'])fail('Сначала скрой сообщение, затем удали его навсегда.',409);
        $pdo->beginTransaction();$q=$pdo->prepare('DELETE FROM community_posts WHERE id=? AND hidden=1');$q->execute([$id]);
        if(!$q->rowCount()){$pdo->rollBack();fail('Состояние сообщения изменилось. Обнови страницу.',409);}
        if($post['kind']==='topic'){$q=$pdo->prepare("DELETE FROM community_posts WHERE kind='reply' AND parent_id=?");$q->execute([$id]);}
        $pdo->commit();respond(['ok'=>true]);
    }
    if($action==='rating_delete'){
        $id=text_field($input,'id',64);if(!preg_match('/^[a-f0-9]{64}$/D',$id))fail('Некорректный идентификатор оценки.',422);
        $q=$pdo->prepare('DELETE FROM site_ratings WHERE visitor=?');$q->execute([$id]);
        if(!$q->rowCount())fail('Оценка уже удалена или не найдена.',404);
        respond(['ok'=>true,'rating'=>ratings($pdo)]);
    }
    if($action==='visibility'){
        $id=text_field($input,'id',40);$hidden=$input['hidden']??null;if(!is_bool($hidden))fail('Некорректное действие.',422);
        $q=$pdo->prepare('UPDATE community_posts SET hidden=? WHERE id=?');$q->execute([(int)$hidden,$id]);respond(['ok'=>true]);
    }
    if($action==='block'){
        $id=text_field($input,'id',40);$q=$pdo->prepare('SELECT host_token FROM community_posts WHERE id=?');$q->execute([$id]);$host=$q->fetchColumn();if(!$host)fail('Сообщение не найдено.',404);
        $q=$pdo->prepare('SELECT host_token FROM community_blocks WHERE host_token=?');$q->execute([$host]);if(!$q->fetchColumn()){$q=$pdo->prepare('INSERT INTO community_blocks(host_token,created_at) VALUES (?,?)');$q->execute([$host,gmdate('c')]);}respond(['ok'=>true]);
    }
    if($action==='unblock'){$host=text_field($input,'host_token',64);$q=$pdo->prepare('DELETE FROM community_blocks WHERE host_token=?');$q->execute([$host]);respond(['ok'=>true]);}
    if($action==='words'){
        $words=$input['words']??null;if(!is_array($words)||count($words)>100)fail('Допускается до 100 выражений.',422);
        $clean=[];foreach($words as $word)$clean[]=text_field(['word'=>$word],'word',100);
        $pdo->beginTransaction();$pdo->exec('DELETE FROM community_words');$q=$pdo->prepare('INSERT INTO community_words(word) VALUES (?)');foreach(array_unique($clean) as $word)$q->execute([$word]);$pdo->commit();respond(['ok'=>true]);
    }
    fail('Такого действия нет.',404);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('MIXLAB community: '.$e->getMessage());fail('Не удалось выполнить действие. Попробуй позже.',503);}
