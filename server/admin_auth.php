<?php
declare(strict_types=1);
// Shared authentication for the news API and the server-rendered RSS forms.
function admin_login(mixed $password):?array {
    if(($_SESSION['blocked_until']??0)>time())return ['Слишком много попыток. Повтори через минуту.',429];
    $hash=config()['admin_password_hash'];
    if(!$hash)return ['Администратор ещё не настроил пароль сервера.',503];
    if(!is_string($password)||!password_verify($password,$hash)){
        $_SESSION['attempts']=($_SESSION['attempts']??0)+1;
        if($_SESSION['attempts']>=5){$_SESSION['blocked_until']=time()+60;$_SESSION['attempts']=0;}
        return ['Неверный пароль.',401];
    }
    session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['attempts']=0;
    $_SESSION['csrf']=bin2hex(random_bytes(24));$_SESSION['rss_csrf']=bin2hex(random_bytes(24));
    return null;
}
