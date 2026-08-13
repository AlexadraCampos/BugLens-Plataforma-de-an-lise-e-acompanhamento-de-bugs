<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

if (usuario_logado()) {
    redirecionar('dashboard.php');
}

$flash = obter_flash();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/asset.php?f=style.css')) ?>">
</head>
<body class="login-body">
<main class="login-card">
    <div class="brand">
        <img
            src="<?= e(url('assets/asset.php?f=favicon.png')) ?>"
            alt=""
            width="54"
            height="54"
        >
        <div>
            <h1><?= e(APP_NAME) ?></h1>
            <p>Acesso autenticado ao Power BI</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= e($flash['tipo']) ?>">
            <?= e($flash['texto']) ?>
        </div>
    <?php endif; ?>

    <form action="<?= e(url('valida_login.php')) ?>" method="post">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <label for="email">E-mail</label>
        <input
            id="email"
            name="email"
            type="email"
            autocomplete="username"
            required
            autofocus
        >

        <label for="senha">Senha</label>
        <input
            id="senha"
            name="senha"
            type="password"
            autocomplete="current-password"
            required
        >
        <button type="submit">Entrar</button>
    </form>
</main>
</body>
</html>
