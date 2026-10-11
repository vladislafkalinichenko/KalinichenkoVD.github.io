<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
// Password comes from stdin, never from command-line arguments or a tracked file.
fwrite(STDOUT,"Введите пароль администратора (минимум 10 символов): ");
$password=trim(fgets(STDIN));
if (strlen($password)<10) {fwrite(STDERR,"Пароль слишком короткий.\n");exit(1);}
$c=config();$dir=$c['private_dir'];if(!is_dir($dir))mkdir($dir,0700,true);
$file=$dir.'/config.php';$saved=is_file($file)?require $file:[];
$saved['admin_password_hash']=password_hash($password,PASSWORD_DEFAULT);
file_put_contents($file,"<?php\nreturn ".var_export($saved,true).";\n",LOCK_EX);chmod($file,0600);
db();fwrite(STDOUT,"БД и пароль готовы. Запусти: php -S 127.0.0.1:8000 server/router.php\n");
