<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Servidor de assets estaticos via PHP
|--------------------------------------------------------------------------
| Motivo: requisicoes diretas a arquivos .css/.png dentro desta pasta
| estao sendo interceptadas antes de chegar aqui (nivel do WordPress/
| Wordfence na raiz do site). Como arquivos .php nao sofrem esse
| problema, servimos os assets por este script em vez de diretamente.
*/

$mapa = [
    'style.css' => [
        'caminho' => __DIR__ . '/css/style.css',
        'tipo'    => 'text/css; charset=utf-8',
    ],
    'favicon.png' => [
        'caminho' => __DIR__ . '/img/favicon-150x150.png',
        'tipo'    => 'image/png',
    ],
];

$pedido = $_GET['f'] ?? '';

if (!isset($mapa[$pedido])) {
    http_response_code(404);
    exit;
}

$item = $mapa[$pedido];

if (!is_file($item['caminho'])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $item['tipo']);
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . filesize($item['caminho']));
readfile($item['caminho']);
exit;
