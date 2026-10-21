<?php
// Router for the local PHP development server; never expose server files or private data.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)\.|^/(?:server|database|tools)/|\.(?:sqlite|db|sql)$~i',$path)||str_contains($path,'..')) {http_response_code(404);exit;}
$root=dirname(__DIR__);
if ($path==='/database.html') {require $root.'/database.php';return true;}
if ($path==='/xml.html') {require $root.'/xml.php';return true;}
if ($path==='/services/news.html') {require $root.'/services/news.php';return true;}
$file=realpath($root.$path);
if ($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file)) return false;
if ($path==='/'||is_dir($root.$path)) { $index=$root.rtrim($path,'/').'/index.html';if(is_file($index)){header('Content-Type: text/html; charset=utf-8');readfile($index);return true;} }
http_response_code(404);header('Content-Type: text/html; charset=utf-8');readfile($root.'/404.html');return true;
