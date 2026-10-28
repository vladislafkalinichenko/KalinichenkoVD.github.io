<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/http.php';
require_once dirname(__DIR__).'/server/admin_auth.php';
require_once dirname(__DIR__).'/server/news_input.php';
try {
    $action=$_GET['action']??'list'; $method=$_SERVER['REQUEST_METHOD'];
    if ($method==='GET') {
        if ($action==='status') {db();respond(['backend'=>'php-pdo','authenticated'=>!empty($_SESSION['admin']),'csrf'=>$_SESSION['csrf']]);}
        if ($action==='list') respond(['items'=>db()->query('SELECT n.*, a.name AS author_name FROM news n JOIN authors a ON a.id=n.author_id ORDER BY n.published_at DESC, n.id DESC')->fetchAll()]);
        if ($action==='catalog') respond(['authors'=>catalog_authors(db()),'categories'=>db()->query('SELECT DISTINCT category FROM news ORDER BY category')->fetchAll(PDO::FETCH_COLUMN)]);
        fail('Такого действия нет.',404);
    }
    if ($method!=='POST') {header('Allow: GET, POST');fail('Метод не поддерживается.',405);}
    if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('Сессия устарела. Обнови страницу.',403);
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>50000) fail('Слишком большой запрос.',413);
    $raw=file_get_contents('php://input',false,null,0,50001);
    if (strlen($raw)>50000) fail('Слишком большой запрос.',413);
    try {$input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){fail('Некорректный запрос.',400);}
    if (!is_array($input)) fail('Некорректный запрос.',400);
    if ($action==='login') {
        if($failure=admin_login($input['password']??null))fail($failure[0],$failure[1]);
        respond(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    if (empty($_SESSION['admin'])) fail('Сначала войди как администратор.',401);
    if ($action==='logout') {$_SESSION['admin']=false;$_SESSION['csrf']=bin2hex(random_bytes(24));respond(['ok'=>true,'csrf'=>$_SESSION['csrf']]);}
    if($action==='delete'){
        $id=$input['id']??null;
        if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id))fail('Выбери публикацию для удаления.',422);
        $q=db()->prepare('DELETE FROM news WHERE id=?');$q->execute([$id]);
        if(!$q->rowCount())fail('Публикация уже удалена или не найдена.',404);
        respond(['ok'=>true,'id'=>$id]);
    }
    $len=static fn(string $s):int=>preg_match_all('/./us',$s);
    if($action==='create_author'){
        $fields=[];foreach(['name'=>160,'role'=>160,'bio'=>1000] as $field=>$max){$v=$input[$field]??null;if(!is_string($v)||!preg_match('//u',$v)||trim($v)===''||$len($v)>$max)fail('Заполни имя, роль и описание автора в пределах указанной длины.',422);$fields[$field]=trim($v);}
        $author=['id'=>bin2hex(random_bytes(16)),...$fields];$q=db()->prepare('INSERT INTO authors(id,name,role,bio) VALUES (?,?,?,?)');$q->execute(array_values($author));respond(['author'=>$author],201);
    }
    if ($action!=='create') fail('Такого действия нет.',404);
    respond(['item'=>news_create(db(),$input)],201);
} catch (InvalidArgumentException $e) {fail($e->getMessage(),422);}
catch (Throwable $e) {error_log('MIXLAB API: '.$e->getMessage());fail('Не удалось загрузить записи. Попробуй позже.',503);}
