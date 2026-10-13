<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__).'/server/weather.php';
try {
    if ($_SERVER['REQUEST_METHOD']!=='GET') {header('Allow: GET');http_response_code(405);echo '{"error":"Метод не поддерживается."}';exit;}
    $key=$_GET['city']??'obninsk';$refresh=$_GET['refresh']??'0';
    if (!is_string($key) || !is_string($refresh) || !in_array($refresh,['0','1'],true)) throw new InvalidArgumentException('Проверь параметры погоды.');
    $result=weather_result($key,$refresh==='1');
    if (!$result['city']) http_response_code(503);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch (Throwable $e) {error_log('MIXLAB weather API: '.$e->getMessage());http_response_code(503);echo '{"error":"Не удалось проверить погоду. Попробуй позже."}';}
