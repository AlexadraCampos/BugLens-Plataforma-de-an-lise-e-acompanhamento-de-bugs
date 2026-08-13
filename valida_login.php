<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('index.php');
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$senha = (string) ($_POST['senha'] ?? '');
$token = (string) ($_POST['csrf_token'] ?? '');

if (!csrf_valido($token)) {
    mensagem_flash(
        'erro',
        'A sessão expirou. Atualize a página e tente novamente.'
    );
    redirecionar('index.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
    mensagem_flash('erro', 'Informe um e-mail válido e a senha.');
    redirecionar('index.php');
}

$stmtTentativas = db()->prepare(
    "SELECT COUNT(*)
     FROM logs_acesso
     WHERE evento = 'LOGIN_FALHA'
       AND email_informado = :email
       AND ip = :ip
       AND criado_em >= (NOW() - INTERVAL 15 MINUTE)"
);

$stmtTentativas->execute([
    'email' => $email,
    'ip' => ip_cliente(),
]);

if ((int) $stmtTentativas->fetchColumn() >= 5) {
    mensagem_flash(
        'erro',
        'Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.'
    );
    redirecionar('index.php');
}

$stmt = db()->prepare(
    'SELECT id, nome, email, senha_hash, perfil, ativo
     FROM usuarios
     WHERE email = :email
     LIMIT 1'
);

$stmt->execute(['email' => $email]);
$usuario = $stmt->fetch();

$senhaCorreta = $usuario
    && (int) $usuario['ativo'] === 1
    && password_verify($senha, $usuario['senha_hash']);

if (!$senhaCorreta) {
    registrar_log(
        'LOGIN_FALHA',
        $usuario ? (int) $usuario['id'] : null,
        $email
    );

    mensagem_flash('erro', 'E-mail ou senha inválidos.');
    redirecionar('index.php');
}

session_regenerate_id(true);

$_SESSION['usuario_id'] = (int) $usuario['id'];
$_SESSION['usuario_nome'] = $usuario['nome'];
$_SESSION['usuario_email'] = $usuario['email'];
$_SESSION['usuario_perfil'] = $usuario['perfil'];

registrar_log(
    'LOGIN_SUCESSO',
    (int) $usuario['id'],
    $usuario['email']
);

redirecionar('dashboard.php');
