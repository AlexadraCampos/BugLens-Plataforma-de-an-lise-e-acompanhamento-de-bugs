<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BLOQUEIO DE ACESSO DIRETO
|--------------------------------------------------------------------------
*/
if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    exit('Acesso negado.');
}

/*
|--------------------------------------------------------------------------
| BANCO DE DADOS 
|--------------------------------------------------------------------------
*/
define('DB_HOST', getenv('PORTAL_BI_DB_HOST') ?: 'mysql.seudominio.com.br');
define('DB_NAME', getenv('PORTAL_BI_DB_NAME') ?: 'portal_bi_demo');
define('DB_USER', getenv('PORTAL_BI_DB_USER') ?: 'portal_bi_demo');
define('DB_PASS', getenv('PORTAL_BI_DB_PASS') ?: 'TROQUE_ESTA_SENHA');

/*
|--------------------------------------------------------------------------
| SISTEMA
|--------------------------------------------------------------------------
*/
define('BASE_URL', '/login_bi');
define('APP_NAME', 'Portal BI');
define('APP_TIMEZONE', 'America/Sao_Paulo');
date_default_timezone_set(APP_TIMEZONE);

/*
| Preencha somente quando o relatório utilizar RLS.
*/
define('POWER_BI_RLS_ROLE', '');

/*
|--------------------------------------------------------------------------
| PRIMEIRO ADMINISTRADOR
|--------------------------------------------------------------------------
| Troque por um token longo antes do primeiro acesso ao /admin/.
*/
define(
    'SETUP_TOKEN',
    'TROQUE-ESTE-TOKEN-ANTES-DO-PRIMEIRO-ACESSO'
);
/*
|--------------------------------------------------------------------------
| SESSÃO
|--------------------------------------------------------------------------
*/
$httpsAtivo = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_name('portal_bi_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim(BASE_URL, '/') . '/',
    'domain' => 'seudominio.com.br',
    'secure' => $httpsAtivo,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| CABEÇALHOS DE SEGURANÇA
|--------------------------------------------------------------------------
*/
function csp_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(18));
    }

    return $nonce;
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Surrogate-Control: no-store');
header('Vary: Cookie');
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.jsdelivr.net; "
    . "style-src 'self'; "
    . "img-src 'self' data: https://*.powerbi.com; "
    . "connect-src 'self' https://*.powerbi.com https://*.analysis.windows.net; "
    . "frame-src https://*.powerbi.com https://app.powerbi.com; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "frame-ancestors 'self';"
);

/*
|--------------------------------------------------------------------------
| CONEXÃO PDO
|--------------------------------------------------------------------------
*/
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    criar_tabelas($pdo);

    return $pdo;
}

/*
|--------------------------------------------------------------------------
| TABELAS
|--------------------------------------------------------------------------
| O banco deve existir. As tabelas são criadas automaticamente.
*/
function criar_tabelas(PDO $pdo): void
{
    static $executado = false;

    if ($executado) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            senha_hash VARCHAR(255) NOT NULL,
            perfil ENUM('admin', 'usuario') NOT NULL DEFAULT 'usuario',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_usuarios_email (email),
            KEY idx_usuarios_ativo (ativo)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS logs_acesso (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT UNSIGNED NULL,
            email_informado VARCHAR(190) NULL,
            evento VARCHAR(40) NOT NULL,
            detalhes VARCHAR(1000) NULL,
            ip VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_logs_usuario (usuario_id),
            KEY idx_logs_evento_data (evento, criado_em),
            KEY idx_logs_email_ip_data (
                email_informado,
                ip,
                criado_em
            ),
            CONSTRAINT fk_logs_usuario
                FOREIGN KEY (usuario_id)
                REFERENCES usuarios (id)
                ON UPDATE CASCADE
                ON DELETE SET NULL
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
    );

    $executado = true;
}

/*
|--------------------------------------------------------------------------
| FUNÇÕES GERAIS
|--------------------------------------------------------------------------
*/
function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $caminho = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($caminho, '/');
}

function redirecionar(string $caminho): void
{
    header('Location: ' . url($caminho));
    exit;
}

function usuario_logado(): bool
{
    return !empty($_SESSION['usuario_id']);
}

function usuario_atual(): array
{
    return [
        'id' => (int) ($_SESSION['usuario_id'] ?? 0),
        'nome' => (string) ($_SESSION['usuario_nome'] ?? ''),
        'email' => (string) ($_SESSION['usuario_email'] ?? ''),
        'perfil' => (string) ($_SESSION['usuario_perfil'] ?? ''),
    ];
}

function exigir_login(): void
{
    if (!usuario_logado()) {
        mensagem_flash('erro', 'Faça login para continuar.');
        redirecionar('index.php');
    }
}

function exigir_admin(): void
{
    exigir_login();

    if (($_SESSION['usuario_perfil'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_valido(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function mensagem_flash(string $tipo, string $texto): void
{
    $_SESSION['flash'] = [
        'tipo' => $tipo,
        'texto' => $texto,
    ];
}

function obter_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function ip_cliente(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'desconhecido', 0, 45);
}

function user_agent_cliente(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido', 0, 500);
}

function registrar_log(
    string $evento,
    ?int $usuarioId = null,
    ?string $emailInformado = null,
    ?string $detalhes = null
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO logs_acesso
                (
                    usuario_id,
                    email_informado,
                    evento,
                    detalhes,
                    ip,
                    user_agent
                )
             VALUES
                (
                    :usuario_id,
                    :email_informado,
                    :evento,
                    :detalhes,
                    :ip,
                    :user_agent
                )'
        );

        $stmt->execute([
            'usuario_id' => $usuarioId,
            'email_informado' => $emailInformado,
            'evento' => substr($evento, 0, 40),
            'detalhes' => $detalhes ? substr($detalhes, 0, 1000) : null,
            'ip' => ip_cliente(),
            'user_agent' => user_agent_cliente(),
        ]);
    } catch (Throwable $erro) {
        error_log('Falha ao registrar log: ' . $erro->getMessage());
    }
}

function resposta_json(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| POWER BI
|--------------------------------------------------------------------------
*/
function powerbi_configurado(): bool
{
    $valores = [
        POWER_BI_TENANT_ID,
        POWER_BI_CLIENT_ID,
        POWER_BI_CLIENT_SECRET,
        POWER_BI_WORKSPACE_ID,
        POWER_BI_REPORT_ID,
    ];

    foreach ($valores as $valor) {
        if (trim($valor) === '' || strpos($valor, 'SEU_') === 0) {
            return false;
        }
    }

    return true;
}

function requisicao_http(
    string $metodo,
    string $endereco,
    array $cabecalhos = [],
    ?string $corpo = null
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'A extensão cURL do PHP não está habilitada.'
        );
    }

    $curl = curl_init($endereco);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_HEADER => false,
    ]);

    if ($corpo !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $corpo);
    }

    $resposta = curl_exec($curl);

    if ($resposta === false) {
        $mensagem = curl_error($curl);
        curl_close($curl);

        throw new RuntimeException(
            'Falha ao comunicar com a Microsoft: ' . $mensagem
        );
    }

    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $json = json_decode($resposta, true);

    return [
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'raw' => $resposta,
    ];
}

function obter_token_entra(): string
{
    $endereco = sprintf(
        'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
        rawurlencode(POWER_BI_TENANT_ID)
    );

    $corpo = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => POWER_BI_CLIENT_ID,
        'client_secret' => POWER_BI_CLIENT_SECRET,
        'scope' => 'https://analysis.windows.net/powerbi/api/.default',
    ]);

    $resposta = requisicao_http(
        'POST',
        $endereco,
        ['Content-Type: application/x-www-form-urlencoded'],
        $corpo
    );

    if (
        $resposta['status'] < 200
        || $resposta['status'] >= 300
        || empty($resposta['json']['access_token'])
    ) {
        $mensagem = $resposta['json']['error_description']
            ?? $resposta['json']['error']
            ?? 'Não foi possível autenticar no Microsoft Entra ID.';

        throw new RuntimeException($mensagem);
    }

    return (string) $resposta['json']['access_token'];
}

function obter_relatorio_powerbi(string $accessToken): array
{
    $endereco = sprintf(
        'https://api.powerbi.com/v1.0/myorg/groups/%s/reports/%s',
        rawurlencode(POWER_BI_WORKSPACE_ID),
        rawurlencode(POWER_BI_REPORT_ID)
    );

    $resposta = requisicao_http(
        'GET',
        $endereco,
        [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]
    );

    if (
        $resposta['status'] < 200
        || $resposta['status'] >= 300
        || empty($resposta['json']['embedUrl'])
        || empty($resposta['json']['datasetId'])
    ) {
        $mensagem = $resposta['json']['error']['message']
            ?? 'Não foi possível localizar o relatório no workspace.';

        throw new RuntimeException($mensagem);
    }

    return $resposta['json'];
}

function gerar_embed_token(
    string $accessToken,
    string $datasetId,
    string $emailUsuario
): array {
    $payload = [
        'datasets' => [
            ['id' => $datasetId],
        ],
        'reports' => [
            [
                'id' => POWER_BI_REPORT_ID,
                'allowEdit' => false,
            ],
        ],
    ];

    if (trim(POWER_BI_RLS_ROLE) !== '') {
        $payload['identities'] = [
            [
                'username' => $emailUsuario,
                'roles' => [POWER_BI_RLS_ROLE],
                'datasets' => [$datasetId],
                'auditableContext' => $emailUsuario,
            ],
        ];
    }

    $resposta = requisicao_http(
        'POST',
        'https://api.powerbi.com/v1.0/myorg/GenerateToken',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )
    );

    if (
        $resposta['status'] < 200
        || $resposta['status'] >= 300
        || empty($resposta['json']['token'])
    ) {
        $mensagem = $resposta['json']['error']['message']
            ?? $resposta['json']['error']['code']
            ?? 'Não foi possível gerar o token de incorporação.';

        throw new RuntimeException($mensagem);
    }

    return $resposta['json'];
}

function criar_configuracao_embed(string $emailUsuario): array
{
    if (!powerbi_configurado()) {
        throw new RuntimeException(
            'Preencha as credenciais do Power BI em config/database.php.'
        );
    }

    $accessToken = obter_token_entra();
    $relatorio = obter_relatorio_powerbi($accessToken);
    $embedToken = gerar_embed_token(
        $accessToken,
        (string) $relatorio['datasetId'],
        $emailUsuario
    );

    return [
        'reportId' => POWER_BI_REPORT_ID,
        'embedUrl' => $relatorio['embedUrl'],
        'embedToken' => $embedToken['token'],
        'expiration' => $embedToken['expiration'] ?? null,
    ];
}
