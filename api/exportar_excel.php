<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Compatibilidade com PHP anterior a 8.0
|--------------------------------------------------------------------------
| str_contains() so existe nativamente a partir do PHP 8.0. O servidor
| desta pasta esta rodando uma versao mais antiga, entao definimos a
| mesma funcao aqui caso ela ainda nao exista.
*/
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/power_bi.php';

exigir_login();
$usuario = usuario_atual();

$relatorio = strtolower(trim((string) ($_GET['relatorio'] ?? '')));
$opcao = strtolower(trim((string) ($_GET['opcao'] ?? '')));

function erro_download(string $mensagem, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $mensagem;
    exit;
}

function guid(string $valor, string $nome): string
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valor)) {
        throw new RuntimeException($nome . ' inválido.');
    }
    return $valor;
}

function requisicao_json(string $metodo, string $url, array $headers = [], ?array $body = null, bool $form = false): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
    }

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Não foi possível iniciar a conexão.');

    $cabecalhos = array_merge(['Accept: application/json'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($body !== null) {
        if ($form) {
            $conteudo = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
            $cabecalhos[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $conteudo = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($conteudo)) throw new RuntimeException('Falha ao montar a requisição.');
            $cabecalhos[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_POSTFIELDS] = $conteudo;
    }

    $opts[CURLOPT_HTTPHEADER] = $cabecalhos;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);

    if ($raw === false) {
        $msg = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Falha na comunicação: ' . $msg);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $dados = json_decode((string) $raw, true);

    if ($status < 200 || $status >= 300) {
        $msg = is_array($dados)
            ? ($dados['error']['message'] ?? $dados['error_description'] ?? $dados['message'] ?? null)
            : null;
        throw new RuntimeException(is_string($msg) && $msg !== '' ? $msg : ('HTTP ' . $status . ': ' . trim((string) $raw)));
    }

    if (!is_array($dados)) throw new RuntimeException('A API retornou uma resposta inválida.');
    return $dados;
}

function obter_token(): string
{
    if (POWER_BI_CLIENT_SECRET === '' || str_contains(POWER_BI_CLIENT_SECRET, 'COLE_AQUI')) {
        throw new RuntimeException('Preencha POWER_BI_CLIENT_SECRET em config/power_bi.php.');
    }

    $tenant = guid(POWER_BI_TENANT_ID, 'Tenant ID');
    guid(POWER_BI_CLIENT_ID, 'Client ID');

    $r = requisicao_json(
        'POST',
        'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
        [],
        [
            'client_id' => POWER_BI_CLIENT_ID,
            'client_secret' => POWER_BI_CLIENT_SECRET,
            'scope' => 'https://analysis.windows.net/powerbi/api/.default',
            'grant_type' => 'client_credentials',
        ],
        true
    );

    $token = trim((string) ($r['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('O Entra não retornou o token.');
    return $token;
}

function obter_dataset(string $token, string $reportId): string
{
    $workspace = guid(POWER_BI_WORKSPACE_ID, 'Workspace ID');
    $report = guid($reportId, 'Report ID');
    $r = requisicao_json(
        'GET',
        'https://api.powerbi.com/v1.0/myorg/groups/' . rawurlencode($workspace) . '/reports/' . rawurlencode($report),
        ['Authorization: Bearer ' . $token]
    );
    return guid(trim((string) ($r['datasetId'] ?? '')), 'Dataset ID');
}

function executar_dax(string $token, string $datasetId, string $dax): array
{
    $workspace = guid(POWER_BI_WORKSPACE_ID, 'Workspace ID');
    $r = requisicao_json(
        'POST',
        'https://api.powerbi.com/v1.0/myorg/groups/' . rawurlencode($workspace) . '/datasets/' . rawurlencode($datasetId) . '/executeQueries',
        ['Authorization: Bearer ' . $token],
        [
            'queries' => [['query' => $dax]],
            'serializerSettings' => ['includeNulls' => true],
        ]
    );

    $erro = $r['results'][0]['error']['message'] ?? $r['results'][0]['tables'][0]['error']['message'] ?? null;
    if (is_string($erro) && trim($erro) !== '') throw new RuntimeException(trim($erro));
    $rows = $r['results'][0]['tables'][0]['rows'] ?? [];
    return is_array($rows) ? $rows : [];
}

function normalizar_linhas(array $rows, array $columns): array
{
    $saida = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $mapa = [];
        foreach ($row as $k => $v) {
            $k = (string) $k;
            if (preg_match('/^[^\[]*\[(.*)\]$/u', $k, $m)) $k = $m[1];
            $mapa[$k] = $v;
        }
        $linha = [];
        foreach ($columns as $c) $linha[$c] = $mapa[$c] ?? null;
        $saida[] = $linha;
    }
    return $saida;
}

function xml(string $s): string
{
    $s = preg_replace('/[^\P{C}\t\n\r]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col_excel(int $n): string
{
    $s = '';
    while ($n > 0) { $n--; $s = chr(65 + ($n % 26)) . $s; $n = intdiv($n, 26); }
    return $s;
}

function estilo(string $nome): int
{
    $n = mb_strtolower($nome, 'UTF-8');
    if (str_contains($n, 'proporção') || str_contains($n, 'participação') || str_contains($n, 'taxa')) return 2;
    if (str_contains($n, 'valor') || str_contains($n, 'vgv') || str_contains($n, 'ticket') || str_contains($n, 'preço') || str_contains($n, 'vl vagas')) return 3;
    return 0;
}

function gerar_xlsx(string $path, string $sheetName, array $columns, array $rows): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão ZipArchive do PHP não está habilitada.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar o Excel.');

    $sheetName = mb_substr(trim(preg_replace('#[\\\\/:*?\[\]]#u', ' ', $sheetName) ?? 'Relatório'), 0, 31, 'UTF-8') ?: 'Relatório';
    $cols = '';
    foreach ($columns as $i => $name) {
        $max = mb_strlen($name, 'UTF-8');
        foreach (array_slice($rows, 0, 1000) as $row) $max = max($max, mb_strlen((string) ($row[$name] ?? ''), 'UTF-8'));
        $w = min(max($max + 2, 12), 45);
        $n = $i + 1;
        $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
    }

    $sheetRows = '<row r="1">';
    foreach ($columns as $i => $name) {
        $ref = col_excel($i + 1) . '1';
        $sheetRows .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . xml($name) . '</t></is></c>';
    }
    $sheetRows .= '</row>';

    $r = 2;
    foreach ($rows as $row) {
        $sheetRows .= '<row r="' . $r . '">';
        foreach ($columns as $i => $name) {
            $ref = col_excel($i + 1) . $r;
            $v = $row[$name] ?? null;
            $s = estilo($name);
            $style = $s ? ' s="' . $s . '"' : '';
            if (is_int($v) || is_float($v) || (is_string($v) && $v !== '' && is_numeric($v) && !preg_match('/^0\d+$/', $v))) {
                $sheetRows .= '<c r="' . $ref . '"' . $style . '><v>' . xml((string) $v) . '</v></c>';
            } else {
                $sheetRows .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . xml($v === null ? '' : (string) $v) . '</t></is></c>';
            }
        }
        $sheetRows .= '</row>';
        $r++;
    }

    $lastCol = col_excel(count($columns));
    $lastRow = max(1, $r - 1);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols><sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A1:' . $lastCol . $lastRow . '"/></worksheet>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="0.00%"/><numFmt numFmtId="165" formatCode="R$ #,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

$configs = [
    'vgv' => [
        'report' => POWER_BI_REPORT_VGV_ID,
        'options' => [
            'vgv-total' => [
                'file' => 'FGV - VGV Total.xlsx',
                'sheet' => 'VGV Total',
                'columns' => ['Empreendimento','Unidades','Lojas','Vagas','VL Vagas','VGV','Ticket Medio','Participação'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'empreendimentos'[nome],
        'empreendimentos'[Total Unidades],
        'empreendimentos'[Total Lojas],
        'empreendimentos'[Total Vagas Autônomas],
        "__VL Vagas", '_Medidas'[Valor Total Vagas],
        "__VGV", '_Medidas'[VGV Total],
        "__Ticket Medio", '_Medidas'[Ticket Medio],
        "__Participação", '_Medidas'[Participação % VGV]
    ),
    "Empreendimento", 'empreendimentos'[nome],
    "Unidades", 'empreendimentos'[Total Unidades],
    "Lojas", 'empreendimentos'[Total Lojas],
    "Vagas", 'empreendimentos'[Total Vagas Autônomas],
    "VL Vagas", [__VL Vagas],
    "VGV", [__VGV],
    "Ticket Medio", [__Ticket Medio],
    "Participação", [__Participação]
)
ORDER BY [Empreendimento]
DAX,
            ],
            'vgv-unidade' => [
                'file' => 'FGV - VGV Unidade.xlsx',
                'sheet' => 'VGV Unidade',
                'columns' => ['Empreendimento','Bloco','Unidade','Tipologia','Área privativa','Preço por m²','Valor'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'empreendimentos'[nome],
        'unidades'[bloco],
        'unidades'[nome],
        'unidades'[tipologia],
        'unidades'[area_privativa_m2],
        "__Preço por m²", '_Medidas'[Preço por m²],
        "__Valor", SUM('valor'[valor])
    ),
    "Empreendimento", 'empreendimentos'[nome],
    "Bloco", 'unidades'[bloco],
    "Unidade", 'unidades'[nome],
    "Tipologia", 'unidades'[tipologia],
    "Área privativa", 'unidades'[area_privativa_m2],
    "Preço por m²", [__Preço por m²],
    "Valor", [__Valor]
)
ORDER BY [Empreendimento], [Bloco], [Unidade]
DAX,
            ],
        ],
    ],
    'leads' => [
        'report' => POWER_BI_REPORT_LEADS_ID,
        'options' => [
            'sdr' => [
                'file' => 'Leads - SDR.xlsx', 'sheet' => 'SDR',
                'columns' => ['SDR','Aguardando Atendimento','Dia 1 GA','Dia 2 GA','Em Atendimento GA','Aguardando Lancamento','Total SDR','Descarte'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'Leads'[gestor],
        "__Aguardando Atendimento", 'Medidass'[Leads Aguardando Atendimento],
        "__Dia 1 GA", 'Medidass'[Leads Dia 1 GA],
        "__Dia 2 GA", 'Medidass'[Leads Dia 2 GA],
        "__Em Atendimento GA", 'Medidass'[Leads Em Atendimento GA],
        "__Aguardando Lancamento", 'Medidass'[eads Aguardando Lancamento],
        "__Total SDR", 'Medidass'[Total SDR],
        "__Descarte", 'Medidass'[Descarte]
    ),
    "SDR", 'Leads'[gestor],
    "Aguardando Atendimento", [__Aguardando Atendimento],
    "Dia 1 GA", [__Dia 1 GA],
    "Dia 2 GA", [__Dia 2 GA],
    "Em Atendimento GA", [__Em Atendimento GA],
    "Aguardando Lancamento", [__Aguardando Lancamento],
    "Total SDR", [__Total SDR],
    "Descarte", [__Descarte]
)
ORDER BY [SDR]
DAX,
            ],
            'corretor' => [
                'file' => 'Leads - Corretor.xlsx', 'sheet' => 'Corretor',
                'columns' => ['Imobiliaria','Corretor','Leads Aguardando Corretor','Dia 1 Corretor','Dia 2 Corretor','Em Atendimento Corretor','Agendamento da Visita','Visita Cancelada','Visita Realizada','Reunindo Documentacao','Montagem da Proposta','Proposta','Venda Realizada','Aguardando Lançamento','Totais Corretor','Descarte'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'Leads'[imobiliaria], 'Leads'[corretor],
        "__Leads Aguardando Corretor", 'Medidass'[Leads Aguardando Corretor],
        "__Dia 1 Corretor", 'Medidass'[Dia 1 Corretor],
        "__Dia 2 Corretor", 'Medidass'[Dia 2 Corretor],
        "__Em Atendimento Corretor", 'Medidass'[Em Atendimento Corretor],
        "__Agendamento da Visita", 'Medidass'[Agendamento da Visita],
        "__Visita Cancelada", 'Medidass'[Visita Cancelada],
        "__Visita Realizada", 'Medidass'[Visita Realizada],
        "__Reunindo Documentacao", 'Medidass'[Reunindo Documentacao],
        "__Montagem da Proposta", 'Medidass'[Montagem da Proposta],
        "__Proposta", 'Medidass'[Proposta],
        "__Venda Realizada", 'Medidass'[Venda Realizada],
        "__Aguardando Lançamento", 'Medidass'[Aguardando Lancamento Corretor],
        "__Totais Corretor", 'Medidass'[Totais Corretor],
        "__Descarte", 'Medidass'[Descarte]
    ),
    "Imobiliaria", 'Leads'[imobiliaria], "Corretor", 'Leads'[corretor],
    "Leads Aguardando Corretor", [__Leads Aguardando Corretor],
    "Dia 1 Corretor", [__Dia 1 Corretor], "Dia 2 Corretor", [__Dia 2 Corretor],
    "Em Atendimento Corretor", [__Em Atendimento Corretor],
    "Agendamento da Visita", [__Agendamento da Visita],
    "Visita Cancelada", [__Visita Cancelada], "Visita Realizada", [__Visita Realizada],
    "Reunindo Documentacao", [__Reunindo Documentacao],
    "Montagem da Proposta", [__Montagem da Proposta], "Proposta", [__Proposta],
    "Venda Realizada", [__Venda Realizada], "Aguardando Lançamento", [__Aguardando Lançamento],
    "Totais Corretor", [__Totais Corretor], "Descarte", [__Descarte]
)
ORDER BY [Imobiliaria], [Corretor]
DAX,
            ],
            'metrica-corretor' => [
                'file' => 'Leads - Métrica Corretor.xlsx', 'sheet' => 'Métrica Corretor',
                'columns' => ['Imobiliaria','Corretor','Venda Realizada','Descarte','Taxa de Conversao','Taxa de Descarte'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'Leads'[imobiliaria], 'Leads'[corretor],
        "__Venda Realizada", 'Medidass'[Venda Realizada],
        "__Descarte", 'Medidass'[Descarte],
        "__Taxa de Conversao", 'Medidass'[Taxa de Conversao],
        "__Taxa de Descarte", 'Medidass'[Taxa de Descarte]
    ),
    "Imobiliaria", 'Leads'[imobiliaria], "Corretor", 'Leads'[corretor],
    "Venda Realizada", [__Venda Realizada], "Descarte", [__Descarte],
    "Taxa de Conversao", [__Taxa de Conversao], "Taxa de Descarte", [__Taxa de Descarte]
)
ORDER BY [Imobiliaria], [Corretor]
DAX,
            ],
            'origem-lead' => [
                'file' => 'Leads - Origem do Lead.xlsx', 'sheet' => 'Origem do Lead',
                'columns' => ['Origem Nome','Quantidade Leads','Proporção por Origem'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'Leads'[origem_nome],
        "__Quantidade Leads", 'Medidass'[Quantidade Leads],
        "__Proporção por Origem", 'Medidass'[Proporção por Origem]
    ),
    "Origem Nome", 'Leads'[origem_nome],
    "Quantidade Leads", [__Quantidade Leads],
    "Proporção por Origem", [__Proporção por Origem]
)
ORDER BY [Origem Nome]
DAX,
            ],
            'midia-lead' => [
                'file' => 'Leads - Mídia do Lead.xlsx', 'sheet' => 'Mídia do Lead',
                'columns' => ['Mídia último','Quantidade Leads','Proporção por Mídia'],
                'dax' => <<<'DAX'
EVALUATE
SELECTCOLUMNS(
    SUMMARIZECOLUMNS(
        'Leads'[midia_ultimo],
        "__Quantidade Leads", 'Medidass'[Quantidade Leads],
        "__Proporção por Mídia", 'Medidass'[Proporção por Mídia]
    ),
    "Mídia último", 'Leads'[midia_ultimo],
    "Quantidade Leads", [__Quantidade Leads],
    "Proporção por Mídia", [__Proporção por Mídia]
)
ORDER BY [Mídia último] DESC
DAX,
            ],
        ],
    ],
];

if (!isset($configs[$relatorio]['options'][$opcao])) erro_download('Relatório ou opção inválida.', 400);

try {
    $cfg = $configs[$relatorio];
    $opt = $cfg['options'][$opcao];
    $token = obter_token();
    $dataset = obter_dataset($token, $cfg['report']);
    $rows = executar_dax($token, $dataset, $opt['dax']);
    $rows = normalizar_linhas($rows, $opt['columns']);

    $temp = tempnam(sys_get_temp_dir(), 'portal_bi_');
    if ($temp === false) throw new RuntimeException('Não foi possível criar o arquivo temporário.');
    $xlsx = $temp . '.xlsx';
    @unlink($temp);
    gerar_xlsx($xlsx, $opt['sheet'], $opt['columns'], $rows);

    registrar_log('RELATORIO_EXCEL_GERADO', $usuario['id'], $usuario['email'], $relatorio . ':' . $opcao);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $opt['file']) . '"');
    header('Content-Length: ' . (string) filesize($xlsx));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($xlsx);
    @unlink($xlsx);
    exit;
} catch (Throwable $e) {
    registrar_log('RELATORIO_EXCEL_FALHA', $usuario['id'], $usuario['email'], $relatorio . ':' . $opcao . ' - ' . $e->getMessage());
    erro_download("Não foi possível gerar o Excel.\n\n" . $e->getMessage());
}
