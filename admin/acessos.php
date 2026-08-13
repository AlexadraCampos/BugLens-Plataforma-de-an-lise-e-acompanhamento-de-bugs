<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

exigir_admin();

$logs = db()->query(
    'SELECT
        logs.email_informado,
        logs.evento,
        logs.detalhes,
        logs.ip,
        logs.criado_em,
        usuarios.nome
     FROM logs_acesso logs
     LEFT JOIN usuarios
        ON usuarios.id = logs.usuario_id
     ORDER BY logs.criado_em DESC
     LIMIT 500'
)->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acessos | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="app-body">
<header class="topbar">
    <div class="topbar-title">
        <strong>Acessos</strong>
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
    <section class="panel">
        <h2>Últimos 500 registros</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Evento</th>
                    <th>Usuário</th>
                    <th>E-mail</th>
                    <th>IP</th>
                    <th>Detalhes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <?= e(
                                date(
                                    'd/m/Y H:i:s',
                                    strtotime($log['criado_em'])
                                )
                            ) ?>
                        </td>
                        <td><?= e($log['evento']) ?></td>
                        <td><?= e($log['nome'] ?? '-') ?></td>
                        <td><?= e($log['email_informado'] ?? '-') ?></td>
                        <td><?= e($log['ip']) ?></td>
                        <td><?= e($log['detalhes'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="6">Nenhum registro encontrado.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
