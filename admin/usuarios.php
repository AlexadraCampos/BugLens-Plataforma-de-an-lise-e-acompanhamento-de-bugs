<?php
declare(strict_types=1);
 
require_once dirname(__DIR__) . '/config/database.php';
 
exigir_admin();
 
$usuarioAtual = usuario_atual();
$erro = '';
$sucesso = '';
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
 
    if (!csrf_valido($token)) {
        $erro = 'A sessão expirou. Atualize a página.';
    } else {
        $acao = (string) ($_POST['acao'] ?? '');
 
        try {
            if ($acao === 'criar') {
                $nome = trim((string) ($_POST['nome'] ?? ''));
                $email = strtolower(
                    trim((string) ($_POST['email'] ?? ''))
                );
                $senha = (string) ($_POST['senha'] ?? '');
                $perfil = (string) ($_POST['perfil'] ?? 'usuario');
 
                if (
                    $nome === ''
                    || !filter_var($email, FILTER_VALIDATE_EMAIL)
                ) {
                    throw new RuntimeException(
                        'Informe o nome e um e-mail válido.'
                    );
                }
 
                if (strlen($senha) < 8) {
                    throw new RuntimeException(
                        'A senha precisa ter pelo menos 8 caracteres.'
                    );
                }
 
                if (!in_array($perfil, ['admin', 'usuario'], true)) {
                    throw new RuntimeException('Perfil inválido.');
                }
 
                $stmt = db()->prepare(
                    'INSERT INTO usuarios
                        (nome, email, senha_hash, perfil, ativo)
                     VALUES
                        (:nome, :email, :senha_hash, :perfil, 1)'
                );
 
                $stmt->execute([
                    'nome' => $nome,
                    'email' => $email,
                    'senha_hash' => password_hash(
                        $senha,
                        PASSWORD_DEFAULT
                    ),
                    'perfil' => $perfil,
                ]);
 
                $sucesso = 'Usuário criado com sucesso.';
            }
 
            if ($acao === 'alternar_status') {
                $id = (int) ($_POST['id'] ?? 0);
 
                if ($id === $usuarioAtual['id']) {
                    throw new RuntimeException(
                        'Você não pode desativar o próprio usuário.'
                    );
                }
 
                $stmt = db()->prepare(
                    'UPDATE usuarios
                     SET ativo = IF(ativo = 1, 0, 1)
                     WHERE id = :id'
                );
 
                $stmt->execute(['id' => $id]);
                $sucesso = 'Status atualizado.';
            }
 
            if ($acao === 'alterar_perfil') {
                $id = (int) ($_POST['id'] ?? 0);
                $novoPerfil = (string) ($_POST['novo_perfil'] ?? '');
 
                if ($id === $usuarioAtual['id']) {
                    throw new RuntimeException(
                        'Você não pode alterar o próprio perfil.'
                    );
                }
 
                if (!in_array($novoPerfil, ['admin', 'usuario'], true)) {
                    throw new RuntimeException('Perfil inválido.');
                }
 
                $stmt = db()->prepare(
                    'UPDATE usuarios
                     SET perfil = :perfil
                     WHERE id = :id'
                );
 
                $stmt->execute([
                    'perfil' => $novoPerfil,
                    'id' => $id,
                ]);
 
                $sucesso = 'Perfil atualizado.';
            }
 
            if ($acao === 'excluir') {
                $id = (int) ($_POST['id'] ?? 0);
 
                if ($id === $usuarioAtual['id']) {
                    throw new RuntimeException(
                        'Você não pode excluir o próprio usuário.'
                    );
                }
 
                $stmt = db()->prepare(
                    'DELETE FROM usuarios WHERE id = :id'
                );
                $stmt->execute(['id' => $id]);
 
                $sucesso = 'Usuário excluído.';
            }
 
            if ($acao === 'redefinir_senha') {
                $id = (int) ($_POST['id'] ?? 0);
                $novaSenha = (string) ($_POST['nova_senha'] ?? '');
 
                if (strlen($novaSenha) < 8) {
                    throw new RuntimeException(
                        'A nova senha precisa ter pelo menos 8 caracteres.'
                    );
                }
 
                $stmt = db()->prepare(
                    'UPDATE usuarios
                     SET senha_hash = :senha_hash
                     WHERE id = :id'
                );
 
                $stmt->execute([
                    'senha_hash' => password_hash(
                        $novaSenha,
                        PASSWORD_DEFAULT
                    ),
                    'id' => $id,
                ]);
 
                $sucesso = 'Senha redefinida.';
            }
        } catch (PDOException $erroBanco) {
            if ((int) ($erroBanco->errorInfo[1] ?? 0) === 1062) {
                $erro = 'Esse e-mail já está cadastrado.';
            } else {
                $erro = 'Não foi possível concluir a operação.';
                error_log($erroBanco->getMessage());
            }
        } catch (Throwable $excecao) {
            $erro = $excecao->getMessage();
        }
    }
}
 
$usuarios = db()->query(
    'SELECT id, nome, email, perfil, ativo, criado_em
     FROM usuarios
     ORDER BY nome'
)->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuários | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="app-body">
<header class="topbar">
    <div class="topbar-title">
        <strong>Usuários</strong>
    </div>
 
    <nav>
        <a href="<?= e(url('admin/index.php')) ?>">Administração</a>
        <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
        <a class="logout-link" href="<?= e(url('logout.php')) ?>">
            Sair
        </a>
    </nav>
</header>
 
<main class="content-container">
    <?php if ($erro !== ''): ?>
        <div class="alert erro"><?= e($erro) ?></div>
    <?php endif; ?>
 
    <?php if ($sucesso !== ''): ?>
        <div class="alert sucesso"><?= e($sucesso) ?></div>
    <?php endif; ?>
 
    <section class="panel">
        <h2>Novo usuário</h2>
 
        <form method="post" class="form-grid">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >
            <input type="hidden" name="acao" value="criar">
 
            <div>
                <label for="nome">Nome</label>
                <input id="nome" name="nome" type="text" required>
            </div>
 
            <div>
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" required>
            </div>
 
            <div>
                <label for="senha">Senha inicial</label>
                <input
                    id="senha"
                    name="senha"
                    type="password"
                    minlength="8"
                    required
                >
            </div>
 
            <div>
                <label for="perfil">Perfil</label>
                <select id="perfil" name="perfil">
                    <option value="usuario">Usuário</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
 
            <div class="form-action">
                <button type="submit">Cadastrar</button>
            </div>
        </form>
    </section>
 
    <section class="panel">
        <h2>Usuários cadastrados</h2>
 
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $usuario): ?>
                    <tr>
                        <td><?= e($usuario['nome']) ?></td>
                        <td><?= e($usuario['email']) ?></td>
                        <td><?= e($usuario['perfil']) ?></td>
                        <td>
                            <?= (int) $usuario['ativo'] === 1
                                ? 'Ativo'
                                : 'Inativo' ?>
                        </td>
                        <td class="actions">
                            <?php if ((int) $usuario['id'] !== $usuarioAtual['id']): ?>
                                <form method="post">
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="alternar_status"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $usuario['id'] ?>"
                                    >
                                    <button
                                        type="submit"
                                        class="secondary"
                                    >
                                        <?= (int) $usuario['ativo'] === 1
                                            ? 'Desativar'
                                            : 'Ativar' ?>
                                    </button>
                                </form>
 
                                <form method="post" class="reset-form">
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="alterar_perfil"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $usuario['id'] ?>"
                                    >
                                    <select name="novo_perfil">
                                        <option
                                            value="usuario"
                                            <?= $usuario['perfil'] === 'usuario'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Usuário
                                        </option>
                                        <option
                                            value="admin"
                                            <?= $usuario['perfil'] === 'admin'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Administrador
                                        </option>
                                    </select>
                                    <button
                                        type="submit"
                                        class="secondary"
                                    >
                                        Alterar perfil
                                    </button>
                                </form>
 
                                <form
                                    method="post"
                                    onsubmit="return confirm('Excluir <?= e($usuario['nome']) ?> definitivamente? Essa ação não pode ser desfeita.');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="excluir"
                                    >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $usuario['id'] ?>"
                                    >
                                    <button
                                        type="submit"
                                        class="secondary"
                                    >
                                        Excluir
                                    </button>
                                </form>
                            <?php endif; ?>
 
                            <form method="post" class="reset-form">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrf_token()) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="acao"
                                    value="redefinir_senha"
                                >
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int) $usuario['id'] ?>"
                                >
                                <input
                                    name="nova_senha"
                                    type="password"
                                    minlength="8"
                                    placeholder="Nova senha"
                                    required
                                >
                                <button
                                    type="submit"
                                    class="secondary"
                                >
                                    Redefinir
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>