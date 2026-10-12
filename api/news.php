<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/bootstrap.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
if (!session_start()) {http_response_code(503);echo json_encode(['backend'=>'php-pdo','error'=>'Не удалось войти. Попробуй позже.'],JSON_UNESCAPED_UNICODE);exit;}
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function respond(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function fail(string $message,int $status): never {respond(['backend'=>'php-pdo','error'=>$message],$status);}
try {
    $action=$_GET['action']??'list'; $method=$_SERVER['REQUEST_METHOD'];
    if ($method==='GET') {
        if ($action==='status') {db();respond(['backend'=>'php-pdo','authenticated'=>!empty($_SESSION['admin']),'csrf'=>$_SESSION['csrf']]);}
        if ($action==='list') respond(['items'=>db()->query('SELECT * FROM news ORDER BY published_at DESC, id DESC')->fetchAll()]);
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
        if (($_SESSION['blocked_until']??0)>time()) fail('Слишком много попыток. Повтори через минуту.',429);
        $hash=config()['admin_password_hash'];
        if (!$hash) fail('Администратор ещё не настроил пароль сервера.',503);
        $password=$input['password']??'';
        if (!is_string($password)||!password_verify($password,$hash)) {
            $_SESSION['attempts']=($_SESSION['attempts']??0)+1;
            if ($_SESSION['attempts']>=5) {$_SESSION['blocked_until']=time()+60;$_SESSION['attempts']=0;}
            fail('Неверный пароль.',401);
        }
        session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['attempts']=0;$_SESSION['csrf']=bin2hex(random_bytes(24));respond(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    if (empty($_SESSION['admin'])) fail('Сначала войди как администратор.',401);
    if ($action==='logout') {$_SESSION['admin']=false;$_SESSION['csrf']=bin2hex(random_bytes(24));respond(['ok'=>true,'csrf'=>$_SESSION['csrf']]);}
    if ($action!=='create') fail('Такого действия нет.',404);
    $title=$input['title']??null;$body=$input['body']??null;
    if (!is_string($title)||!is_string($body)) fail('Нужны заголовок и текст.',422);
    $title=trim($title);$body=trim($body);
    $len=static fn(string $s):int=>preg_match_all('/./us',$s);
    if ($title===''||$body===''||$len($title)>160||$len($body)>10000) fail('Заголовок: до 160 символов. Текст: до 10 000.',422);
    $item=['id'=>bin2hex(random_bytes(16)),'title'=>$title,'body'=>$body,'published_at'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'Свой сайт','source_url'=>'','category'=>'Обновления'];
    insert_news(db(),$item);respond(['item'=>$item],201);
} catch (Throwable $e) {error_log('MIXLAB API: '.$e->getMessage());fail('Не удалось загрузить записи. Попробуй позже.',503);}
