<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$quantidadeUsuarios = (int) db()
    ->query('SELECT COUNT(*) FROM usuarios')
    ->fetchColumn();

$erro = '';

if ($quantidadeUsuarios === 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');
        $setupToken = (string) ($_POST['setup_token'] ?? '');
        $csrf = (string) ($_POST['csrf_token'] ?? '');

        if (!csrf_valido($csrf)) {
            $erro = 'A sessão expirou. Atualize a página.';
        } elseif (!hash_equals(SETUP_TOKEN, $setupToken)) {
            $erro = 'Token de instalação inválido.';
        } elseif (
            $nome === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $erro = 'Preencha o nome e um e-mail válido.';
        } elseif (strlen($senha) < 8) {
            $erro = 'A senha precisa ter pelo menos 8 caracteres.';
        } else {
            $stmt = db()->prepare(
                "INSERT INTO usuarios
                    (nome, email, senha_hash, perfil, ativo)
                 VALUES
                    (:nome, :email, :senha_hash, 'admin', 1)"
            );

            $stmt->execute([
                'nome' => $nome,
                'email' => $email,
                'senha_hash' => password_hash(
                    $senha,
                    PASSWORD_DEFAULT
                ),
            ]);

            mensagem_flash(
                'sucesso',
                'Administrador criado. Faça login.'
            );

            redirecionar('index.php');
        }
    }
} else {
    exigir_admin();
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administração | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="<?= $quantidadeUsuarios === 0 ? 'login-body' : 'app-body' ?>">
<?php if ($quantidadeUsuarios === 0): ?>
    <main class="login-card">
        <h1>Primeiro administrador</h1>
        <p class="muted">
            Use o token configurado em config/database.php.
        </p>

        <?php if ($erro !== ''): ?>
            <div class="alert erro"><?= e($erro) ?></div>
        <?php endif; ?>

        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <label for="setup_token">Token de instalação</label>
            <input
                id="setup_token"
                name="setup_token"
                type="password"
                required
            >

            <label for="nome">Nome</label>
            <input id="nome" name="nome" type="text" required>

            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" required>

            <label for="senha">Senha</label>
            <input
                id="senha"
                name="senha"
                type="password"
                minlength="8"
                required
            >

            <button type="submit">Criar administrador</button>
        </form>
    </main>
<?php else: ?>
    <header class="topbar">
        <div class="topbar-title">
            <strong>Administração</strong>
        </div>

        <nav>
            <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
            <a class="logout-link" href="<?= e(url('logout.php')) ?>">
                Sair
            </a>
        </nav>
    </header>

    <main class="content-container">
        <section class="admin-cards">
            <a class="admin-card" href="<?= e(url('admin/usuarios.php')) ?>">
                <strong>Usuários</strong>
                <span>Cadastrar, ativar e redefinir senhas.</span>
            </a>

            <a class="admin-card" href="<?= e(url('admin/acessos.php')) ?>">
                <strong>Acessos</strong>
                <span>Consultar login e geração de token.</span>
            </a>
        </section>
    </main>
<?php endif; ?>
</body>
</html>
