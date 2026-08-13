<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

if (usuario_logado()) {
    $usuario = usuario_atual();

    registrar_log(
        'LOGOUT',
        $usuario['id'],
        $usuario['email']
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parametros['path'],
        $parametros['domain'],
        (bool) $parametros['secure'],
        (bool) $parametros['httponly']
    );
}

session_destroy();
redirecionar('index.php');
