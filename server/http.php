<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
if (!session_start()) {http_response_code(503);echo '{"error":"Не удалось войти. Попробуй позже."}';exit;}
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
function respond(array $data,int $status=200): never {
    http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
}
function fail(string $message,int $status): never {respond(['backend'=>'php-pdo','error'=>$message],$status);}
function require_admin(): void {if(empty($_SESSION['admin']))fail('Сначала войди как администратор.',401);}
function read_input(): array {
    if($_SERVER['REQUEST_METHOD']!=='POST')fail('Метод не поддерживается.',405);
    if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Обнови страницу и повтори действие.',403);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>50000)fail('Слишком большой запрос.',413);
    $raw=file_get_contents('php://input',false,null,0,50001);
    if(strlen($raw)>50000)fail('Слишком большой запрос.',413);
    try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){fail('Некорректный запрос.',400);}
    if(!is_array($data))fail('Некорректный запрос.',400);
    return $data;
}
function text_field(array $input,string $field,int $maximum): string {
    $s=$input[$field]??null;
    if(!is_string($s)||!preg_match('//u',$s))fail('Проверь заполнение полей.',422);
    $s=trim($s);$length=preg_match_all('/./us',$s);
    if($length<1||$length>$maximum)fail('Проверь длину текста: не больше '.$maximum.' символов.',422);
    return $s;
}
