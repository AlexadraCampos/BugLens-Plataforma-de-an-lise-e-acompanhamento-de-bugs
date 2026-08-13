<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

exigir_login();

$usuario = usuario_atual();

registrar_log(
    'DASHBOARD_ABERTO',
    $usuario['id'],
    $usuario['email']
);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/asset.php?f=style.css')) ?>">
</head>
<body class="app-body">
<header class="topbar">
    <div class="topbar-title">
        <img
            src="<?= e(url('assets/asset.php?f=favicon.png')) ?>"
            alt=""
            width="38"
            height="38"
        >
        <strong><?= e(APP_NAME) ?></strong>
    </div>

    <nav>
        <span><?= e($usuario['nome']) ?></span>

        <?php if ($usuario['perfil'] === 'admin'): ?>
            <a href="<?= e(url('admin/index.php')) ?>">
                Administração
            </a>
        <?php endif; ?>

        <a class="logout-link" href="<?= e(url('logout.php')) ?>">
            Sair
        </a>
    </nav>
</header>

<main class="dashboard-container">
    <section id="bi-area">
        <aside id="bi-menu">
            <button
                type="button"
                class="bi-relatorio bi-ativo"
                data-relatorio="vgv"
                data-title="VGV"
                data-url="https://app.powerbi.com/view?r=COLE_AQUI_O_LINK_PUBLICO_DO_RELATORIO_VGV"
            >
                VGV 
            </button>

            <button
                type="button"
                class="bi-relatorio"
                data-relatorio="leads"
                data-title="Leads"
                data-url="https://app.powerbi.com/view?r=COLE_AQUI_O_LINK_PUBLICO_DO_RELATORIO_LEADS"
            >
                Leads
            </button>

            <select
                id="excel-opcao"
                aria-label="Escolha o conteúdo que deseja baixar"
                hidden
            ></select>

            <button
                id="excel-button"
                type="button"
                title="Escolher e baixar uma tabela em Excel"
            >
                Baixar no Excel
            </button>
        </aside>

        <iframe
            id="bi-frame"
            title="VGV"
            src="https://app.powerbi.com/view?r=COLE_AQUI_O_LINK_PUBLICO_DO_RELATORIO_VGV"
            allowfullscreen="true"
            referrerpolicy="strict-origin-when-cross-origin"
        ></iframe>
    </section>
</main>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const frame = document.getElementById('bi-frame');
    const botoes = document.querySelectorAll('#bi-menu .bi-relatorio');
    const excelButton = document.getElementById('excel-button');
    const excelOpcao = document.getElementById('excel-opcao');

    const opcoesPorRelatorio = {
        vgv: [
            {
                valor: 'vgv-total',
                texto: 'VGV Total',
                arquivo: 'VGV - VGV Total.xlsx'
            },
            {
                valor: 'vgv-unidade',
                texto: 'VGV Unidade',
                arquivo: 'VGV - VGV Unidade.xlsx'
            }
        ],
        leads: [
            {
                valor: 'sdr',
                texto: 'SDR',
                arquivo: 'Leads - SDR.xlsx'
            },
            {
                valor: 'corretor',
                texto: 'Corretor',
                arquivo: 'Leads - Corretor.xlsx'
            },
            {
                valor: 'metrica-corretor',
                texto: 'Métrica Corretor',
                arquivo: 'Leads - Métrica Corretor.xlsx'
            },
            {
                valor: 'origem-lead',
                texto: 'Origem do Lead',
                arquivo: 'Leads - Origem do Lead.xlsx'
            },
            {
                valor: 'midia-lead',
                texto: 'Mídia do Lead',
                arquivo: 'Leads - Mídia do Lead.xlsx'
            }
        ]
    };

    let botaoAtivo = document.querySelector(
        '#bi-menu .bi-relatorio.bi-ativo'
    );

    function fecharEscolhaExcel() {
        excelOpcao.hidden = true;
        excelOpcao.innerHTML = '';
        excelButton.textContent = 'Baixar no Excel';
        excelButton.disabled = false;
    }

    function abrirEscolhaExcel() {
        const relatorio = botaoAtivo?.dataset.relatorio;
        const opcoes = opcoesPorRelatorio[relatorio] || [];

        excelOpcao.innerHTML = '';

        opcoes.forEach(function (opcao) {
            const item = document.createElement('option');

            item.value = opcao.valor;
            item.textContent = opcao.texto;
            item.dataset.arquivo = opcao.arquivo;

            excelOpcao.appendChild(item);
        });

        excelOpcao.hidden = false;
        excelButton.textContent = 'Baixar selecionado';
        excelOpcao.focus();
    }

    botoes.forEach(function (botao) {
        botao.addEventListener('click', function () {
            botoes.forEach(function (item) {
                item.classList.remove('bi-ativo');
            });

            botao.classList.add('bi-ativo');
            botaoAtivo = botao;
            frame.title = botao.dataset.title;
            frame.src = botao.dataset.url;
            fecharEscolhaExcel();
        });
    });

    excelButton.addEventListener('click', async function () {
        if (!botaoAtivo?.dataset.relatorio) {
            return;
        }

        if (excelOpcao.hidden) {
            abrirEscolhaExcel();
            return;
        }

        const itemSelecionado =
            excelOpcao.options[excelOpcao.selectedIndex];

        if (!itemSelecionado) {
            return;
        }

        const textoOriginal = excelButton.textContent;

        excelButton.disabled = true;
        excelButton.textContent = 'Preparando...';

        try {
            const parametros = new URLSearchParams({
                relatorio: botaoAtivo.dataset.relatorio,
                opcao: itemSelecionado.value
            });

            const endereco =
                <?= json_encode(
                    url('api/exportar_excel.php'),
                    JSON_UNESCAPED_SLASHES
                ) ?>
                + '?' + parametros.toString();

            const resposta = await fetch(endereco, {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!resposta.ok) {
                const mensagem = await resposta.text();

                throw new Error(
                    mensagem || 'Não foi possível gerar o Excel.'
                );
            }

            const arquivo = await resposta.blob();
            const temporario = URL.createObjectURL(arquivo);
            const link = document.createElement('a');

            link.href = temporario;
            link.download =
                itemSelecionado.dataset.arquivo || 'relatorio.xlsx';
            link.style.display = 'none';

            document.body.appendChild(link);
            link.click();
            link.remove();

            setTimeout(function () {
                URL.revokeObjectURL(temporario);
            }, 1000);

            fecharEscolhaExcel();
        } catch (erro) {
            alert(
                erro?.message || 'Não foi possível baixar o Excel.'
            );

            excelButton.disabled = false;
            excelButton.textContent = textoOriginal;
        }
    });
})();
</script>
</body>
</html>
