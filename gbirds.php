<?php
/*
 * Optional private FireBird configuration.
 *
 * For Hostinger, upload firebird-config.php alongside this file (or update the
 * require path below if you keep it outside public_html). The config file is
 * intentionally separate from Git so Client Secrets are never committed.
 */
$firebirdConfigPath = __DIR__ . '/firebird-config.php';
if (is_file($firebirdConfigPath)) {
    require_once $firebirdConfigPath;
}

/*
 * GetBirds / FireBird server gateway
 * - Serves the existing SPA unchanged when requested normally.
 * - Provides same-origin server-side proxies for BirdBuddy GraphQL and media.
 * - Keeps Adobe/FireBird server integration available without exposing secrets.
 */

function gbirds_json_response($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function gbirds_cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin === '' || preg_match('/^https?:\/\//', $origin)) {
        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $requestHost = preg_replace('/:\d+$/', '', $host);
            if ($originHost && strcasecmp($originHost, $requestHost) === 0) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
        }
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
}

function gbirds_require_curl(): void {
    if (!function_exists('curl_init')) {
        gbirds_json_response(['error' => 'PHP cURL extension is required.'], 500);
    }
}

function gbirds_forward_headers(array $extra = []): array {
    $headers = ['Accept: application/json'];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    // Authorization is not guaranteed to appear in HTTP_AUTHORIZATION under
    // every Apache/mod_php configuration. Recover it from all common sources.
    $authorization = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('getallheaders')) {
        $requestHeaders = getallheaders();
        if (is_array($requestHeaders)) {
            foreach ($requestHeaders as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $authorization = trim((string)$value);
                    break;
                }
            }
        }
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (is_array($requestHeaders)) {
            foreach ($requestHeaders as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $authorization = trim((string)$value);
                    break;
                }
            }
        }
    }
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    return array_merge($headers, $extra);
}

function gbirds_proxy_graphql(): void {
    gbirds_cors_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        gbirds_json_response(['error' => 'Method not allowed.'], 405);
    }
    gbirds_require_curl();

    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        gbirds_json_response(['error' => 'Request body is required.'], 400);
    }

    $target = 'https://graphql.app-api.prod.aws.mybirdbuddy.com/graphql';
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => gbirds_forward_headers(),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        gbirds_json_response(['error' => 'BirdBuddy proxy request failed.', 'detail' => $error], 502);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);
    curl_close($ch);

    $contentTypeOut = 'application/json; charset=utf-8';
    if (preg_match('/^Content-Type:\s*([^\r\n]+)/im', $responseHeaders, $m)) {
        $contentTypeOut = trim($m[1]);
    }
    http_response_code($status ?: 502);
    header('Content-Type: ' . $contentTypeOut);
    header('Cache-Control: no-store');
    echo $responseBody;
    exit;
}

function gbirds_allowed_media_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
    if (!in_array(strtolower($parts['scheme']), ['https', 'http'], true)) return false;
    return strtolower($parts['host']) === 'graphql.app-api.prod.aws.mybirdbuddy.com' ||
           str_ends_with(strtolower($parts['host']), '.mybirdbuddy.com');
}

function gbirds_proxy_media(): void {
    gbirds_cors_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
        gbirds_json_response(['error' => 'Method not allowed.'], 405);
    }
    gbirds_require_curl();
    $url = trim((string)($_GET['url'] ?? ''));
    if ($url === '' || !gbirds_allowed_media_url($url)) {
        gbirds_json_response(['error' => 'Invalid BirdBuddy media URL.'], 400);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        gbirds_json_response(['error' => 'BirdBuddy media proxy request failed.', 'detail' => $error], 502);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);
    $contentType = 'application/octet-stream';
    if (preg_match('/^Content-Type:\s*([^\r\n]+)/im', $responseHeaders, $m)) {
        $contentType = trim($m[1]);
    }
    $length = strlen($responseBody);
    curl_close($ch);

    http_response_code($status ?: 502);
    header('Content-Type: ' . $contentType);
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . $length);
    echo $responseBody;
    exit;
}



function gbirds_config_value(string $name, string $default = ''): string {
    if (defined($name)) {
        $value = constant($name);
        if ($value !== null && $value !== '') return trim((string)$value);
    }
    $env = getenv($name);
    if ($env !== false && trim((string)$env) !== '') return trim((string)$env);
    return $default;
}

function gbirds_frameio_config(): array {
    // Frame.io V4 Web App User Authentication uses this documented scope set.
    // Keep this fixed for the Frame.io credential so stale/private config values
    // cannot introduce unrelated IMS scopes and cause invalid_scope.
    $frameioScopes = 'openid,email,profile,offline_access,additional_info.roles';
    return [
        'client_id' => gbirds_config_value('FIREBIRD_FRAMEIO_CLIENT_ID'),
        'client_secret' => gbirds_config_value('FIREBIRD_FRAMEIO_CLIENT_SECRET'),
        'redirect_uri' => gbirds_config_value('FIREBIRD_FRAMEIO_REDIRECT_URI', 'https://hh5hh.com/gbirds.php?api=frameio-callback'),
        'scopes' => $frameioScopes,
        'project_id' => gbirds_config_value('FIREBIRD_FRAMEIO_PROJECT_ID', 'f7f67254-9ec8-4e2c-99f8-32cd31123eef'),
        // Target collection inside the project. Resolved at runtime by id first,
        // then by name. Static collections expose a root_folder_id we upload into;
        // date folders are created under it. Leave both empty to upload under the
        // project root instead.
        'collection_id' => gbirds_config_value('FIREBIRD_FRAMEIO_COLLECTION_ID', ''),
        'collection_name' => gbirds_config_value('FIREBIRD_FRAMEIO_COLLECTION_NAME', 'Project_FIREBIRD'),
        // Explicit base folder to upload into (takes priority over collection).
        // This is the "BIRDS" folder inside Assets; date subfolders are created
        // under it. Leave empty to fall back to the collection / project root.
        'folder_id' => gbirds_config_value('FIREBIRD_FRAMEIO_FOLDER_ID', 'a80bef5f-0ec5-4074-b68e-6e70c5f1670f'),
        'api_base' => 'https://api.frame.io/v4',
        'ims_base' => 'https://ims-na1.adobelogin.com',
    ];
}

// Bump this whenever gbirds.php is redeployed so ?api=frameio-status confirms the
// LIVE server is running the intended build (guards against stale uploads).
if (!defined('FIREBIRD_BUILD_VERSION')) {
    define('FIREBIRD_BUILD_VERSION', 'firebird-2026-09-24-onelogin-dedupe-5');
}

function gbirds_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('FIREBIRDSESSID');
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function gbirds_frameio_http(string $method, string $url, ?array $body, string $accessToken, array $extraHeaders = []): array {
    gbirds_require_curl();
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    foreach ($extraHeaders as $h) { $headers[] = $h; }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES); }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Frame.io request failed: ' . $err); }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseBody = substr($raw, $headerSize);
    curl_close($ch);
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => trim(substr($responseBody, 0, 2000))];
    } else {
        $decoded['_http_status'] = $status;
        if (isset($responseBody[0]) && !isset($decoded['_raw'])) {
            $decoded['_raw'] = trim(substr($responseBody, 0, 2000));
        }
    }
    return [$status, $decoded];
}

function gbirds_frameio_token_is_fresh(): bool {
    gbirds_session_start();
    $exp = (int)($_SESSION['frameio_expires_at'] ?? 0);
    return !empty($_SESSION['frameio_access_token']) && $exp > (time() + 60);
}

function gbirds_frameio_begin_usage(): void {
    gbirds_session_start();
    $usageId = trim((string)($_GET['usage_id'] ?? $_POST['usage_id'] ?? ''));
    if ($usageId === '' || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $usageId)) {
        gbirds_json_response(['ok'=>false,'error'=>'Invalid FireBird usage ID.'],400);
    }
    $current = (string)($_SESSION['frameio_usage_id'] ?? '');
    if ($current !== $usageId) {
        unset($_SESSION['frameio_access_token'], $_SESSION['frameio_refresh_token'], $_SESSION['frameio_expires_at'], $_SESSION['frameio_oauth_state'], $_SESSION['frameio_pending_upload'], $_SESSION['frameio_usage_authenticated'], $_SESSION['frameio_ims_org_id'], $_SESSION['frameio_ims_user_id'], $_SESSION['frameio_auth_error'], $_SESSION['frameio_date_folders'], $_SESSION['frameio_date_collections']);
        $_SESSION['frameio_usage_id'] = $usageId;
        $_SESSION['frameio_usage_authenticated'] = false;
    }
    gbirds_json_response([
        'ok' => true,
        'usageId' => $usageId,
        'authenticated' => !empty($_SESSION['frameio_usage_authenticated']) && gbirds_frameio_token_is_fresh()
    ]);
}

function gbirds_frameio_begin_auth(): void {
    gbirds_session_start();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (!is_array($input)) $input = [];

    $usageId = trim((string)($input['usage_id'] ?? ''));
    if ($usageId === '' || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $usageId)) {
        gbirds_json_response(['ok'=>false,'error'=>'Invalid FireBird usage ID.'],400);
    }

    $mediaUrl = trim((string)($input['mediaUrl'] ?? ''));
    $filename = trim((string)($input['filename'] ?? ''));
    $createdAt = trim((string)($input['createdAt'] ?? ''));
    $species = trim((string)($input['species'] ?? ''));
    $postcardId = trim((string)($input['postcardId'] ?? ''));
    if ($mediaUrl === '' || !gbirds_allowed_media_url($mediaUrl)) {
        gbirds_json_response(['ok'=>false,'error'=>'Invalid BirdBuddy media URL.'],400);
    }
    if ($filename === '') {
        $filename = 'file_' . md5($mediaUrl) . (preg_match('/\.mp4(?:$|[?#])/i',$mediaUrl) ? '.mp4' : '.jpg');
    }

    // Explicit first-click gate: discard any previously cached Frame.io/IMS token
    // so Send to Adobe can never silently reuse an old account/profile.
    unset($_SESSION['frameio_access_token'], $_SESSION['frameio_refresh_token'], $_SESSION['frameio_expires_at'], $_SESSION['frameio_account_id'], $_SESSION['frameio_ims_org_id'], $_SESSION['frameio_ims_user_id'], $_SESSION['frameio_date_folders'], $_SESSION['frameio_auth_error']);
    $_SESSION['frameio_usage_id'] = $usageId;
    $_SESSION['frameio_usage_authenticated'] = false;
    $_SESSION['frameio_pending_upload'] = [
        'mediaUrl'=>$mediaUrl,
        'filename'=>$filename,
        'createdAt'=>$createdAt,
        'species'=>$species,
        'postcardId'=>$postcardId
    ];

    try {
        $authorizeUrl = gbirds_frameio_authorize_url();
    } catch (Throwable $e) {
        gbirds_json_response(['ok'=>false,'error'=>$e->getMessage(),'stage'=>'frameio-auth-begin'],500);
    }

    gbirds_json_response(['ok'=>true,'needsAuth'=>true,'authorizeUrl'=>$authorizeUrl]);
}

function gbirds_frameio_authorize_url(): string {
    $cfg = gbirds_frameio_config();
    if ($cfg['client_id'] === '') throw new RuntimeException('Frame.io Client ID is not configured on the server.');
    gbirds_session_start();
    $state = bin2hex(random_bytes(24));
    $_SESSION['frameio_oauth_state'] = $state;
    $scope = preg_replace('/\s*,\s*/', ',', preg_replace('/\s+/', ',', trim($cfg['scopes'])));
    $nonce = bin2hex(random_bytes(24));
    $_SESSION['frameio_auth_started_at'] = time();
    $_SESSION['frameio_auth_nonce'] = $nonce;
    // prompt=login forces a fresh sign-in (ignores any pre-existing IMS session);
    // select_account additionally forces Adobe's account/profile chooser so users
    // who belong to multiple IMS orgs must pick the profile/org that actually has
    // access to Project_FIREBIRD instead of silently landing on their default org.
    $query = http_build_query([
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'scope' => $scope,
        'response_type' => 'code',
        'response_mode' => 'query',
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'login select_account',
    ], '', '&', PHP_QUERY_RFC3986);
    return $cfg['ims_base'] . '/ims/authorize/v2?' . $query;
}

function gbirds_frameio_exchange_code(string $code): array {
    $cfg = gbirds_frameio_config();
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') throw new RuntimeException('Frame.io OAuth credentials are not configured on the server.');
    gbirds_require_curl();
    $ch = curl_init($cfg['ims_base'] . '/ims/token/v3');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($cfg['client_id'] . ':' . $cfg['client_secret']),
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $cfg['redirect_uri'],
        ]),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Adobe IMS token exchange failed: ' . $err); }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        $msg = $data['error_description'] ?? $data['error'] ?? ('IMS token exchange failed (' . $status . ')');
        throw new RuntimeException($msg);
    }
    return $data;
}

function gbirds_frameio_capture_ims_context(array $tokens): void {
    gbirds_session_start();
    unset($_SESSION['frameio_ims_org_id'], $_SESSION['frameio_ims_user_id']);
    $idToken = (string)($tokens['id_token'] ?? '');
    if ($idToken === '') return;
    $parts = explode('.', $idToken);
    if (count($parts) < 2) return;
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!is_array($payload)) return;
    foreach (['org_id', 'orgId', 'imsOrgId', 'ims_org_id'] as $key) {
        if (!empty($payload[$key])) {
            $_SESSION['frameio_ims_org_id'] = trim((string)$payload[$key]);
            break;
        }
    }
    if (!empty($payload['sub'])) {
        $_SESSION['frameio_ims_user_id'] = trim((string)$payload['sub']);
    }
}

function gbirds_frameio_store_tokens(array $tokens): void {
    gbirds_session_start();
    $_SESSION['frameio_access_token'] = (string)$tokens['access_token'];
    $_SESSION['frameio_refresh_token'] = (string)($tokens['refresh_token'] ?? ($_SESSION['frameio_refresh_token'] ?? ''));
    $_SESSION['frameio_expires_at'] = time() + max(60, ((int)($tokens['expires_in'] ?? 3600)) - 60);
}

function gbirds_frameio_refresh(): bool {
    gbirds_session_start();
    $refresh = trim((string)($_SESSION['frameio_refresh_token'] ?? ''));
    $cfg = gbirds_frameio_config();
    if ($refresh === '' || $cfg['client_id'] === '' || $cfg['client_secret'] === '') return false;
    gbirds_require_curl();
    $ch = curl_init($cfg['ims_base'] . '/ims/token/v3');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($cfg['client_id'] . ':' . $cfg['client_secret']),
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ]),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { curl_close($ch); return false; }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) return false;
    gbirds_frameio_store_tokens($data);
    return true;
}

function gbirds_frameio_access_token(): string {
    gbirds_session_start();
    // A Frame.io token is usable only after THIS browser-tab usage completed the
    // Adobe IMS Web App flow. Prior/stale sessions are never accepted as an
    // implicit login for the first Send to Adobe in a usage.
    if (empty($_SESSION['frameio_usage_authenticated'])) {
        throw new RuntimeException('Adobe IMS authorization is required.');
    }
    if (gbirds_frameio_token_is_fresh()) return (string)$_SESSION['frameio_access_token'];
    if (gbirds_frameio_refresh()) return (string)$_SESSION['frameio_access_token'];
    $_SESSION['frameio_usage_authenticated'] = false;
    throw new RuntimeException('Adobe IMS authorization is required.');
}

function gbirds_frameio_clear_session(): void {
    gbirds_session_start();
    unset($_SESSION['frameio_access_token'], $_SESSION['frameio_refresh_token'], $_SESSION['frameio_expires_at'], $_SESSION['frameio_oauth_state'], $_SESSION['frameio_pending_upload'], $_SESSION['frameio_usage_authenticated'], $_SESSION['frameio_ims_org_id'], $_SESSION['frameio_ims_user_id'], $_SESSION['frameio_auth_error'], $_SESSION['frameio_date_folders'], $_SESSION['frameio_date_collections']);
}


function gbirds_frameio_get_me(string $accessToken): array {
    $cfg = gbirds_frameio_config();
    [$status, $data] = gbirds_frameio_http('GET', $cfg['api_base'] . '/me', null, $accessToken);
    if ($status < 200 || $status >= 300) {
        $detail = $data['message'] ?? ($data['error']['message'] ?? '');
        throw new RuntimeException('Frame.io identity check failed (' . $status . ')' . ($detail ? ': ' . $detail : '.'));
    }
    return is_array($data) ? $data : [];
}

function gbirds_frameio_project_user_role(string $accessToken, string $accountId, string $projectId, string $userId): string {
    $cfg = gbirds_frameio_config();
    [$status, $data] = gbirds_frameio_http(
        'GET',
        $cfg['api_base'] . '/accounts/' . rawurlencode($accountId) . '/projects/' . rawurlencode($projectId) . '/users',
        null,
        $accessToken
    );
    if ($status < 200 || $status >= 300) return '';
    foreach (($data['data'] ?? []) as $user) {
        if (!is_array($user)) continue;
        $id = (string)($user['id'] ?? $user['user_id'] ?? '');
        if ($userId !== '' && $id !== '' && $id === $userId) {
            return strtolower(trim((string)($user['role'] ?? '')));
        }
    }
    return '';
}

function gbirds_frameio_role_allows_upload(string $role): bool {
    return in_array(strtolower(trim($role)), ['admin', 'full_access', 'editor', 'edit_only'], true);
}

function gbirds_frameio_find_project(string $accessToken): array {
    $cfg = gbirds_frameio_config();
    [$acctStatus, $acctData] = gbirds_frameio_http('GET', $cfg['api_base'] . '/accounts?page_size=100', null, $accessToken);
    if ($acctStatus < 200 || $acctStatus >= 300 || !is_array($acctData)) {
        $detail = $acctData['message'] ?? ($acctData['error']['message'] ?? '');
        throw new RuntimeException('Frame.io account lookup failed (' . $acctStatus . ')' . ($detail ? ': ' . $detail : '.') . ($acctStatus === 403 ? ' The authenticated Adobe/Frame.io user does not have V4 account access.' : ''));
    }

    $foundAccountCount = 0;
    $lastProjectStatus = 0;
    $lastProjectDetail = '';
    foreach (($acctData['data'] ?? []) as $acct) {
        if (!is_array($acct)) continue;
        $accountId = trim((string)($acct['id'] ?? ''));
        if ($accountId === '') continue;
        $foundAccountCount++;

        [$ps, $pd] = gbirds_frameio_http(
            'GET',
            $cfg['api_base'] . '/accounts/' . rawurlencode($accountId) . '/projects/' . rawurlencode($cfg['project_id']),
            null,
            $accessToken
        );
        $lastProjectStatus = $ps;
        $lastProjectDetail = (string)($pd['message'] ?? ($pd['error']['message'] ?? ''));
        if ($ps >= 200 && $ps < 300 && !empty($pd['data']) && is_array($pd['data'])) {
            $project = $pd['data'];
            $project['_frameio_account_id'] = $accountId;
            $project['_frameio_account_name'] = (string)($acct['name'] ?? $acct['display_name'] ?? $acct['displayName'] ?? '');
            gbirds_session_start();
            $_SESSION['frameio_account_id'] = $accountId;
            return [$project, $accountId];
        }
    }

    if ($foundAccountCount === 0) {
        throw new RuntimeException('Adobe IMS authentication succeeded, but Frame.io returned no accounts for this Adobe user.');
    }

    $detailSuffix = $lastProjectDetail ? ': ' . $lastProjectDetail : '';
    throw new RuntimeException(
        'The selected Adobe profile does not have access to Project_FIREBIRD (' . $cfg['project_id'] . '). ' .
        'Sign out of Adobe at account.adobe.com, retry Send to Adobe, and choose the Adobe profile that owns or has access to Project_FIREBIRD.' .
        ' (last project HTTP ' . $lastProjectStatus . ')' . $detailSuffix
    );
}

function gbirds_frameio_find_date_collection(string $accessToken, string $accountId, string $projectId, string $dateName): array {
    $cfg = gbirds_frameio_config();
    $safeDateName = preg_replace('/[^0-9-]/', '-', trim($dateName));
    $safeDateName = trim((string)$safeDateName, '-');
    if ($safeDateName === '') $safeDateName = gmdate('Y-m-d');

    gbirds_session_start();
    $cacheKey = $safeDateName;
    $cached = $_SESSION['frameio_date_collections'][$cacheKey] ?? null;
    if (is_array($cached) && !empty($cached['id']) && !empty($cached['root_folder_id'])) {
        return $cached;
    }

    [$status, $data] = gbirds_frameio_http(
        'GET',
        $cfg['api_base'] . '/accounts/' . rawurlencode($accountId) . '/projects/' . rawurlencode($projectId) . '/collections?page_size=100',
        null,
        $accessToken,
        ['api-version: experimental']
    );

    if ($status < 200 || $status >= 300 || !is_array($data)) {
        $detail = $data['message'] ?? ($data['error']['message'] ?? '');
        throw new RuntimeException(
            'Frame.io date-collection lookup failed (' . $status . ')' . ($detail ? ': ' . $detail : '.')
        );
    }

    foreach (($data['data'] ?? []) as $collection) {
        if (!is_array($collection)) continue;
        $name = trim((string)($collection['name'] ?? ''));
        $id = trim((string)($collection['id'] ?? ''));
        $root = trim((string)($collection['root_folder_id'] ?? ''));
        $collectionProjectId = trim((string)($collection['project_id'] ?? ''));
        if ($name === $safeDateName && $id !== '' && ($collectionProjectId === '' || $collectionProjectId === $projectId)) {
            $result = [
                'id' => $id,
                'name' => $name,
                'root_folder_id' => $root,
                'project_id' => $collectionProjectId ?: $projectId,
                'exists' => true
            ];
            $_SESSION['frameio_date_collections'][$cacheKey] = $result;
            return $result;
        }
    }

    return [
        'id' => '',
        'name' => $safeDateName,
        'root_folder_id' => '',
        'project_id' => $projectId,
        'exists' => false
    ];
}

function gbirds_sanitize_frameio_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return substr($name ?: 'bird-media', 0, 180);
}

/**
 * Ensure a date-named folder (Y-m-d) exists directly under Project_FIREBIRD's
 * root folder, creating it when missing. Frame.io V4 exposes no public
 * create-collection API, but it does expose folder create/list, so FireBird
 * uses a per-date folder as the required destination bucket. Returns
 * ['id' => <folder_id>, 'name' => <date>, 'created' => bool].
 */
function gbirds_frameio_find_or_create_date_folder(string $accessToken, string $accountId, string $rootFolderId, string $dateName): array {
    $cfg = gbirds_frameio_config();
    $safe = preg_replace('/[^0-9-]/', '-', trim($dateName));
    $safe = trim((string)$safe, '-');
    if ($safe === '') $safe = gmdate('Y-m-d');

    gbirds_session_start();
    $cacheKey = $rootFolderId . '|' . $safe;
    $cached = $_SESSION['frameio_date_folders'][$cacheKey] ?? null;
    if (is_array($cached) && !empty($cached['id'])) return $cached;

    $childrenUrl = $cfg['api_base'] . '/accounts/' . rawurlencode($accountId)
        . '/folders/' . rawurlencode($rootFolderId) . '/children?page_size=100';

    // 1) Reuse an existing date folder under the project root if one is present.
    $existing = gbirds_frameio_match_child_folder($accessToken, $childrenUrl, $safe);
    if ($existing !== '') {
        $result = ['id' => $existing, 'name' => $safe, 'created' => false];
        $_SESSION['frameio_date_folders'][$cacheKey] = $result;
        return $result;
    }

    // 2) Create the date folder under the project root.
    [$cstatus, $cdata] = gbirds_frameio_http(
        'POST',
        $cfg['api_base'] . '/accounts/' . rawurlencode($accountId) . '/folders/' . rawurlencode($rootFolderId) . '/folders',
        ['data' => ['name' => $safe]],
        $accessToken
    );
    $newId = trim((string)($cdata['data']['id'] ?? ''));
    if ($cstatus >= 200 && $cstatus < 300 && $newId !== '') {
        $result = ['id' => $newId, 'name' => $safe, 'created' => true];
        $_SESSION['frameio_date_folders'][$cacheKey] = $result;
        return $result;
    }

    // 3) A conflict (e.g. concurrent create) can mean it already exists; re-list once.
    $recovered = gbirds_frameio_match_child_folder($accessToken, $childrenUrl, $safe);
    if ($recovered !== '') {
        $result = ['id' => $recovered, 'name' => $safe, 'created' => false];
        $_SESSION['frameio_date_folders'][$cacheKey] = $result;
        return $result;
    }

    $detail = $cdata['message'] ?? ($cdata['error']['message'] ?? ($cdata['_raw'] ?? ''));
    throw new RuntimeException(
        'FireBird could not create the Frame.io date folder "' . $safe . '" (' . $cstatus . ')' . ($detail ? ': ' . $detail : '.')
    );
}

/**
 * List a folder's children (one page of up to 100) and return the id of the
 * first child folder whose name matches $name, or '' when none match.
 */
function gbirds_frameio_match_child_folder(string $accessToken, string $childrenUrl, string $name): string {
    [$status, $data] = gbirds_frameio_http('GET', $childrenUrl, null, $accessToken);
    if ($status < 200 || $status >= 300 || !is_array($data)) return '';
    foreach (($data['data'] ?? []) as $child) {
        if (!is_array($child)) continue;
        $type = strtolower(trim((string)($child['type'] ?? '')));
        $childName = trim((string)($child['name'] ?? ''));
        $id = trim((string)($child['id'] ?? ''));
        if ($childName === $name && $id !== '' && ($type === '' || $type === 'folder')) {
            return $id;
        }
    }
    return '';
}

/**
 * List a folder's children (one page of up to 100) and return the id of the first
 * child FILE (or version_stack) whose name matches $name, or '' when none match.
 * Used to detect a media that was already uploaded (dedupe).
 */
function gbirds_frameio_find_child_file_by_name(string $accessToken, string $childrenUrl, string $name): string {
    [$status, $data] = gbirds_frameio_http('GET', $childrenUrl, null, $accessToken);
    if ($status < 200 || $status >= 300 || !is_array($data)) return '';
    foreach (($data['data'] ?? []) as $child) {
        if (!is_array($child)) continue;
        $type = strtolower(trim((string)($child['type'] ?? '')));
        if (trim((string)($child['name'] ?? '')) === $name && ($type === 'file' || $type === 'version_stack' || $type === '')) {
            $id = trim((string)($child['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    return '';
}

/**
 * Resolve the uploadable root folder id for a target collection inside a project,
 * matched by id first then by (case-insensitive) name. Frame.io V4 has no
 * "show single collection" endpoint, so we list the project's collections and
 * match locally. Returns ['id'=>collectionId,'root_folder_id'=>folderId] with
 * empty strings when the collection is dynamic/aggregated (no uploadable root
 * folder), not found, or the listing endpoint is unavailable — the caller then
 * falls back to the project root.
 */
function gbirds_frameio_collection_root_folder(string $accessToken, string $accountId, string $projectId, string $collectionId, string $collectionName = ''): array {
    $none = ['id' => '', 'root_folder_id' => ''];
    if ($collectionId === '' && $collectionName === '') return $none;
    $cfg = gbirds_frameio_config();
    [$status, $data] = gbirds_frameio_http(
        'GET',
        $cfg['api_base'] . '/accounts/' . rawurlencode($accountId) . '/projects/' . rawurlencode($projectId) . '/collections?page_size=100',
        null,
        $accessToken,
        ['api-version: experimental']
    );
    if ($status < 200 || $status >= 300 || !is_array($data)) return $none;
    $wantName = strtolower(trim($collectionName));
    $byName = null;
    foreach (($data['data'] ?? []) as $collection) {
        if (!is_array($collection)) continue;
        $id = trim((string)($collection['id'] ?? ''));
        $root = trim((string)($collection['root_folder_id'] ?? ''));
        if ($collectionId !== '' && $id === $collectionId) {
            return ['id' => $id, 'root_folder_id' => $root];
        }
        if ($byName === null && $wantName !== '' && strtolower(trim((string)($collection['name'] ?? ''))) === $wantName) {
            $byName = ['id' => $id, 'root_folder_id' => $root];
        }
    }
    return $byName ?? $none;
}

function gbirds_frameio_start_auth_for_pending(array $pending): void {
    gbirds_session_start();
    $_SESSION['frameio_pending_upload'] = [
        'mediaUrl' => trim((string)($pending['mediaUrl'] ?? '')),
        'filename' => trim((string)($pending['filename'] ?? '')),
        'createdAt' => trim((string)($pending['createdAt'] ?? '')),
        'species' => trim((string)($pending['species'] ?? '')),
        'postcardId' => trim((string)($pending['postcardId'] ?? ''))
    ];
    // A deliberate first-click authentication gate: discard any old Frame.io
    // token so the Adobe IMS flow cannot silently reuse the wrong profile.
    unset(
        $_SESSION['frameio_access_token'],
        $_SESSION['frameio_refresh_token'],
        $_SESSION['frameio_expires_at'],
        $_SESSION['frameio_usage_authenticated'],
        $_SESSION['frameio_account_id'],
        $_SESSION['frameio_date_folders'],
        $_SESSION['frameio_ims_org_id'],
        $_SESSION['frameio_ims_user_id']
    );
    $_SESSION['frameio_usage_authenticated'] = false;
    try {
        $authorizeUrl = gbirds_frameio_authorize_url();
    } catch (Throwable $e) {
        gbirds_json_response(['ok'=>false,'error'=>$e->getMessage(),'stage'=>'frameio-auth-start'],500);
    }
    gbirds_json_response([
        'ok' => false,
        'needsAuth' => true,
        'forceLogin' => true,
        'authorizeUrl' => $authorizeUrl
    ], 401);
}

function gbirds_frameio_send(): void {
    gbirds_session_start();
    $cfg = gbirds_frameio_config();
    $cfg = gbirds_frameio_config();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (!is_array($input) || empty($input)) {
        $input = $GLOBALS['gbirds_frameio_pending_resume'] ?? ($_SESSION['frameio_pending_upload'] ?? []);
    }

    $mediaUrl = trim((string)($input['mediaUrl'] ?? ''));
    $filename = trim((string)($input['filename'] ?? ''));
    $createdAt = trim((string)($input['createdAt'] ?? ''));
    $species = trim((string)($input['species'] ?? ''));
    $postcardId = trim((string)($input['postcardId'] ?? ''));
    if ($mediaUrl === '' || !gbirds_allowed_media_url($mediaUrl)) {
        gbirds_json_response(['ok'=>false,'error'=>'Invalid BirdBuddy media URL.'],400);
    }
    if ($filename === '') {
        $filename = 'file_' . md5($mediaUrl) . (preg_match('/\.mp4(?:$|[?#])/i',$mediaUrl) ? '.mp4' : '.jpg');
    }

    // First Send to Adobe for this browser-tab usage always starts a fresh Adobe
    // IMS login. This prevents an existing server/browser session from selecting
    // the wrong Adobe profile before the user authenticates for FireBird.
    if (empty($_SESSION['frameio_usage_authenticated'])) {
        gbirds_frameio_start_auth_for_pending([
            'mediaUrl'=>$mediaUrl,
            'filename'=>$filename,
            'createdAt'=>$createdAt,
            'species'=>$species,
            'postcardId'=>$postcardId
        ]);
    }

    try {
        $token = gbirds_frameio_access_token();
    } catch (RuntimeException $e) {
        $_SESSION['frameio_pending_upload'] = [
            'mediaUrl'=>$mediaUrl,
            'filename'=>$filename,
            'createdAt'=>$createdAt,
            'species'=>$species,
            'postcardId'=>$postcardId
        ];
        gbirds_json_response([
            'ok'=>false,
            'needsAuth'=>true,
            'forceLogin'=>true,
            'authorizeUrl'=>gbirds_frameio_authorize_url()
        ],401);
    }

    try {
        [$project, $accountId] = gbirds_frameio_find_project($token);

        // Verify the authenticated Adobe/Frame.io identity before touching folders.
        // A valid IMS token can still represent the wrong Adobe profile, or a user
        // can have view-only access to Project_FIREBIRD. In either case, stop here
        // with a useful message rather than producing a confusing folder 403.
        $me = gbirds_frameio_get_me($token);
        $meData = is_array($me['data'] ?? null) ? $me['data'] : $me;
        $userId = (string)($meData['user_id'] ?? $meData['id'] ?? $meData['user']['id'] ?? '');
        $role = $userId !== '' ? gbirds_frameio_project_user_role($token, $accountId, $cfg['project_id'], $userId) : '';
        if ($role !== '' && !gbirds_frameio_role_allows_upload($role)) {
            throw new RuntimeException(
                'The authenticated Adobe profile has Frame.io project role "' . $role . '" and cannot upload/manage Project_FIREBIRD. ' .
                'Switch Adobe to the profile that has Editor or Full Access to Project_FIREBIRD, then retry Send to Adobe. ' .
                'Adobe does not provide an OAuth parameter that can force its profile chooser; its automatic profile selection is controlled by Adobe.'
            );
        }
        $dateTs = $createdAt !== '' ? strtotime($createdAt) : false;
        $dateName = $dateTs ? gmdate('Y-m-d', $dateTs) : gmdate('Y-m-d');

        // FireBird's destination is a DATE-SPECIFIC folder inside Project_FIREBIRD.
        // Base folder resolution order:
        //   1) An explicitly configured folder id (the "BIRDS" folder in Assets).
        //   2) The configured target COLLECTION's root_folder_id (static collection).
        //   3) The project root folder, if neither of the above is resolvable.
        // A date-named folder (Y-m-d) is then found-or-created under that base and
        // used as the upload target, guaranteeing the destination exists first.
        $wantFolderId = trim((string)($cfg['folder_id'] ?? ''));
        $wantCollectionId = trim((string)($cfg['collection_id'] ?? ''));
        $wantCollectionName = trim((string)($cfg['collection_name'] ?? ''));
        $collectionId = '';
        $baseFolderId = '';
        $destination = 'project';
        if ($wantFolderId !== '') {
            $baseFolderId = $wantFolderId;
            $destination = 'folder';
        }
        if ($baseFolderId === '' && ($wantCollectionId !== '' || $wantCollectionName !== '')) {
            // Experimental endpoint — never let it abort the upload.
            try {
                $resolved = gbirds_frameio_collection_root_folder($token, $accountId, $cfg['project_id'], $wantCollectionId, $wantCollectionName);
                $baseFolderId = trim((string)($resolved['root_folder_id'] ?? ''));
                $collectionId = trim((string)($resolved['id'] ?? ''));
            } catch (Throwable $collLookupError) {
                error_log('GetBirds Frame.io collection resolve skipped: ' . $collLookupError->getMessage());
            }
            if ($baseFolderId !== '') $destination = 'collection';
        }
        if ($baseFolderId === '') {
            $baseFolderId = trim((string)($project['root_folder_id'] ?? ($project['data']['root_folder_id'] ?? '')));
            $destination = 'project';
        }
        if ($baseFolderId === '') {
            throw new RuntimeException(
                'Project_FIREBIRD returned no uploadable folder (no collection root folder and no project root folder), ' .
                'so FireBird cannot create the "' . $dateName . '" date folder.'
            );
        }

        $dateFolder = gbirds_frameio_find_or_create_date_folder($token, $accountId, $baseFolderId, $dateName);
        $folderId = trim((string)($dateFolder['id'] ?? ''));
        if ($folderId === '') {
            throw new RuntimeException('FireBird could not resolve a Frame.io destination folder for "' . $dateName . '".');
        }

        $nameBits = [];
        if ($dateTs) $nameBits[] = gmdate('Y-m-d_H-i-s', $dateTs);
        if ($species !== '') $nameBits[] = $species;
        $nameBits[] = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $remoteName = gbirds_sanitize_frameio_filename(implode('_', $nameBits) . ($ext ? '.' . strtolower($ext) : ''));

        $cfg = gbirds_frameio_config();

        // DEDUPE (authoritative, server-side): the remote name is deterministic for
        // a given media (it embeds file_<md5(mediaUrl)>). If a file with that exact
        // name already exists in the date folder, this media was already pushed to
        // Frame.io — do NOT upload again; return the existing asset's preview link.
        $dupChildrenUrl = $cfg['api_base'] . '/accounts/' . rawurlencode($accountId)
            . '/folders/' . rawurlencode($folderId) . '/children?page_size=100';
        $existingFileId = gbirds_frameio_find_child_file_by_name($token, $dupChildrenUrl, $remoteName);
        if ($existingFileId !== '') {
            $dupViewUrl = 'https://next.frame.io/project/' . rawurlencode($cfg['project_id']) . '/view/' . rawurlencode($existingFileId);
            unset($_SESSION['frameio_pending_upload']);
            gbirds_json_response([
                'ok'=>true,
                'status'=>'already-sent',
                'duplicate'=>true,
                'projectId'=>$project['id'] ?? $cfg['project_id'],
                'projectName'=>$project['name'] ?? 'Project_FIREBIRD',
                'destination'=>$destination,
                'folderId'=>$folderId,
                'dateName'=>$dateName,
                'file'=>['id'=>$existingFileId,'name'=>$remoteName],
                'viewUrl'=>$dupViewUrl,
                'projectUrl'=>'https://next.frame.io/project/' . rawurlencode($cfg['project_id'])
            ]);
        }

        [$status, $upload] = gbirds_frameio_http(
            'POST',
            $cfg['api_base'] . '/accounts/' . rawurlencode($accountId) . '/folders/' . rawurlencode($folderId) . '/files/remote_upload',
            ['data'=>['name'=>$remoteName,'source_url'=>$mediaUrl]],
            $token
        );

        if ($status === 401) {
            gbirds_frameio_clear_session();
            $_SESSION['frameio_usage_authenticated'] = false;
            $_SESSION['frameio_pending_upload'] = [
                'mediaUrl'=>$mediaUrl,
                'filename'=>$filename,
                'createdAt'=>$createdAt,
                'species'=>$species,
                'postcardId'=>$postcardId
            ];
            try {
                $authorizeUrl = gbirds_frameio_authorize_url();
            } catch (Throwable $authUrlError) {
                error_log('GetBirds Frame.io reauthorize URL failed: ' . $authUrlError->getMessage());
                gbirds_json_response(['ok'=>false,'error'=>$authUrlError->getMessage(),'stage'=>'frameio-reauthorize'],500);
            }
            gbirds_json_response([
                'ok'=>false,
                'needsAuth'=>true,
                'authorizeUrl'=>$authorizeUrl
            ],401);
        }

        if ($status < 200 || $status >= 300 || empty($upload['data'])) {
            $msg = $upload['message'] ?? (($upload['error']['message'] ?? null) ?: ('Frame.io upload failed (' . $status . ').'));
            $detail = $upload['detail'] ?? ($upload['error']['detail'] ?? '');
            if ($detail && $detail !== $msg) $msg .= ' — ' . $detail;
            throw new RuntimeException($msg);
        }

        $fileData = is_array($upload['data']) ? $upload['data'] : [];
        $fileId = trim((string)($fileData['id'] ?? ''));
        // Build Frame.io's canonical single-asset PREVIEW deep link from the new
        // file id: /project/{project_id}/view/{file_id}. We construct this rather
        // than trusting the API's view_url, because for a freshly created
        // remote_upload view_url points at the PARENT FOLDER (lands in the Assets
        // folder) instead of the asset itself. Fall back to the API view_url only
        // when no file id is returned.
        $apiViewUrl = trim((string)($fileData['view_url'] ?? $fileData['viewUrl'] ?? ''));
        if ($fileId !== '') {
            $viewUrl = 'https://next.frame.io/project/' . rawurlencode($cfg['project_id']) . '/view/' . rawurlencode($fileId);
        } else {
            $viewUrl = $apiViewUrl;
        }
        unset($_SESSION['frameio_pending_upload']);
        gbirds_json_response([
            'ok'=>true,
            'status'=>'sent',
            'projectId'=>$project['id'] ?? $cfg['project_id'],
            'projectName'=>$project['name'] ?? 'Project_FIREBIRD',
            'destination'=>$destination,
            'folderId'=>$folderId,
            'collectionId'=>$destination === 'collection' ? $collectionId : '',
            'dateName'=>$dateName,
            'folderName'=>$dateName,
            'file'=>$fileData,
            'viewUrl'=>$viewUrl,
            'projectUrl'=>'https://next.frame.io/project/' . rawurlencode($cfg['project_id']),
            'links'=>$upload['links'] ?? null
        ]);
    } catch (Throwable $e) {
        // Never allow Frame.io failures to become an opaque Apache/PHP 500.
        // Return a JSON error so the browser can show the actual server-side cause.
        error_log('GetBirds Frame.io send failed: ' . $e->getMessage());
        gbirds_json_response([
            'ok'=>false,
            'error'=>$e->getMessage() ?: 'Frame.io send failed.',
            'stage'=>'frameio-send'
        ],500);
    }
}

function gbirds_frameio_resume_send(): void {
    gbirds_session_start();
    $pending = $_SESSION['frameio_pending_upload'] ?? null;
    if (!is_array($pending) || empty($pending['mediaUrl'])) {
        gbirds_json_response(['ok'=>false,'error'=>'No pending Adobe media upload was found.'],409);
    }
    $GLOBALS['gbirds_frameio_pending_resume'] = $pending;
    gbirds_frameio_send();
}

function gbirds_frameio_status(): void {
    gbirds_session_start();
    $configured = gbirds_frameio_config()['client_id'] !== '' && gbirds_frameio_config()['client_secret'] !== '';
    $authenticated = false;
    if ($configured) { try { $authenticated = gbirds_frameio_token_is_fresh() || gbirds_frameio_refresh(); } catch (Throwable $e) {} }
    // "ready" is the true "can Send to Adobe without logging in again" signal: the
    // browser-tab usage completed the Adobe login AND a usable token is available.
    // The client uses this to require only ONE master login per browser session.
    $ready = !empty($_SESSION['frameio_usage_authenticated']) && $authenticated;
    $cfg = gbirds_frameio_config();
    gbirds_json_response([
        'ok'=>true,
        'configured'=>$configured,
        'authenticated'=>$authenticated,
        'ready'=>$ready,
        'build'=>defined('FIREBIRD_BUILD_VERSION') ? FIREBIRD_BUILD_VERSION : 'unknown',
        'projectId'=>$cfg['project_id'],
        'collectionId'=>$cfg['collection_id']
    ]);
}

function gbirds_frameio_login(): void {
    try { header('Location: ' . gbirds_frameio_authorize_url(), true, 302); exit; } catch (Throwable $e) { gbirds_json_response(['ok'=>false,'error'=>$e->getMessage()],500); }
}

/**
 * Render the OAuth callback result for the POPUP auth flow. When opened as a
 * popup (window.opener present, same origin) it posts a message to the GetBirds
 * page and stays put so the opener can drive the upload and reuse this window for
 * the asset preview — the main app never reloads (smooth, YouTube-like UX).
 * When there is no opener (popup blocked → login happened in the main tab) it
 * falls back to the original full-page redirect so the flow still completes.
 */
function gbirds_frameio_render_popup_result(bool $ok, string $error = ''): void {
    $appBase = 'https://hh5hh.com/gbirds.php';
    $redirect = $ok
        ? ($appBase . (!empty($_SESSION['frameio_pending_upload']['mediaUrl']) ? '?frameio_resume=1' : '?frameio_connected=1'))
        : ($appBase . '?frameio_auth_error=1');
    // JSON_HEX_TAG escapes < and > so a stray "</script>" in an Adobe error string
    // can never break out of the inline <script> below.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $payload = json_encode(['type' => 'firebird-frameio-auth', 'ok' => $ok, 'error' => $error], $jsonFlags);
    $redirectJs = json_encode($redirect, $jsonFlags);
    $origin = json_encode('https://hh5hh.com', $jsonFlags);
    $msg = $ok ? 'Signed in to Adobe — finishing your upload…' : ('Adobe sign-in failed' . ($error !== '' ? ': ' . htmlspecialchars($error, ENT_QUOTES) : '.'));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Adobe sign-in</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0f1115;color:#e8eaed;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}'
        . '.card{max-width:420px}button{margin-top:16px;padding:10px 18px;border-radius:8px;border:0;background:#2b6cff;color:#fff;font-size:14px;cursor:pointer}</style></head>'
        . '<body><div class="card"><p>' . $msg . '</p>'
        . '<button type="button" onclick="__done()">Close</button></div>'
        . '<script>(function(){'
        . 'var payload=' . $payload . ';var origin=' . $origin . ';var redirect=' . $redirectJs . ';'
        . 'var hasOpener=false;try{hasOpener=!!(window.opener&&!window.opener.closed);}catch(e){hasOpener=!!window.opener;}'
        . 'if(hasOpener){try{window.opener.postMessage(payload,origin);}catch(e){}'
        // Popup: let the opener drive from here. On error close soon; on success
        // the opener navigates this window to the asset preview (or closes it).
        . 'window.__done=function(){try{window.close();}catch(e){}};'
        . 'if(!payload.ok){setTimeout(function(){try{window.close();}catch(e){}},1500);}'
        . '}else{'
        // No opener → this is the main tab (popup was blocked). Redirect the app.
        . 'window.__done=function(){window.location.replace(redirect);};'
        . 'window.location.replace(redirect);'
        . '}'
        . '})();</script></body></html>';
    exit;
}

function gbirds_frameio_callback(): void {
    gbirds_session_start();

    // Handle IMS authorization errors first. Adobe may omit state on an error
    // response such as invalid_scope; never misreport that as an OAuth state error.
    if (!empty($_GET['error'])) {
        $error = trim((string)($_GET['error'] ?? 'authorization_failed'));
        $description = trim((string)($_GET['error_description'] ?? 'Adobe authorization was cancelled or denied.'));
        unset($_SESSION['frameio_oauth_state']);
        unset($_SESSION['frameio_access_token'], $_SESSION['frameio_refresh_token'], $_SESSION['frameio_expires_at'], $_SESSION['frameio_usage_authenticated']);
        $_SESSION['frameio_auth_error'] = $error . ($description ? ': ' . $description : '');
        gbirds_frameio_render_popup_result(false, $error . ($description ? ': ' . $description : ''));
    }

    $state = (string)($_GET['state'] ?? '');
    $expectedState = (string)($_SESSION['frameio_oauth_state'] ?? '');
    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        unset($_SESSION['frameio_oauth_state']);
        gbirds_frameio_render_popup_result(false, 'Invalid Adobe OAuth state.');
    }
    unset($_SESSION['frameio_oauth_state']);

    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        gbirds_frameio_render_popup_result(false, 'Adobe IMS returned no authorization code.');
    }

    try {
        $tokens = gbirds_frameio_exchange_code($code);
        gbirds_frameio_store_tokens($tokens);
        $_SESSION['frameio_usage_authenticated'] = true;
        $_SESSION['frameio_authenticated_at'] = time();
        unset($_SESSION['frameio_account_id']);
        unset($_SESSION['frameio_auth_error']);
        gbirds_frameio_capture_ims_context($tokens);
    } catch (Throwable $e) {
        $_SESSION['frameio_auth_error'] = $e->getMessage();
        gbirds_frameio_render_popup_result(false, $e->getMessage());
    }

    gbirds_frameio_render_popup_result(true);
}

function gbirds_frameio_auth_config(): void {
    $cfg = gbirds_frameio_config();
    gbirds_json_response([
        'ok' => true,
        'configured' => $cfg['client_id'] !== '' && $cfg['client_secret'] !== '',
        'clientIdPresent' => $cfg['client_id'] !== '',
        'clientSecretPresent' => $cfg['client_secret'] !== '',
        'redirectUri' => $cfg['redirect_uri'],
        'scopes' => preg_split('/\\s*[,\\s]+\\s*/', trim($cfg['scopes'])) ?: [],
        'projectId' => $cfg['project_id']
    ]);
}

function gbirds_frameio_debug(): void {
    try {
        $token = gbirds_frameio_access_token();
        $cfg = gbirds_frameio_config();
        [$meStatus, $meData] = gbirds_frameio_http('GET', $cfg['api_base'] . '/me', null, $token);
        [$acctStatus, $acctData] = gbirds_frameio_http('GET', $cfg['api_base'] . '/accounts', null, $token);
        $projectStatus = null; $project = null; $accountId = null;
        foreach (($acctData['data'] ?? []) as $acct) {
            $aid = (string)($acct['id'] ?? '');
            if (!$aid) continue;
            [$ps, $pd] = gbirds_frameio_http('GET', $cfg['api_base'] . '/accounts/' . rawurlencode($aid) . '/projects/' . rawurlencode($cfg['project_id']), null, $token);
            if ($ps >= 200 && $ps < 300 && !empty($pd['data'])) { $projectStatus = $ps; $project = $pd['data']; $accountId = $aid; break; }
            $projectStatus = $ps;
        }
        gbirds_json_response([
            'ok'=>true,
            'me'=>['http'=>$meStatus,'userId'=>$meData['user_id'] ?? ($meData['data']['id'] ?? null),'email'=>$meData['email'] ?? ($meData['data']['email'] ?? null)],
            'accounts'=>['http'=>$acctStatus,'count'=>is_array($acctData['data'] ?? null) ? count($acctData['data']) : 0],
            'project'=>['id'=>$cfg['project_id'],'http'=>$projectStatus,'found'=>!!$project,'accountId'=>$accountId,'name'=>$project['name'] ?? null,'rootFolderId'=>$project['root_folder_id'] ?? null]
        ]);
    } catch (Throwable $e) {
        gbirds_json_response(['ok'=>false,'error'=>$e->getMessage(),'stage'=>'frameio-debug'],500);
    }
}


function gbirds_frameio_collections_debug(): void {
    try {
        $token = gbirds_frameio_access_token();
        $cfg = gbirds_frameio_config();
        [$project, $accountId] = gbirds_frameio_find_project($token);
        $collections = gbirds_frameio_find_date_collection($token, $accountId, $cfg['project_id'], gmdate('Y-m-d'));
        gbirds_json_response([
            'ok'=>true,
            'projectId'=>$cfg['project_id'],
            'projectName'=>$project['name'] ?? 'Project_FIREBIRD',
            'accountId'=>$accountId,
            'date'=>gmdate('Y-m-d'),
            'collection'=>$collections
        ]);
    } catch (Throwable $e) {
        gbirds_json_response(['ok'=>false,'error'=>$e->getMessage(),'stage'=>'frameio-collections-debug'],500);
    }
}

function gbirds_firebird_placeholder(): void {
    gbirds_json_response([
        'ok' => false,
        'service' => 'firebird',
        'status' => 'not_configured',
        'message' => 'FireBird Adobe art-seed pipeline endpoint is reserved for the next build.'
    ], 501);
}


if (!empty($_GET['frameio_resume'])) {
    gbirds_session_start();
    // Keep the pending payload in session until the resume request succeeds.
    // The previous build unset it here, so frameio-resume-send had nothing to read.
    if (!empty($_SESSION['frameio_pending_upload']['mediaUrl'])) {
        $GLOBALS['gbirds_frameio_pending_resume'] = $_SESSION['frameio_pending_upload'];
    }
}

$api = strtolower(trim((string)($_GET['api'] ?? '')));
if ($api === 'birdbuddy') gbirds_proxy_graphql();
if ($api === 'birdbuddy-media') gbirds_proxy_media();
if ($api === 'frameio-auth-begin') gbirds_frameio_begin_auth();
if ($api === 'frameio-auth-reset') {
    gbirds_frameio_clear_session();
    header('Location: https://account.adobe.com/', true, 302);
    exit;
}
if ($api === 'frameio-login') gbirds_frameio_login();
if ($api === 'frameio-callback') gbirds_frameio_callback();
if ($api === 'frameio-status') gbirds_frameio_status();
if ($api === 'frameio-begin-usage') gbirds_frameio_begin_usage();
if ($api === 'frameio-send') gbirds_frameio_send();
if ($api === 'frameio-resume-send') gbirds_frameio_resume_send();
if ($api === 'frameio-auth-config') gbirds_frameio_auth_config();
if ($api === 'frameio-debug') gbirds_frameio_debug();
if ($api === 'frameio-collections-debug') gbirds_frameio_collections_debug();
if ($api === 'frameio-logout') { gbirds_frameio_clear_session(); gbirds_json_response(['ok'=>true]); }
if ($api === 'firebird-art-seed') gbirds_firebird_placeholder();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>GetBirds — Your postcards</title>
  <link rel="preconnect" href="https://accounts.google.com" />
  <link rel="preconnect" href="https://graphql.app-api.prod.aws.mybirdbuddy.com" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; }

    :root {
      --bg: #f5f1f8;
      --surface: #ffffff;
      --surface-alt: #ece5f1;
      --border: #d3c7da;
      --text: #211b26;
      --text-soft: #51445a;
      --muted: #76697c;
      --accent: #6f3c8f;
      --accent-hover: #572d73;
      --accent-light: #eadff0;
      --error: #c23d3d;
      --radius: 12px;
      --radius-sm: 8px;
      --media-radius: 22px;
      --shadow: 0 2px 8px rgba(0,0,0,0.06);
      --shadow-hover: 0 4px 16px rgba(0,0,0,0.08);
    }

    html, body {
      margin: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: "Plus Jakarta Sans", system-ui, -apple-system, sans-serif;
      -webkit-font-smoothing: antialiased;
    }

    .screen {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }

    .screen.hidden { display: none !important; }

    /* When login screen is visible it fully covers the viewport; no app UI shows through. */
    #loginScreen:not(.hidden) {
      position: fixed;
      inset: 0;
      width: 100%;
      height: 100%;
      background: var(--bg);
      z-index: 10;
    }

    .brand {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--accent);
      letter-spacing: -0.02em;
      margin-bottom: 0.5rem;
    }

    .tagline {
      font-size: 0.9375rem;
      color: var(--text-soft);
      margin-bottom: 2rem;
    }

    .login-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      padding: 0.875rem 1.5rem;
      font-size: 1rem;
      font-weight: 600;
      font-family: inherit;
      color: #fff;
      background: var(--accent);
      border: none;
      border-radius: var(--radius-sm);
      cursor: pointer;
      box-shadow: var(--shadow);
      transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
    }

    .login-btn:hover:not(:disabled) {
      background: var(--accent-hover);
      box-shadow: var(--shadow-hover);
      transform: translateY(-1px);
    }

    .login-btn:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }

    .login-btn svg {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }

    .logged-in {
      display: grid;
      grid-template-rows: auto minmax(0, 1fr) auto;
      height: 100vh;
      width: 100%;
      overflow: hidden;
      position: relative;
      isolation: isolate;
    }

    .header {
      position: sticky;
      top: 0;
      z-index: 40;
      display: flex;
      flex-direction: column;
      align-items: stretch;
      padding: 0.875rem 1.25rem 0.75rem;
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      gap: 0.7rem;
      box-shadow: var(--shadow);
    }

    .header-main {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      align-items: center;
      gap: 1rem;
      min-width: 0;
    }

    .header-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid var(--border);
      background: var(--surface-alt);
    }

    .header-title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--text);
    }

    .header-status {
      flex: 1;
      min-width: 0;
      text-align: center;
      font-size: 0.875rem;
      font-weight: 500;
      color: var(--text-soft);
      padding: 0 1rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .header-status:empty { display: none; }
    .header-status.error { color: var(--error); }
    .header-status.youtube-limit {
      max-width: 720px;
      margin: 0 auto;
      padding: 0.45rem 0.8rem;
      border-radius: 8px;
      border: 1px solid rgba(194, 61, 61, 0.45);
      background: rgba(194, 61, 61, 0.1);
      color: #8e1f1f;
      white-space: normal;
      overflow: visible;
      text-overflow: clip;
      line-height: 1.35;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .btn {
      padding: 0.5rem 1rem;
      font-size: 0.875rem;
      font-weight: 600;
      font-family: inherit;
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: background 0.2s, border-color 0.2s;
    }

    .btn:hover:not(:disabled) {
      background: var(--surface-alt);
      border-color: var(--accent);
      color: var(--accent);
    }

    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .btn.btn-uploading {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      white-space: nowrap;
    }

    .btn.btn-uploading::before {
      content: "";
      position: static;
      width: 0.85rem;
      height: 0.85rem;
      border: 2px solid currentColor;
      border-right-color: transparent;
      border-radius: 50%;
      animation: btn-spin 0.8s linear infinite;
      flex: 0 0 auto;
    }

    @keyframes btn-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    .btn-primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .btn-primary:hover:not(:disabled) {
      background: var(--accent-hover);
      border-color: var(--accent-hover);
      color: #fff;
    }

    .media-area {
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 1rem 1.25rem;
      background: var(--bg);
      position: relative;
      z-index: 1;
    }

    .media-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: 1rem;
      align-content: start;
    }

    .media-card {
      position: relative;
      background: var(--surface);
      border-radius: var(--media-radius);
      overflow: hidden;
      border: 1px solid rgba(26, 26, 26, 0.08);
      aspect-ratio: 3 / 4;
      box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
      transition: transform 0.25s ease, box-shadow 0.25s ease;
      cursor: pointer;
    }

    .media-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 34px rgba(0, 0, 0, 0.17);
    }

    .media-card img,
    .media-card video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
      display: block;
    }

    .media-card video {
      background: #000;
    }

    .media-card .media-link {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: 0.5rem 0.6rem;
      background: rgba(26, 26, 26, 0.85);
      font-size: 0.75rem;
      font-weight: 500;
      text-align: center;
      color: #fff;
      text-decoration: none;
      opacity: 0;
      transition: opacity 0.2s;
    }

    .media-card:hover .media-link {
      opacity: 1;
    }

    .media-link:hover {
      background: var(--accent);
    }

    .media-actions {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      display: flex;
      opacity: 0;
      transition: opacity 0.2s;
      z-index: 3;
    }
    .media-card:hover .media-actions, .media-card:focus-within .media-actions { opacity: 1; }
    .media-actions .media-link, .media-actions .media-send-link {
      position: static;
      flex: 1 1 50%;
      width: auto;
      opacity: 1;
      border: 0;
      border-radius: 0;
      padding: 0.55rem 0.45rem;
      background: rgba(26,26,26,0.88);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 600;
      text-align: center;
      text-decoration: none;
      cursor: pointer;
    }
    .media-actions .media-send-link { border-left: 1px solid rgba(255,255,255,0.18); }
    .media-actions .media-link:hover { background: rgba(26,26,26,0.96); color: #fff; }
    .media-actions .media-send-link:hover { background: var(--accent); color: #fff; }
    .media-actions .media-send-link.sent { background: #356b52; }
    .media-actions .media-send-link.frameio-sent { background: #2f5a46; cursor: default; }
    .media-actions .media-send-link.frameio-sent:hover { background: #356b52; color: #dfeee7; }
    .media-actions .media-send-link.uploading { opacity: 0.85; pointer-events: none; }
    @media (max-width: 640px) { .media-actions { opacity: 1; } }


    .media-loading,
    .media-error,
    .media-empty {
      padding: 2rem;
      text-align: center;
      color: var(--muted);
      font-size: 0.9375rem;
    }

    .media-error { color: var(--error); }

    .load-more {
      padding: 0.6rem 1.2rem;
      font-size: 0.875rem;
      font-weight: 600;
      font-family: inherit;
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: background 0.2s, border-color 0.2s;
    }

    .load-more:hover:not(:disabled) {
      background: var(--accent-light);
      border-color: var(--accent);
      color: var(--accent);
    }

    .load-more:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .load-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      justify-content: center;
      margin: 1rem 0;
    }

    /* —— Postcard groups (one postcard = one visit = 1+ images/videos) —— */
    .media-grid {
      display: block;
    }

    .postcard-group {
      margin-bottom: 1.5rem;
      padding: 0.75rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }

    .postcard-group-label {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--accent);
      margin-bottom: 0.75rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--accent-light);
    }

    .postcard-group-label .badge {
      font-weight: 500;
      color: var(--text-soft);
      background: var(--surface-alt);
      padding: 0.2rem 0.5rem;
      border-radius: 4px;
    }

    .postcard-group-media {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 1rem;
      align-content: start;
    }

    .postcard-group-media .media-card {
      min-height: 0;
    }

    @media (max-width: 980px) {
      .postcard-group-media {
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
      }
    }

    @media (max-width: 640px) {
      .postcard-group-media {
        grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
        gap: 0.75rem;
      }
      .media-card {
        border-radius: 18px;
      }
    }

    .postcard-group-footer {
      margin-top: 0.75rem;
      padding-top: 0.75rem;
      border-top: 1px solid var(--border);
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .postcard-group-meta {
      font-size: 0.75rem;
      color: var(--muted);
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem 1rem;
    }

    .postcard-group-meta .meta-row {
      display: inline-flex;
      align-items: baseline;
      gap: 0.25rem;
    }

    .postcard-group-meta .meta-label {
      font-weight: 600;
      color: var(--text-soft);
    }

    .postcard-group-meta .meta-value {
      color: var(--muted);
    }

    .postcard-group-meta .meta-row.meta-row-checkbox {
      align-items: center;
    }

    .postcard-group-meta .meta-row.meta-row-checkbox .meta-value {
      display: inline-flex;
      align-items: center;
    }

    .postcard-group-meta .meta-row.meta-row-checkbox input[type="checkbox"] {
      margin: 0;
      width: 0.9rem;
      height: 0.9rem;
      accent-color: var(--accent);
      cursor: default;
    }

    .postcard-group-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
    }

    .postcard-group-actions .btn {
      padding: 0.4rem 0.75rem;
      font-size: 0.8125rem;
    }

    .footer-rail {
      position: sticky;
      bottom: 0;
      z-index: 35;
      background: var(--surface);
      border-top: 1px solid var(--border);
      box-shadow: 0 -2px 8px rgba(0,0,0,0.04);
    }

    /* —— Pinned footer rail —— */
    .app-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 0.75rem;
      padding: 0.8rem 1.25rem;
      background: var(--surface);
      border-top: none;
      box-shadow: none;
    }

    .footer-stats {
      font-size: 0.9375rem;
      font-weight: 600;
      color: var(--text);
    }

    .footer-stats .muted {
      font-weight: 500;
      color: var(--muted);
    }

    .footer-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      align-items: center;
    }

    .btn-mega {
      padding: 0.75rem 1.5rem;
      font-size: 1rem;
      font-weight: 700;
      font-family: inherit;
      color: #fff;
      background: var(--accent);
      border: none;
      border-radius: var(--radius-sm);
      cursor: pointer;
      box-shadow: var(--shadow);
      transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
    }

    .btn-mega:hover:not(:disabled) {
      background: var(--accent-hover);
      box-shadow: var(--shadow-hover);
      transform: translateY(-1px);
    }

    .btn-mega:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .camera-info-bar {
      display: flex;
      flex-wrap: nowrap;
      align-items: center;
      gap: 0.75rem;
      overflow-x: auto;
      overflow-y: hidden;
      white-space: nowrap;
      scrollbar-width: thin;
      -webkit-overflow-scrolling: touch;
    }

    .camera-info-bar.hidden { display: none; }

    .camera-info-bar-footer {
      padding: 0.45rem 1rem 0.6rem;
      border-top: 1px solid var(--border);
      background: var(--surface);
      overflow: hidden;
    }

    .camera-card {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      width: auto;
      text-align: left;
      padding: 0.4rem 0.75rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 999px;
      box-shadow: none;
      cursor: pointer;
      transition: border-color 0.2s, background 0.2s, transform 0.2s;
      color: var(--text);
      font-family: inherit;
      max-width: 260px;
      overflow: hidden;
    }

    .camera-card:hover {
      border-color: var(--accent);
      background: var(--accent-light);
      transform: translateY(-1px);
    }

    .camera-card.active {
      border-color: var(--accent);
      background: var(--accent);
      color: #fff;
    }

    .camera-card .camera-title {
      font-size: 0.78rem;
      font-weight: 700;
      color: inherit;
      margin: 0;
      line-height: 1.15;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .camera-card .camera-meta {
      display: none;
    }

    .species-filter-bar {
      margin: 0;
      padding: 0.55rem 0.65rem;
      background: var(--surface-alt);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.5rem;
    }

    .species-filter-bar.hidden { display: none; }

    .species-filter-bar .filter-label {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--text-soft);
      margin-right: 0.25rem;
    }

    .species-chip {
      display: inline-block;
      padding: 0.35rem 0.65rem;
      font-size: 0.8125rem;
      font-weight: 500;
      font-family: inherit;
      color: var(--text);
      background: var(--surface-alt);
      border: 1px solid var(--border);
      border-radius: 6px;
      cursor: pointer;
      transition: background 0.2s, border-color 0.2s;
    }

    .species-chip:hover {
      background: var(--accent-light);
      border-color: var(--accent);
      color: var(--accent);
    }

    .species-chip.active {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
    }

    @media (max-width: 980px) {
      .header-main {
        grid-template-columns: 1fr;
        gap: 0.55rem;
      }
      .header-status {
        text-align: left;
        padding: 0;
      }
      .header-actions {
        justify-content: flex-start;
      }
    }

    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 1rem;
    }
    .modal-overlay.hidden { display: none !important; }
    .modal {
      background: var(--surface);
      border-radius: var(--radius);
      box-shadow: 0 8px 32px rgba(0,0,0,0.15);
      max-width: 96vw;
      width: 420px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .modal-header {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.75rem 1rem;
      border-bottom: 1px solid var(--border);
      font-weight: 600;
    }
    .modal-close {
      padding: 0.35rem 0.6rem;
      font-size: 1rem;
      line-height: 1;
      background: transparent;
      border: none;
      color: var(--muted);
      cursor: pointer;
      border-radius: var(--radius-sm);
    }
    .modal-close:hover { color: var(--text); background: var(--surface-alt); }
    .modal-body {
      padding: 1rem 1.25rem;
    }
    .modal-body .modal-message {
      margin: 0 0 1rem;
      font-size: 0.9375rem;
      color: var(--text);
      line-height: 1.5;
    }
    .modal-body .modal-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      align-items: center;
    }
    .modal-body .modal-actions .btn {
      padding: 0.5rem 1rem;
      font-size: 0.875rem;
      font-weight: 600;
      font-family: inherit;
      color: #fff;
      background: var(--accent);
      border: none;
      border-radius: var(--radius-sm);
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
    }
    .modal-body .modal-actions .btn:hover { background: var(--accent-hover); }

    .btn-tv {
      padding: 0.4rem 0.5rem;
      margin-left: 0.25rem;
      border-radius: var(--radius-sm);
      color: var(--text-soft);
    }
    .btn-tv:hover { color: var(--accent); }
    .btn-tv .tv-icon { display: block; }

    .getbirds-tv-overlay {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: #000;
      display: flex;
      align-items: stretch;
      justify-content: center;
    }
    .getbirds-tv-overlay.hidden { display: none !important; }
    .getbirds-tv-stage {
      display: flex;
      flex-direction: row;
      align-items: stretch;
      justify-content: center;
      width: 100%;
      height: 100%;
      min-width: 0;
    }
    .getbirds-tv-panel {
      flex: 1;
      min-width: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #000;
      cursor: pointer;
      overflow: hidden;
      position: relative;
    }
    .getbirds-tv-panel::after {
      content: "";
      position: absolute;
      inset: 0;
      background: rgba(0,0,0,0.55);
      pointer-events: none;
    }
    .getbirds-tv-panel img {
      max-width: 100%;
      max-height: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
      opacity: 0.7;
      filter: brightness(0.7);
      display: block;
      position: relative;
      z-index: 0;
    }
    .getbirds-tv-panel:focus { outline: 2px solid rgba(255,255,255,0.4); outline-offset: -2px; }
    .getbirds-tv-video-wrap {
      position: relative;
      flex: 1 1 0;
      min-width: 0;
      min-height: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
    }
    .getbirds-tv-video,
    .getbirds-tv-image {
      max-width: 100%;
      max-height: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
      display: block;
    }
    .getbirds-tv-video.hidden { display: none !important; }
    .getbirds-tv-image {
      position: absolute;
      inset: 0;
      margin: auto;
    }
    .getbirds-tv-image.hidden { display: none !important; }
    .getbirds-tv-download {
      position: absolute;
      bottom: 1rem;
      right: 1rem;
      z-index: 10;
      width: 2.25rem;
      height: 2.25rem;
      padding: 0;
      font-size: 1rem;
      line-height: 1;
      color: rgba(255,255,255,0.9);
      background: rgba(0,0,0,0.5);
      border: 1px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      cursor: pointer;
      pointer-events: auto;
      text-decoration: none;
    }
    .getbirds-tv-download:hover { background: rgba(0,0,0,0.75); color: #fff; }
    .getbirds-tv-overlays {
      position: absolute;
      inset: 0;
      pointer-events: none;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      padding: 1rem 1.25rem;
    }
    .getbirds-tv-bug {
      position: absolute;
      top: 1rem;
      right: 1rem;
      font-size: 0.8rem;
      font-weight: 700;
      color: rgba(255,255,255,0.85);
      text-shadow: 0 1px 2px rgba(0,0,0,0.6);
      letter-spacing: 0.02em;
    }
    .getbirds-tv-banner {
      font-size: 0.7rem;
      font-weight: 600;
      color: rgba(255,255,255,0.7);
      text-shadow: 0 1px 2px rgba(0,0,0,0.5);
      margin-bottom: 0.25rem;
    }
    .getbirds-tv-lower-third {
      background: linear-gradient(90deg, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.5) 70%, transparent 100%);
      color: #fff;
      padding: 0.5rem 1rem 0.5rem 1rem;
      font-size: 0.9rem;
      font-weight: 600;
      max-width: 70%;
      text-shadow: 0 1px 2px rgba(0,0,0,0.8);
    }
    .getbirds-tv-snipe {
      position: absolute;
      bottom: 4rem;
      left: 1rem;
      background: rgba(0,0,0,0.6);
      color: rgba(255,255,255,0.9);
      padding: 0.35rem 0.6rem;
      font-size: 0.75rem;
      border-radius: 4px;
      max-width: 200px;
      animation: getbirds-tv-snipe-in 0.3s ease-out;
    }
    @keyframes getbirds-tv-snipe-in {
      from { opacity: 0; transform: translateX(-8px); }
      to { opacity: 1; transform: translateX(0); }
    }
    .getbirds-tv-close {
      position: absolute;
      top: 1rem;
      left: 1rem;
      z-index: 10;
      width: 2.5rem;
      height: 2.5rem;
      padding: 0;
      font-size: 1.5rem;
      line-height: 1;
      color: rgba(255,255,255,0.9);
      background: rgba(0,0,0,0.5);
      border: 1px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      cursor: pointer;
      pointer-events: auto;
    }
    .getbirds-tv-close:hover { background: rgba(0,0,0,0.75); color: #fff; }
    .getbirds-tv-unmute {
      position: absolute;
      top: 1rem;
      left: 3.5rem;
      z-index: 10;
      width: 2.5rem;
      height: 2.5rem;
      padding: 0;
      font-size: 1.1rem;
      line-height: 1;
      color: rgba(255,255,255,0.9);
      background: rgba(0,0,0,0.5);
      border: 1px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      cursor: pointer;
      pointer-events: auto;
    }
    .getbirds-tv-unmute:hover { background: rgba(0,0,0,0.75); color: #fff; }
    .getbirds-tv-unmute.muted { opacity: 0.7; }
  </style>
</head>
<body>
  <main id="loginScreen" class="screen">
    <h1 class="brand">GetBirds</h1>
    <p class="tagline">chirp chirp</p>
    <button type="button" id="loginBtn" class="login-btn">
      <svg viewBox="0 0 24 24">
        <path fill="#fff" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
        <path fill="#fff" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
        <path fill="#fff" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
        <path fill="#fff" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
      </svg>
      Sign in with Google
    </button>
  </main>

  <main id="loggedInScreen" class="logged-in hidden">
    <header class="header">
      <div class="header-main">
        <div class="header-profile">
          <img id="avatar" class="avatar" src="" alt="" />
          <span class="header-title">GetBirds</span>
          <button type="button" id="getbirdsTvBtn" class="btn btn-tv" title="GetBirds.TV — Live stream visible postcard videos" aria-label="Open GetBirds.TV">
            <svg class="tv-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>
          </button>
        </div>
        <div id="headerStatus" class="header-status" aria-live="polite"></div>
        <div class="header-actions">
          <button type="button" id="downloadAllBtn" class="btn btn-primary" hidden>Download all</button>
          <button type="button" id="signoutBtn" class="btn">Sign out</button>
        </div>
      </div>
      <div id="speciesFilterBar" class="species-filter-bar hidden" aria-label="Filter postcards"></div>
    </header>
    <div class="media-area">
      <div id="mediaGrid" class="media-grid"></div>
      <p id="mediaStatus" class="media-loading" hidden>Loading postcards…</p>
      <p id="mediaError" class="media-error" hidden></p>
      <p id="mediaEmpty" class="media-empty" hidden>No postcards yet.</p>
    </div>
    <div class="footer-rail">
      <footer id="appFooter" class="app-footer" aria-live="polite">
        <div class="footer-stats" id="footerStats">0 postcards displayed</div>
        <div class="footer-actions" id="footerActions">
          <button type="button" id="loadMoreBtn" class="load-more" hidden>Load more</button>
          <button type="button" id="loadAllBtn" class="load-more" hidden>Load all postcards</button>
          <button type="button" id="downloadAllMediaBtn" class="btn-mega" hidden>DOWNLOAD ALL MEDIA</button>
        </div>
      </footer>
      <div id="cameraInfoBar" class="camera-info-bar camera-info-bar-footer hidden" aria-label="BirdBuddy cameras"></div>
    </div>
  </main>

  <div id="youtubeUploadModalOverlay" class="modal-overlay hidden" aria-modal="true" aria-labelledby="youtubeModalTitle">
    <div class="modal">
      <div class="modal-header">
        <span id="youtubeModalTitle">YouTube</span>
        <button type="button" class="modal-close" id="youtubeModalClose" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p id="youtubeModalMessage" class="modal-message"></p>
        <div class="modal-actions">
          <a id="youtubeModalOpenStudio" href="https://studio.youtube.com" target="_blank" rel="noopener" class="btn">Open YouTube Studio</a>
        </div>
      </div>
    </div>
  </div>

  <div id="getbirdsTvOverlay" class="getbirds-tv-overlay hidden" aria-label="GetBirds.TV broadcast">
    <div class="getbirds-tv-stage">
      <div id="getbirdsTvPrevPanel" class="getbirds-tv-panel getbirds-tv-prev" aria-label="Play previous" role="button" tabindex="0"></div>
      <div class="getbirds-tv-video-wrap">
        <video id="getbirdsTvVideo" class="getbirds-tv-video" autoplay playsinline></video>
        <img id="getbirdsTvImage" class="getbirds-tv-image hidden" alt="" />
      </div>
      <div id="getbirdsTvNextPanel" class="getbirds-tv-panel getbirds-tv-next" aria-label="Play next" role="button" tabindex="0"></div>
    </div>
    <div class="getbirds-tv-overlays">
      <div class="getbirds-tv-bug" aria-hidden="true">GetBirds.TV</div>
      <div class="getbirds-tv-banner" aria-hidden="true">GetBirds.TV by HH5HH</div>
      <div id="getbirdsTvLowerThird" class="getbirds-tv-lower-third" aria-hidden="true"></div>
      <div id="getbirdsTvSnipe" class="getbirds-tv-snipe" aria-hidden="true"></div>
    </div>
    <button type="button" id="getbirdsTvDownload" class="getbirds-tv-download" aria-label="Download" title="Download" style="display: none;">↓</button>
    <button type="button" id="getbirdsTvUnmute" class="getbirds-tv-unmute" aria-label="Unmute">🔊</button>
    <button type="button" id="getbirdsTvClose" class="getbirds-tv-close" aria-label="Close GetBirds.TV">×</button>
  </div>

  <script>
(function () {
  "use strict";

  const CLIENT_ID = "1022751350446-o1m86gkguv2br1hfr1s2efau3aoj0jf2.apps.googleusercontent.com";
  /* Sign-in only: no restricted scopes so login does not get 403. */
  const SCOPE = "openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile";
  /* Requested only when user clicks Save to YouTube (upload, playlist, thumbnails). force-ssl can help thumbnail set. */
  const SCOPE_YOUTUBE = "openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.force-ssl";
  const USERINFO_URL = "https://openidconnect.googleapis.com/v1/userinfo";
  const REVOKE_URL = "https://oauth2.googleapis.com/revoke";
  const BB_GRAPHQL = "./gbirds.php?api=birdbuddy";
  const BB_GRAPHQL_OVERRIDE_KEY = "bb_graphql_override";
  const BB_GRAPHQL_OVERRIDE_QUERY_KEYS = ["bb_graphql", "bbGraphql", "graphql"];
  const BB_GRAPHQL_ALLOW_DIRECT_QUERY_KEYS = ["bb_graphql_direct", "bbGraphqlDirect"];
  const BIRDBUDDY_MEDIA_PROXY = "./gbirds.php?api=birdbuddy-media";
  const FRAMEIO_USAGE_KEY = "firebird_firebird_frameio_usage_id_v4";
  const FRAMEIO_BEGIN_USAGE = "./gbirds.php?api=frameio-begin-usage";
  const GBIRDS_FRAMEIO_BASE = "https://hh5hh.com/gbirds.php";
  const FRAMEIO_PROJECT_ID = "f7f67254-9ec8-4e2c-99f8-32cd31123eef";
  // Durable (per-browser) map of media already pushed to Frame.io: mediaKey -> viewUrl.
  const FRAMEIO_SENT_KEY = "firebird_frameio_sent_v1";
  // Fast-path flag: server session is known-authenticated, so Send to Adobe can
  // skip re-login (enforces "one master login" per browser session).
  const FRAMEIO_AUTHED_FLAG = "firebird_frameio_authed_v1";

  const STORAGE_KEYS = {
    googleAccessToken: "google_access_token",
    googleExpiresAt: "google_expires_at",
    picture: "google_picture",
    name: "google_name",
    bbAccessToken: "bb_access_token",
    bbExpiresAt: "bb_expires_at",
    bbRefreshToken: "bb_refresh_token",
    youtubeUploadBlockedUntil: "youtube_upload_blocked_until",
    youtubeUploadBlockedReason: "youtube_upload_blocked_reason"
  };

  /* BirdBuddy token considered stale this many ms before expiry (refresh proactively). */
  const BB_TOKEN_STALE_MS = 2 * 60 * 1000;
  const UNKNOWN_SPECIES_LABEL = "Unknown Birdo";
  const SPECIES_FILTER_IDENTIFIED = "Identified only";
  const SPECIES_FILTER_NOT_COLLECTED = "__not_collected__";
  const LEGACY_SPECIES_FILTER_UNKNOWN = "Unknown";

  function isUnknownSpeciesLabel(name) {
    var s = String(name || "").trim().toLowerCase();
    if (!s) return false;
    return s === "unknown" ||
      s === "unknown birdo" ||
      s === "unidentified" ||
      s === "unrecognized" ||
      s === "n/a" ||
      s === "na" ||
      s === "none";
  }

  function canonicalizeSpeciesLabel(name) {
    var s = String(name || "").trim();
    if (!s) return "";
    return isUnknownSpeciesLabel(s) ? UNKNOWN_SPECIES_LABEL : s;
  }

  /* Feed query variants: rich species first, assigned-name fallback, then no-species fallback. */
  var INBOX_FEED_QUERY = [
    'query inboxFeed($first: Int, $after: String, $filter: FeedFilterInput) {',
    '  me {',
    '    feed(first: $first, after: $after, filter: $filter) {',
    '      edges { cursor node {',
    '        __typename',
    '        ... on FeedItemNewPostcard { id createdAt expiresAt medias { __typename id thumbnailUrl ... on MediaImage { contentUrl(size: ORIGINAL) } ... on MediaVideo { contentUrl(size: ORIGINAL) } } mediaSpeciesAssignedName { name species { id name scientificName } } sightingReportPreview { sightings { __typename ... on SightingRecognizedBird { species { __typename ... on SpeciesBird { id name scientificName } ... on SpeciesBirdFamily { id name } ... on SpeciesBirdGenus { id name } ... on SpeciesBirdOrder { id name } } } ... on SightingRecognizedBirdUnlocked { species { __typename ... on SpeciesBird { id name scientificName } ... on SpeciesBirdFamily { id name } ... on SpeciesBirdGenus { id name } ... on SpeciesBirdOrder { id name } } } } } }',
    '        ... on FeedItemCollectedPostcard { id createdAt expiresAt medias { __typename id thumbnailUrl ... on MediaImage { contentUrl(size: ORIGINAL) } ... on MediaVideo { contentUrl(size: ORIGINAL) } } mediaSpeciesAssignedName { name species { id name scientificName } } species { id name scientificName } }',
    '      } }',
    '      pageInfo { hasNextPage endCursor }',
    '    }',
    '  }',
    '}'
  ].join(' ');

  var INBOX_FEED_QUERY_BIRDS = [
    'query inboxFeed($first: Int, $after: String, $filter: FeedFilterInput) {',
    '  me {',
    '    feed(first: $first, after: $after, filter: $filter) {',
    '      edges { cursor node {',
    '        __typename',
    '        ... on FeedItemNewPostcard { id createdAt expiresAt medias { __typename id thumbnailUrl ... on MediaImage { contentUrl(size: ORIGINAL) } ... on MediaVideo { contentUrl(size: ORIGINAL) } } mediaSpeciesAssignedName { name species { id name scientificName } } }',
    '        ... on FeedItemCollectedPostcard { id createdAt expiresAt medias { __typename id thumbnailUrl ... on MediaImage { contentUrl(size: ORIGINAL) } ... on MediaVideo { contentUrl(size: ORIGINAL) } } mediaSpeciesAssignedName { name species { id name scientificName } } species { id name scientificName } }',
    '      } }',
    '      pageInfo { hasNextPage endCursor }',
    '    }',
    '  }',
    '}'
  ].join(' ');

  var INBOX_FEED_QUERY_NO_SPECIES = [
    'query inboxFeed($first: Int, $after: String, $filter: FeedFilterInput) {',
    '  me {',
    '    feed(first: $first, after: $after, filter: $filter) {',
    '      edges { cursor node {',
    '        __typename',
    '        ... on FeedItemNewPostcard { id createdAt expiresAt medias { __typename id thumbnailUrl ... on MediaImage { contentUrl(size: ORIGINAL) } ... on MediaVideo { contentUrl(size: ORIGINAL) } } }',
    '        ... on FeedItemCollectedPostcard { id createdAt expiresAt medias { __typename id thumbnailUrl ... on MediaImage { contentUrl(size: ORIGINAL) } ... on MediaVideo { contentUrl(size: ORIGINAL) } } }',
    '      } }',
    '      pageInfo { hasNextPage endCursor }',
    '    }',
    '  }',
    '}'
  ].join(' ');

  const $ = function (id) { return document.getElementById(id); };
  const loginScreen = $("loginScreen");
  const loggedInScreen = $("loggedInScreen");
  const loginBtn = $("loginBtn");
  const avatar = $("avatar");
  const signoutBtn = $("signoutBtn");
  const mediaGrid = $("mediaGrid");
  const mediaStatus = $("mediaStatus");
  const mediaError = $("mediaError");
  const mediaEmpty = $("mediaEmpty");
  const loadMoreBtn = $("loadMoreBtn");
  const loadAllBtn = $("loadAllBtn");
  const downloadAllBtn = $("downloadAllBtn");
  const appFooter = $("appFooter");
  const footerStats = $("footerStats");
  const footerActions = $("footerActions");
  const downloadAllMediaBtn = $("downloadAllMediaBtn");
  const cameraInfoBar = $("cameraInfoBar");
  const speciesFilterBar = $("speciesFilterBar");
  const headerStatus = $("headerStatus");
  const youtubeUploadModalOverlay = $("youtubeUploadModalOverlay");
  const youtubeModalMessage = $("youtubeModalMessage");
  const youtubeModalClose = $("youtubeModalClose");
  const youtubeModalOpenStudio = $("youtubeModalOpenStudio");
  const getbirdsTvBtn = $("getbirdsTvBtn");
  const getbirdsTvOverlay = $("getbirdsTvOverlay");
  const getbirdsTvVideo = $("getbirdsTvVideo");
  const getbirdsTvImage = $("getbirdsTvImage");
  const getbirdsTvPrevPanel = $("getbirdsTvPrevPanel");
  const getbirdsTvNextPanel = $("getbirdsTvNextPanel");
  const getbirdsTvClose = $("getbirdsTvClose");
  const getbirdsTvUnmute = $("getbirdsTvUnmute");
  const getbirdsTvDownload = $("getbirdsTvDownload");
  const getbirdsTvLowerThird = $("getbirdsTvLowerThird");
  const getbirdsTvSnipe = $("getbirdsTvSnipe");

  var headerStatusTimer = null;
  var HEADER_STATUS_EXPIRY_MS = 5000;

  function setHeaderStatus(msg, isError, opts) {
    opts = opts || {};
    if (!headerStatus) return;
    if (headerStatusTimer) {
      clearTimeout(headerStatusTimer);
      headerStatusTimer = null;
    }
    headerStatus.textContent = msg || "";
    headerStatus.classList.toggle("error", !!isError);
    headerStatus.classList.toggle("youtube-limit", !!opts.youtubeLimit);
    headerStatus.style.display = msg ? "block" : "none";
    if (msg && !opts.persist) {
      headerStatusTimer = setTimeout(function () {
        if (isYouTubeUploadBlocked()) {
          setHeaderStatus(getYouTubeUploadBlockedMessage(), true, { persist: true, youtubeLimit: true });
          return;
        }
        headerStatus.textContent = "";
        headerStatus.classList.remove("error");
        headerStatus.classList.remove("youtube-limit");
        headerStatus.style.display = "none";
        headerStatusTimer = null;
      }, HEADER_STATUS_EXPIRY_MS);
    } else if (!msg) {
      headerStatus.classList.remove("youtube-limit");
    }
  }

  let busy = false;
  let tokenClient = null;
  let youtubeTokenClient = null;
  var pendingYouTubeUploadRequest = null;
  var activeYouTubeUploadButton = null;
  var activeYouTubeUploadButtonLabel = "";
  var youtubeUploadBlockedUntilMs = 0;
  var youtubeUploadBlockedReason = "";
  var youtubeUploadBlockedTimer = null;
  let loadMoreCursor = null;
  let hasMore = false;
  let activeVideo = null;
  var allPostcards = [];
  var allCameras = [];
  var cameraFilter = "All";
  var speciesFilter = null;
  var speciesQueryMode = "species";
  var deferSpeciesFilterRefresh = false;
  var speciesFilterRefreshPending = false;

  function parseRetryAfterSeconds(retryAfterValue) {
    var raw = String(retryAfterValue || "").trim();
    if (!raw) return 0;
    if (/^\d+$/.test(raw)) {
      var n = parseInt(raw, 10);
      return isFinite(n) && n > 0 ? n : 0;
    }
    var dt = Date.parse(raw);
    if (!isFinite(dt)) return 0;
    var sec = Math.ceil((dt - Date.now()) / 1000);
    return sec > 0 ? sec : 0;
  }

  function parseDurationHintSeconds(message) {
    var text = String(message || "");
    var match = text.match(/(?:in|after)\s+(\d+)\s*(second|minute|hour|day)s?/i);
    if (!match) return 0;
    var amount = parseInt(match[1], 10);
    if (!isFinite(amount) || amount <= 0) return 0;
    var unit = String(match[2] || "").toLowerCase();
    if (unit === "day") return amount * 86400;
    if (unit === "hour") return amount * 3600;
    if (unit === "minute") return amount * 60;
    return amount;
  }

  function formatDateTimeLocal(tsMs) {
    if (!tsMs || !isFinite(tsMs)) return "";
    var d = new Date(tsMs);
    if (isNaN(d.getTime())) return "";
    return d.toLocaleString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit"
    });
  }

  function isYouTubeUploadLimitReason(reason) {
    var r = String(reason || "").toLowerCase();
    return r === "uploadlimitexceeded" ||
      r === "dailylimitexceeded" ||
      r === "dailylimitexceededunreg" ||
      r === "quotaexceeded" ||
      r === "userratelimitexceeded";
  }

  function isYouTubeUploadLimitError(err) {
    if (!err) return false;
    if (isYouTubeUploadLimitReason(err.youtubeReason)) return true;
    var msg = String(err.message || "").toLowerCase();
    return /uploadlimitexceeded|dailylimitexceeded|quotaexceeded|userratelimitexceeded|exceeded the number of videos/i.test(msg);
  }

  function computeYouTubeUploadBlockedUntilMs(err) {
    var retryAfterSec = err && typeof err.retryAfterSeconds === "number" ? err.retryAfterSeconds : 0;
    if (retryAfterSec > 0) return Date.now() + retryAfterSec * 1000;
    var hintedSec = parseDurationHintSeconds(err && err.message);
    if (hintedSec > 0) return Date.now() + hintedSec * 1000;
    if (err && String(err.youtubeReason || "").toLowerCase() === "dailylimitexceeded") {
      var midnight = new Date();
      midnight.setHours(24, 0, 0, 0);
      return midnight.getTime();
    }
    return Date.now() + 24 * 60 * 60 * 1000;
  }

  function extractYouTubeReasonFromText(text) {
    var t = String(text || "");
    var m = t.match(/\[reason:\s*([^\]]+)\]/i);
    if (m && m[1]) return String(m[1]).trim();
    return "";
  }

  function getYouTubeUploadBlockedMessage() {
    var reason = extractYouTubeReasonFromText(youtubeUploadBlockedReason);
    if (!youtubeUploadBlockedUntilMs) {
      return reason
        ? "No more Save to YouTube right now. YouTube limit hit (" + reason + ")."
        : "No more Save to YouTube right now. YouTube upload limit reached.";
    }
    var when = formatDateTimeLocal(youtubeUploadBlockedUntilMs);
    if (!when) {
      return reason
        ? "No more Save to YouTube right now. YouTube limit hit (" + reason + ")."
        : "No more Save to YouTube right now. YouTube upload limit reached.";
    }
    return reason
      ? "No more Save to YouTube until about " + when + " (" + reason + ")."
      : "No more Save to YouTube until about " + when + ".";
  }

  function isYouTubeUploadBlocked() {
    if (!youtubeUploadBlockedUntilMs) return false;
    if (Date.now() >= youtubeUploadBlockedUntilMs) {
      if (youtubeUploadBlockedTimer) {
        clearTimeout(youtubeUploadBlockedTimer);
        youtubeUploadBlockedTimer = null;
      }
      youtubeUploadBlockedUntilMs = 0;
      youtubeUploadBlockedReason = "";
      localStorage.removeItem(STORAGE_KEYS.youtubeUploadBlockedUntil);
      localStorage.removeItem(STORAGE_KEYS.youtubeUploadBlockedReason);
      return false;
    }
    return true;
  }

  function applyYouTubeUploadBlockedUi() {
    var blocked = isYouTubeUploadBlocked();
    var msg = blocked ? getYouTubeUploadBlockedMessage() : "";
    mediaGrid.querySelectorAll("button[data-save-youtube-button='true']").forEach(function (btn) {
      var hasVideo = btn.getAttribute("data-has-video") === "true";
      if (!hasVideo) {
        btn.disabled = true;
        btn.setAttribute("aria-disabled", "true");
        btn.title = "No MP4 video available in this postcard.";
        return;
      }
      if (blocked) {
        btn.disabled = true;
        btn.setAttribute("aria-disabled", "true");
        btn.title = msg;
      } else if (!btn.classList.contains("btn-uploading")) {
        btn.disabled = false;
        btn.removeAttribute("aria-disabled");
        btn.title = "";
      }
    });
    if (blocked) {
      setHeaderStatus(msg, true, { persist: true, youtubeLimit: true });
    } else if (headerStatus && headerStatus.classList.contains("youtube-limit")) {
      setHeaderStatus("", false);
    }
  }

  function scheduleYouTubeUploadBlockedUiRefresh() {
    if (youtubeUploadBlockedTimer) {
      clearTimeout(youtubeUploadBlockedTimer);
      youtubeUploadBlockedTimer = null;
    }
    if (!isYouTubeUploadBlocked()) return;
    var delay = youtubeUploadBlockedUntilMs - Date.now() + 250;
    if (!isFinite(delay) || delay < 500) delay = 500;
    if (delay > 2147483647) delay = 2147483647;
    youtubeUploadBlockedTimer = setTimeout(function () {
      youtubeUploadBlockedTimer = null;
      applyYouTubeUploadBlockedUi();
      if (isYouTubeUploadBlocked()) scheduleYouTubeUploadBlockedUiRefresh();
    }, delay);
  }

  function blockYouTubeUploadsUntil(untilMs, reason) {
    var next = Number(untilMs) || 0;
    if (!isFinite(next) || next <= Date.now()) next = Date.now() + 24 * 60 * 60 * 1000;
    youtubeUploadBlockedUntilMs = next;
    youtubeUploadBlockedReason = String(reason || "").trim();
    localStorage.setItem(STORAGE_KEYS.youtubeUploadBlockedUntil, String(youtubeUploadBlockedUntilMs));
    if (youtubeUploadBlockedReason) {
      localStorage.setItem(STORAGE_KEYS.youtubeUploadBlockedReason, youtubeUploadBlockedReason);
    } else {
      localStorage.removeItem(STORAGE_KEYS.youtubeUploadBlockedReason);
    }
    applyYouTubeUploadBlockedUi();
    scheduleYouTubeUploadBlockedUiRefresh();
  }

  function restoreYouTubeUploadBlockedState() {
    var storedUntil = parseInt(localStorage.getItem(STORAGE_KEYS.youtubeUploadBlockedUntil) || "0", 10);
    youtubeUploadBlockedReason = String(localStorage.getItem(STORAGE_KEYS.youtubeUploadBlockedReason) || "");
    youtubeUploadBlockedUntilMs = isFinite(storedUntil) ? storedUntil : 0;
    if (!isYouTubeUploadBlocked()) {
      youtubeUploadBlockedUntilMs = 0;
      youtubeUploadBlockedReason = "";
      if (youtubeUploadBlockedTimer) {
        clearTimeout(youtubeUploadBlockedTimer);
        youtubeUploadBlockedTimer = null;
      }
    } else {
      scheduleYouTubeUploadBlockedUiRefresh();
    }
  }

  function setBusy(on) {
    busy = !!on;
    loginBtn.disabled = busy;
    signoutBtn.disabled = busy;
    if (downloadAllBtn) downloadAllBtn.disabled = busy;
    if (loadMoreBtn) loadMoreBtn.disabled = busy;
    if (loadAllBtn) loadAllBtn.disabled = busy;
    if (downloadAllMediaBtn) downloadAllMediaBtn.disabled = busy;
  }

  function isElementVisible(el) {
    return !!(el && !el.hidden && el.style.display !== "none");
  }

  function getDisplayedCounts() {
    var groups = mediaGrid.querySelectorAll(".postcard-group");
    var links = mediaGrid.querySelectorAll(".media-link");
    return { postcards: groups.length, media: links.length };
  }

  function getVisibleCounts() {
    var postcards = 0;
    var media = 0;
    mediaGrid.querySelectorAll(".postcard-group").forEach(function (group) {
      if (!isElementVisible(group)) return;
      postcards += 1;
      media += group.querySelectorAll(".media-link").length;
    });
    return { postcards: postcards, media: media };
  }

  function updateFooter() {
    refreshSpeciesFilterBar();
    var visible = getVisibleCounts();
    var total = getDisplayedCounts();
    if (footerStats) {
      footerStats.innerHTML = visible.postcards + " postcard" + (visible.postcards === 1 ? "" : "s") + " displayed" + (visible.media > 0 ? " <span class=\"muted\">(" + visible.media + " media)</span>" : "");
    }
    if (footerActions && loadMoreBtn && loadAllBtn && downloadAllMediaBtn) {
      if (hasMore) {
        loadMoreBtn.hidden = false;
        loadAllBtn.hidden = false;
        downloadAllMediaBtn.hidden = true;
      } else {
        loadMoreBtn.hidden = true;
        loadAllBtn.hidden = true;
        downloadAllMediaBtn.hidden = total.media === 0;
      }
    }
    if (downloadAllBtn) {
      downloadAllBtn.hidden = hasMore || total.media === 0;
    }
    refreshCameraInfoBar();
    applyYouTubeUploadBlockedUi();
  }

  function cameraDisplayName(camera) {
    if (!camera) return "Camera";
    var name = String(camera.name || "").trim();
    if (name) return name;
    var id = String(camera.id || "").trim();
    return id ? ("Camera " + id.slice(0, 8)) : "Camera";
  }

  function formatCameraLocation(camera) {
    if (!camera) return "—";
    var parts = [];
    if (camera.city) parts.push(camera.city);
    if (camera.country) parts.push(camera.country);
    if (parts.length === 0 && camera.region) parts.push(camera.region);
    return parts.length ? parts.join(", ") : "—";
  }

  function formatCameraSignal(camera) {
    if (!camera) return "—";
    var parts = [];
    if (camera.signalState) parts.push(camera.signalState);
    if (typeof camera.signalValue === "number") parts.push(camera.signalValue + " dBm");
    return parts.length ? parts.join(" · ") : "—";
  }

  function formatCameraBattery(camera) {
    if (!camera) return "—";
    var parts = [];
    if (typeof camera.batteryPercent === "number") parts.push(camera.batteryPercent + "%");
    if (camera.batteryState) parts.push(camera.batteryState);
    if (typeof camera.batteryCharging === "boolean") parts.push(camera.batteryCharging ? "charging" : "not charging");
    return parts.length ? parts.join(" · ") : "—";
  }

  function formatCameraBool(value) {
    if (typeof value !== "boolean") return "—";
    return value ? "Yes" : "No";
  }

  function cameraPostcardCount(cameraId) {
    var total = 0;
    if (!cameraId) return total;
    allPostcards.forEach(function (p) {
      if ((p.feederId || "") === cameraId) total += 1;
    });
    return total;
  }

  function buildCameraInfoBar() {
    if (!cameraInfoBar) return;
    if (!allCameras || allCameras.length === 0) {
      cameraInfoBar.classList.add("hidden");
      cameraInfoBar.innerHTML = "";
      return;
    }
    cameraInfoBar.classList.remove("hidden");
    cameraInfoBar.innerHTML = "";

    function addCard(title, metaLines, cameraId) {
      var card = document.createElement("button");
      card.type = "button";
      card.className = "camera-card" + ((cameraFilter === cameraId || (cameraId === "All" && cameraFilter === "All")) ? " active" : "");
      var titleEl = document.createElement("div");
      titleEl.className = "camera-title";
      titleEl.textContent = title;
      card.appendChild(titleEl);
      var tooltip = (metaLines || []).join(" | ").trim();
      if (tooltip) {
        card.title = tooltip;
        card.setAttribute("aria-label", title + " — " + tooltip);
      } else {
        card.setAttribute("aria-label", title);
      }
      card.addEventListener("click", function () {
        cameraFilter = cameraFilter === cameraId ? "All" : cameraId;
        updateFooter();
      });
      cameraInfoBar.appendChild(card);
    }

    var onlineCount = 0;
    allCameras.forEach(function (c) {
      if (String(c.state || "").toUpperCase() === "ONLINE") onlineCount += 1;
    });
    var visibleCounts = getVisibleCounts();
    var totalCounts = getDisplayedCounts();
    var postcardLine = visibleCounts.postcards + " postcard(s) displayed";
    if (totalCounts.postcards !== visibleCounts.postcards) {
      postcardLine += " of " + totalCounts.postcards + " loaded";
    }
    addCard("All cameras (" + allCameras.length + ")", [
      onlineCount + " online",
      postcardLine
    ], "All");

    allCameras.forEach(function (camera) {
      var lines = [];
      lines.push("Type: " + (camera.type || "—"));
      lines.push("State: " + (camera.state || "—"));
      lines.push("Housing/Version: " + (camera.housingType || "—") + " / " + (camera.version || "—"));
      lines.push("Location: " + formatCameraLocation(camera));
      if (typeof camera.latitude === "number" && typeof camera.longitude === "number") {
        lines.push("Coords: " + camera.latitude.toFixed(4) + ", " + camera.longitude.toFixed(4));
      }
      lines.push("Battery: " + formatCameraBattery(camera));
      lines.push("Signal: " + formatCameraSignal(camera));
      lines.push("Food: " + (camera.foodState || "—"));
      lines.push("Temp: " + (typeof camera.temperature === "number" ? (camera.temperature + "°") : "—"));
      if (camera.ownerName) lines.push("Owner: " + camera.ownerName);
      if (camera.livestreamAccessStatus) lines.push("Livestream access: " + camera.livestreamAccessStatus);
      if (camera.videoQuality || camera.cameraFieldOfView || camera.firmwareVersion) {
        lines.push("Video/FOV: " + (camera.videoQuality || "—") + " / " + (camera.cameraFieldOfView || "—"));
        lines.push("Firmware: " + (camera.firmwareVersion || "—") + " (avail " + (camera.availableFirmwareVersion || "—") + ")");
      }
      if (camera.serialNumber) lines.push("SN: " + camera.serialNumber);
      lines.push("Power/Frequency: " + (camera.powerProfile || "—") + " / " + (camera.frequency || "—"));
      lines.push("Audio enabled: " + formatCameraBool(camera.audioEnabled) + " · Off-grid: " + formatCameraBool(camera.offGrid));
      lines.push("Supports audio/Enhanced/WebRTC: " + formatCameraBool(camera.supportsAudio) + "/" + formatCameraBool(camera.supportsEnhancedLivestream) + "/" + formatCameraBool(camera.supportsWebRTC));
      if (camera.presenceUpdatedAt) lines.push("Presence: " + formatPostcardDate(camera.presenceUpdatedAt));
      if (typeof camera.invitationsAvailable === "number") lines.push("Invites available: " + camera.invitationsAvailable);
      lines.push("Postcards loaded: " + cameraPostcardCount(camera.id));
      addCard(cameraDisplayName(camera), lines, camera.id || "All");
    });
  }

  function refreshCameraInfoBar() {
    buildCameraInfoBar();
  }

  function normalizeCameraFromFeeder(feeder) {
    if (!feeder || typeof feeder !== "object") return null;
    var location = feeder.location || {};
    var battery = feeder.battery || {};
    var signal = feeder.signal || {};
    var food = feeder.food || {};
    var temperature = feeder.temperature || {};
    return {
      id: feeder.id ? String(feeder.id) : "",
      type: feeder.__typename ? String(feeder.__typename) : "",
      name: feeder.name ? String(feeder.name) : "",
      state: feeder.state ? String(feeder.state) : "",
      housingType: feeder.housingType ? String(feeder.housingType) : "",
      version: feeder.version ? String(feeder.version) : "",
      ownerName: feeder.ownerName ? String(feeder.ownerName) : "",
      livestreamAccessStatus: feeder.livestreamAccessStatus ? String(feeder.livestreamAccessStatus) : "",
      city: (location.city || feeder.locationCity || "").toString(),
      country: (location.country || feeder.locationCountry || "").toString(),
      region: (location.region || "").toString(),
      latitude: typeof location.latitude === "number" ? location.latitude : null,
      longitude: typeof location.longitude === "number" ? location.longitude : null,
      batteryState: battery.state ? String(battery.state) : "",
      batteryPercent: typeof battery.percentage === "number" ? battery.percentage : null,
      batteryCharging: typeof battery.charging === "boolean" ? battery.charging : null,
      signalState: signal.state ? String(signal.state) : "",
      signalValue: typeof signal.value === "number" ? signal.value : null,
      foodState: food.state ? String(food.state) : "",
      temperature: typeof temperature.value === "number" ? temperature.value : null,
      supportsAudio: typeof feeder.supportsAudio === "boolean" ? feeder.supportsAudio : null,
      supportsEnhancedLivestream: typeof feeder.supportsEnhancedLivestream === "boolean" ? feeder.supportsEnhancedLivestream : null,
      supportsWebRTC: typeof feeder.supportsWebRTC === "boolean" ? feeder.supportsWebRTC : null,
      firmwareVersion: feeder.firmwareVersion ? String(feeder.firmwareVersion) : "",
      availableFirmwareVersion: feeder.availableFirmwareVersion ? String(feeder.availableFirmwareVersion) : "",
      serialNumber: feeder.serialNumber ? String(feeder.serialNumber) : "",
      videoQuality: feeder.videoQuality ? String(feeder.videoQuality) : "",
      cameraFieldOfView: feeder.cameraFieldOfView ? String(feeder.cameraFieldOfView) : "",
      powerProfile: feeder.powerProfile ? String(feeder.powerProfile) : "",
      frequency: feeder.frequency ? String(feeder.frequency) : "",
      offGrid: typeof feeder.offGrid === "boolean" ? feeder.offGrid : null,
      audioEnabled: typeof feeder.audioEnabled === "boolean" ? feeder.audioEnabled : null,
      presenceUpdatedAt: feeder.presenceUpdatedAt ? String(feeder.presenceUpdatedAt) : "",
      invitationsAvailable: typeof feeder.invitationsAvailable === "number" ? feeder.invitationsAvailable : null
    };
  }

  function extractCamerasFromMeData(data) {
    var feeders = data && data.data && data.data.me && data.data.me.feeders;
    if (!Array.isArray(feeders)) return [];
    var out = [];
    var seen = {};
    feeders.forEach(function (feeder) {
      var camera = normalizeCameraFromFeeder(feeder);
      if (!camera || !camera.id) return;
      if (seen[camera.id]) return;
      seen[camera.id] = true;
      out.push(camera);
    });
    return out;
  }

  function applyCameraInventoryFromMeData(data) {
    var cameras = extractCamerasFromMeData(data);
    allCameras = cameras;
    if (!cameras.length) {
      cameraFilter = "All";
      refreshCameraInfoBar();
      applySpeciesFilter();
      return;
    }
    var exists = false;
    cameras.forEach(function (c) {
      if (c.id === cameraFilter) exists = true;
    });
    if (cameraFilter === "All" || !exists) {
      cameraFilter = cameras[0].id || "All";
    }
    refreshCameraInfoBar();
    applySpeciesFilter();
  }

  function refreshSpeciesFilterBar() {
    if (!speciesFilterBar) return;
    if (deferSpeciesFilterRefresh) {
      speciesFilterRefreshPending = true;
      return;
    }
    speciesFilterRefreshPending = false;
    buildSpeciesFilterBar();
    applySpeciesFilter();
  }

  /** One entry per unique species (postcards can have multiple comma-separated species). */
  function getUniqueSpecies() {
    var set = {};
    allPostcards.forEach(function (p) {
      var label = formatSpeciesLabel(p);
      if (!label || label === "—") {
        set[UNKNOWN_SPECIES_LABEL] = true;
        return;
      }
      label.split(/\s*,\s*/).forEach(function (part) {
        var s = part.trim();
        if (s) set[s] = true;
      });
    });
    return Object.keys(set).sort();
  }

  function postcardSpeciesList(dataSpecies) {
    var s = (dataSpecies || "").trim();
    if (s === "" || s === "—") return [];
    return s.split(/\s*,\s*/).map(function (p) { return p.trim(); }).filter(Boolean);
  }

  function applySpeciesFilter() {
    if (!mediaGrid) return;
    var groups = mediaGrid.querySelectorAll(".postcard-group");
    var visibleCount = 0;
    groups.forEach(function (el) {
      var dataSpecies = (el.getAttribute("data-species") || "").trim();
      var dataCameraId = (el.getAttribute("data-camera-id") || "").trim();
      var dataCollected = (el.getAttribute("data-collected") || "") === "1";
      var speciesList = postcardSpeciesList(dataSpecies);
      var show = true;
      if (speciesFilter === null || speciesFilter === "All") {
        show = true;
      } else if (speciesFilter === SPECIES_FILTER_NOT_COLLECTED) {
        show = !dataCollected;
      } else if (speciesFilter === SPECIES_FILTER_IDENTIFIED) {
        show = speciesList.some(function (name) { return !isUnknownSpeciesLabel(name); });
      } else if (speciesFilter === LEGACY_SPECIES_FILTER_UNKNOWN || isUnknownSpeciesLabel(speciesFilter)) {
        show = speciesList.length === 0 || speciesList.some(function (name) { return isUnknownSpeciesLabel(name); });
      } else {
        show = speciesList.indexOf(speciesFilter) !== -1;
      }
      if (show && cameraFilter !== "All") {
        show = dataCameraId === "" || dataCameraId === cameraFilter;
      }
      el.style.display = show ? "" : "none";
      if (show) visibleCount += 1;
    });
    if (mediaEmpty && allPostcards.length > 0) {
      if (visibleCount === 0) {
        mediaEmpty.hidden = false;
        mediaEmpty.textContent = "No postcards match the active filters.";
      } else if (mediaEmpty.textContent === "No postcards match the active filters.") {
        mediaEmpty.hidden = true;
      }
    }
  }

  function buildSpeciesFilterBar() {
    if (!speciesFilterBar) return;
    if (speciesFilter === LEGACY_SPECIES_FILTER_UNKNOWN) speciesFilter = UNKNOWN_SPECIES_LABEL;
    if (allPostcards.length === 0) {
      speciesFilterBar.classList.add("hidden");
      speciesFilterBar.innerHTML = "";
      return;
    }
    speciesFilterBar.classList.remove("hidden");
    var unique = getUniqueSpecies();
    speciesFilterBar.innerHTML = "";
    var label = document.createElement("span");
    label.className = "filter-label";
    label.textContent = "Filter:";
    speciesFilterBar.appendChild(label);
    function addChip(text, value) {
      var chip = document.createElement("button");
      chip.type = "button";
      var isAll = value === "All";
      var isActive = isAll ? (speciesFilter === null || speciesFilter === "All") : (speciesFilter === value);
      chip.className = "species-chip" + (isActive ? " active" : "");
      chip.textContent = text;
      chip.dataset.filter = value;
      chip.addEventListener("click", function () {
        speciesFilter = speciesFilter === value ? "All" : value;
        updateFooter();
      });
      speciesFilterBar.appendChild(chip);
    }
    var notCollectedCount = 0;
    allPostcards.forEach(function (p) {
      if (!isPostcardCollected(p)) notCollectedCount += 1;
    });
    addChip("All", "All");
    addChip("Not Collected (" + notCollectedCount + ")", SPECIES_FILTER_NOT_COLLECTED);
    var hasUnknown = unique.some(function (name) { return isUnknownSpeciesLabel(name); });
    if (hasUnknown) {
      addChip(SPECIES_FILTER_IDENTIFIED, SPECIES_FILTER_IDENTIFIED);
      addChip(UNKNOWN_SPECIES_LABEL, UNKNOWN_SPECIES_LABEL);
    }
    unique.forEach(function (name) {
      if (isUnknownSpeciesLabel(name)) return;
      addChip(name, name);
    });
  }

  function updateDownloadAllVisibility() {
    updateFooter();
  }

  function resetLoggedInUI() {
    setHeaderStatus("", false);
    allPostcards = [];
    allCameras = [];
    cameraFilter = "All";
    speciesFilter = null;
    speciesQueryMode = "species";
    if (cameraInfoBar) {
      cameraInfoBar.innerHTML = "";
      cameraInfoBar.classList.add("hidden");
    }
    if (speciesFilterBar) speciesFilterBar.innerHTML = "";
    if (mediaGrid) mediaGrid.innerHTML = "";
    loadMoreCursor = null;
    hasMore = false;
    if (mediaStatus) {
      mediaStatus.hidden = true;
      mediaStatus.textContent = "";
    }
    if (mediaError) {
      mediaError.hidden = true;
      mediaError.textContent = "";
    }
    if (mediaEmpty) mediaEmpty.hidden = true;
    if (loadMoreBtn) loadMoreBtn.hidden = true;
    if (loadAllBtn) loadAllBtn.hidden = true;
    if (downloadAllMediaBtn) downloadAllMediaBtn.hidden = true;
    if (downloadAllBtn) downloadAllBtn.hidden = true;
    if (footerStats) footerStats.textContent = "0 postcards displayed";
    updateFooter();
  }

  function showLogin() {
    resetLoggedInUI();
    if (loggedInScreen) loggedInScreen.classList.add("hidden");
    if (loginScreen) loginScreen.classList.remove("hidden");
  }

  function getInitials(name) {
    var s = String(name || "").trim();
    if (!s) return "?";
    var parts = s.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase().slice(0, 2);
    return s[0].toUpperCase();
  }

  function getAvatarFallbackDataUri(initials) {
    var letter = (initials || "?").slice(0, 2).toUpperCase();
    var svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"44\" height=\"44\" viewBox=\"0 0 44 44\"><circle cx=\"22\" cy=\"22\" r=\"22\" fill=\"#d3c7da\"/><text x=\"22\" y=\"28\" font-family=\"system-ui,sans-serif\" font-size=\"18\" font-weight=\"600\" fill=\"#76697c\" text-anchor=\"middle\">" + letter + "</text></svg>";
    return "data:image/svg+xml," + encodeURIComponent(svg);
  }

  function applyAvatarWithFallback(avatarEl, pictureUrl, name) {
    if (!avatarEl) return;
    var initials = getInitials(name);
    avatarEl.alt = name ? "Profile: " + name : "Profile";
    avatarEl.dataset.fallbackInitials = initials;
    avatarEl.removeAttribute("data-avatar-retried");
    var fallbackSrc = getAvatarFallbackDataUri(initials);
    if (!pictureUrl || !pictureUrl.trim()) {
      avatarEl.src = fallbackSrc;
      avatarEl.onerror = null;
      return;
    }
    avatarEl.onerror = function () {
      if (avatarEl.dataset.avatarRetried) {
        avatarEl.onerror = null;
        avatarEl.src = getAvatarFallbackDataUri(avatarEl.dataset.fallbackInitials || "?");
        return;
      }
      avatarEl.dataset.avatarRetried = "1";
      var url = avatarEl.src;
      setTimeout(function () {
        if (avatarEl.src === url && avatarEl.dataset.avatarRetried) {
          avatarEl.onerror = function () {
            avatarEl.onerror = null;
            avatarEl.src = getAvatarFallbackDataUri(avatarEl.dataset.fallbackInitials || "?");
          };
          avatarEl.src = url;
        }
      }, 1500);
    };
    avatarEl.src = pictureUrl.trim();
  }

  function showLoggedIn(profile) {
    if (!profile) return;
    loginScreen.classList.add("hidden");
    loggedInScreen.classList.remove("hidden");
    applyAvatarWithFallback(avatar, profile.picture || "", profile.name || "");
    mediaGrid.innerHTML = "";
    loadMoreCursor = null;
    hasMore = false;
    loadMoreBtn.hidden = true;
    loadAllBtn.hidden = true;
    if (downloadAllMediaBtn) downloadAllMediaBtn.hidden = true;
    mediaStatus.hidden = false;
    mediaStatus.textContent = "Loading postcards…";
    mediaError.hidden = true;
    mediaEmpty.hidden = true;
    updateFooter();
    fetchBirdBuddyFeed();
  }

  function normalizeGraphqlEndpoint(urlLike) {
    var raw = String(urlLike || "").trim();
    if (!raw) return "";
    try {
      return new URL(raw, window.location.origin).toString();
    } catch (_) {
      return "";
    }
  }

  function syncGraphqlOverrideFromUrl() {
    try {
      var params = new URLSearchParams(window.location.search || "");
      if (params.has("bb_graphql_clear")) {
        localStorage.removeItem(BB_GRAPHQL_OVERRIDE_KEY);
        return;
      }
      for (var i = 0; i < BB_GRAPHQL_OVERRIDE_QUERY_KEYS.length; i++) {
        var key = BB_GRAPHQL_OVERRIDE_QUERY_KEYS[i];
        var candidate = normalizeGraphqlEndpoint(params.get(key));
        if (candidate) {
          localStorage.setItem(BB_GRAPHQL_OVERRIDE_KEY, candidate);
          return;
        }
      }
    } catch (_) {}
  }

  function isLocalDevOrigin() {
    try {
      var host = String(window.location.hostname || "").toLowerCase();
      return host === "localhost" ||
        host === "127.0.0.1" ||
        host === "::1" ||
        host === "[::1]" ||
        /\.local$/.test(host);
    } catch (_) {
      return false;
    }
  }

  function isDirectBirdBuddyEndpointUrl(urlLike) {
    try {
      var parsed = new URL(String(urlLike || ""), window.location.origin);
      var host = String(parsed.hostname || "").toLowerCase();
      return host === "graphql.app-api.prod.aws.mybirdbuddy.com";
    } catch (_) {
      return false;
    }
  }

  function shouldAllowDirectBirdBuddyGraphql() {
    try {
      if (window.__GBIRDS_ALLOW_DIRECT_BB_GRAPHQL === false) return false;
      var params = new URLSearchParams(window.location.search || "");
      for (var i = 0; i < BB_GRAPHQL_ALLOW_DIRECT_QUERY_KEYS.length; i++) {
        var key = BB_GRAPHQL_ALLOW_DIRECT_QUERY_KEYS[i];
        var value = (params.get(key) || "").trim().toLowerCase();
        if (value === "0" || value === "false" || value === "no") return false;
      }
    } catch (_) {}
    return true;
  }

  function getGraphqlEndpointCandidates() {
    var out = [];
    var allowDirect = shouldAllowDirectBirdBuddyGraphql();
    function push(urlLike) {
      var u = normalizeGraphqlEndpoint(urlLike);
      if (!u) return;
      if (!allowDirect && isDirectBirdBuddyEndpointUrl(u)) return;
      if (out.indexOf(u) !== -1) return;
      out.push(u);
    }

    var injected = normalizeGraphqlEndpoint(window.__GBIRDS_GRAPHQL_ENDPOINT || window.__BB_GRAPHQL_ENDPOINT || "");
    var override = normalizeGraphqlEndpoint(localStorage.getItem(BB_GRAPHQL_OVERRIDE_KEY) || "");

    if (injected) push(injected);
    if (override) push(override);
    push(BB_GRAPHQL);
    if (allowDirect && BB_GRAPHQL.indexOf("api=birdbuddy") === -1) push("https://graphql.app-api.prod.aws.mybirdbuddy.com/graphql");
    return out;
  }

  function isRetryableGraphqlEndpointStatus(status) {
    return status === 400 ||
      status === 403 ||
      status === 404 ||
      status === 405 ||
      status === 408 ||
      status === 429 ||
      status === 500 ||
      status === 502 ||
      status === 503 ||
      status === 504;
  }

  function makeGraphqlConnectivityError(cause, attemptedEndpoints) {
    var details = attemptedEndpoints && attemptedEndpoints.length ? (" Tried: " + attemptedEndpoints.join(", ")) : "";
    var guidance = "Could not reach the BirdBuddy service through the local FireBird gateway. Check that Apache/PHP and the PHP cURL extension are enabled.";
    if (!shouldAllowDirectBirdBuddyGraphql()) {
      guidance += " Direct BirdBuddy GraphQL is disabled on localhost to avoid CORS preflight failures.";
    }
    var err = new Error(guidance + details);
    err.isNetworkError = true;
    err.isCorsError = true;
    if (cause) err.cause = cause;
    return err;
  }

  function bbGraphql(body, token) {
    var headers = {
      "Content-Type": "application/json",
      "Accept": "application/json"
    };
    if (token) headers["Authorization"] = "Bearer " + token;

    var payload = JSON.stringify(body);
    var endpoints = getGraphqlEndpointCandidates();
    var attempted = [];
    var lastErr = null;

    function tryEndpoint(i) {
      if (i >= endpoints.length) {
        return Promise.reject(makeGraphqlConnectivityError(lastErr, attempted));
      }
      var endpoint = endpoints[i];
      attempted.push(endpoint);
      return fetch(endpoint, {
        method: "POST",
        headers: headers,
        body: payload
      }).then(function (r) {
        if (r.status === 401) {
          var e = new Error("Unauthorized");
          e.isAuthError = true;
          return Promise.reject(e);
        }
        if (isRetryableGraphqlEndpointStatus(r.status) && i < endpoints.length - 1) {
          return tryEndpoint(i + 1);
        }
        return r;
      }).catch(function (e) {
        if (e && e.isAuthError) return Promise.reject(e);
        lastErr = e;
        if (i < endpoints.length - 1 && isCORSOrNetworkError(e)) {
          return tryEndpoint(i + 1);
        }
        return Promise.reject(makeGraphqlConnectivityError(e, attempted));
      });
    }

    return tryEndpoint(0);
  }

  function isBirdBuddyAuthError(code, message) {
    if (!code && !message) return false;
    var msg = (message || "").toUpperCase();
    return code === "UNAUTHENTICATED" ||
           code === "AUTH_TOKEN_EXPIRED_ERROR" ||
           /UNAUTHORIZED|INVALID TOKEN|TOKEN EXPIRED|AUTH_TOKEN_EXPIRED/i.test(msg);
  }

  function decodeBase64Url(input) {
    var base = String(input || "").replace(/-/g, "+").replace(/_/g, "/");
    while (base.length % 4) base += "=";
    return atob(base);
  }

  function parseJwtPayload(token) {
    try {
      var raw = String(token || "");
      var parts = raw.split(".");
      if (parts.length < 2) return null;
      return JSON.parse(decodeBase64Url(parts[1]));
    } catch (_) {
      return null;
    }
  }

  function getTokenExpiryMsFromJwt(token) {
    var payload = parseJwtPayload(token);
    if (!payload || typeof payload.exp !== "number") return 0;
    return payload.exp * 1000;
  }

  function resolveBbExpiryMs(token) {
    var jwtExp = getTokenExpiryMsFromJwt(token);
    if (jwtExp && jwtExp > Date.now()) return jwtExp;
    return Date.now() + 10 * 60 * 1000;
  }

  var POSTCARD_COLLECT_MUTATION = [
    "mutation postcardCollect($feedItemId: ID!, $postcardCollectInput: PostcardCollectInput) {",
    "  postcardCollect(feedItemId: $feedItemId, input: $postcardCollectInput) {",
    "    postcardCollectedDetails {",
    "      collectedPostcard { id __typename medias { id __typename } }",
    "      __typename",
    "    }",
    "    __typename",
    "  }",
    "}"
  ].join(" ");

  var POSTCARD_COLLECT_NO_INPUT_MUTATION = [
    "mutation postcardCollect($feedItemId: ID!) {",
    "  postcardCollect(feedItemId: $feedItemId) {",
    "    postcardCollectedDetails {",
    "      collectedPostcard { id __typename medias { id __typename } }",
    "      __typename",
    "    }",
    "    __typename",
    "  }",
    "}"
  ].join(" ");

  var LEGACY_COLLECT_POSTCARD_MUTATION = [
    "mutation collectPostcard($feedItemId: ID!) {",
    "  collectPostcard(feedItemId: $feedItemId) { __typename }",
    "}"
  ].join(" ");

  function getGraphqlErrorMessage(data, response) {
    if (data && data.errors && data.errors.length) {
      return data.errors[0].message || "Save to collection failed";
    }
    if (data && data.message) return data.message;
    if (response && response.status === 400) return "Bad request (400).";
    if (response && response.status) return "Save to collection failed (" + response.status + ")";
    return "Save to collection failed";
  }

  function isAlreadyCollectedError(msg) {
    return /already.*collect|already saved|already in collection/i.test(msg || "");
  }

  function normalizeCollectionErrorMessage(msg) {
    var text = String(msg || "");
    if (!text) return "Save to collection failed";
    if (/youtube\.thumbnail|custom video thumbnails/i.test(text)) {
      return "Save to collection is hitting a non-BirdBuddy endpoint. Clear any bb_graphql override and retry.";
    }
    return text;
  }

  function shouldFallbackCollectMutation(msg) {
    if (!msg) return false;
    return /Cannot query field\s+\"?postcardCollect\"?/i.test(msg) ||
           /Unknown argument\s+\"?input\"?\s+on field\s+\"?postcardCollect\"?/i.test(msg) ||
           /Unknown type\s+\"?PostcardCollectInput\"?/i.test(msg) ||
           /Cannot query field\s+\"?postcardCollectedDetails\"?/i.test(msg) ||
           /Unknown operation named\s+\"?postcardCollect\"?/i.test(msg);
  }

  function extractCollectedPostcardFromCollectResponse(data) {
    var root = data && data.data;
    if (!root || typeof root !== "object") return null;
    var details = root.postcardCollect && root.postcardCollect.postcardCollectedDetails;
    if (details && details.collectedPostcard) return details.collectedPostcard;
    return null;
  }

  function collectPostcardInBirdBuddy(postcard) {
    var feedItemId = postcard && postcard.id ? String(postcard.id) : "";
    if (!feedItemId) return Promise.reject(new Error("Postcard ID missing"));

    var shareToCommunity = postcardHasKnownSpecies(postcard);
    var attempts = [
      {
        request: {
          operationName: "postcardCollect",
          variables: { feedItemId: feedItemId, postcardCollectInput: { share: shareToCommunity } },
          query: POSTCARD_COLLECT_MUTATION
        },
        allowFallback: true
      },
      {
        request: {
          operationName: "postcardCollect",
          variables: { feedItemId: feedItemId },
          query: POSTCARD_COLLECT_NO_INPUT_MUTATION
        },
        allowFallback: true
      },
      {
        request: {
          operationName: "collectPostcard",
          variables: { feedItemId: feedItemId },
          query: LEGACY_COLLECT_POSTCARD_MUTATION
        },
        allowFallback: false
      }
    ];

    function executeAttempt(index, lastMsg, bbToken) {
      if (index >= attempts.length) {
        return Promise.reject(new Error(lastMsg || "Save to collection failed"));
      }
      var attempt = attempts[index];
      return bbGraphql(attempt.request, bbToken).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
          var collected = extractCollectedPostcardFromCollectResponse(data);
          var errMsg = "";
          var errCode = (data && data.errors && data.errors[0] && data.errors[0].extensions && data.errors[0].extensions.code) || "";
          if (data && data.errors && data.errors.length) errMsg = data.errors[0].message || "";
          if (!errMsg && !r.ok) errMsg = getGraphqlErrorMessage(data, r);
          if (collected) {
            var savedMediaCountFromCollected = Array.isArray(collected.medias)
              ? collected.medias.length
              : (Array.isArray(postcard && postcard.medias) ? postcard.medias.length : 0);
            return {
              alreadyCollected: isAlreadyCollectedError(errMsg),
              collectedPostcard: collected,
              savedMediaCount: savedMediaCountFromCollected,
              shareToCommunity: shareToCommunity
            };
          }
          if (errMsg) {
            if (isAlreadyCollectedError(errMsg)) {
              return {
                alreadyCollected: true,
                collectedPostcard: extractCollectedPostcardFromCollectResponse(data),
                savedMediaCount: Array.isArray(postcard && postcard.medias) ? postcard.medias.length : 0,
                shareToCommunity: shareToCommunity
              };
            }
            if (attempt.allowFallback && shouldFallbackCollectMutation(errMsg) && index < attempts.length - 1) {
              var fallbackErr = new Error(errMsg);
              fallbackErr.tryNextMutation = true;
              throw fallbackErr;
            }
            var authErr = new Error(normalizeCollectionErrorMessage(errMsg));
            if (isBirdBuddyAuthError(errCode, errMsg)) {
              authErr.isAuthError = true;
              authErr.tokenExpiredContext = {
                operation: "postcardCollect",
                feedItemId: feedItemId,
                attemptIndex: index,
                attemptOperationName: attempt.request.operationName,
                timestamp: new Date().toISOString()
              };
            }
            throw authErr;
          }
          var savedMediaCount = collected && Array.isArray(collected.medias)
            ? collected.medias.length
            : (Array.isArray(postcard && postcard.medias) ? postcard.medias.length : 0);
          return {
            alreadyCollected: false,
            collectedPostcard: collected,
            savedMediaCount: savedMediaCount,
            shareToCommunity: shareToCommunity
          };
        });
      }).catch(function (e) {
        if (e && e.isAuthError) {
          return Promise.reject(e);
        }
        var msg = normalizeCollectionErrorMessage(e && e.message ? e.message : "Save to collection failed");
        if (attempt.allowFallback && index < attempts.length - 1 &&
            ((e && e.tryNextMutation) || shouldFallbackCollectMutation(msg))) {
          return executeAttempt(index + 1, msg, bbToken);
        }
        return Promise.reject(new Error(msg));
      });
    }

    function runCollect() {
      return getValidBbToken().then(function (bbToken) {
        return executeAttempt(0, "", bbToken);
      });
    }

    return runCollect().catch(function (e) {
      if (e && e.isAuthError) {
        var tokenExpiredContext = e.tokenExpiredContext || {
          operation: "postcardCollect",
          feedItemId: feedItemId,
          timestamp: new Date().toISOString()
        };
        if (typeof e.tokenExpiredContext === "undefined") e.tokenExpiredContext = tokenExpiredContext;
        clearBirdBuddyTokens();
        return getValidBbToken().then(function (newToken) {
          return executeAttempt(0, "", newToken);
        });
      }
      return Promise.reject(e);
    });
  }

  function authBirdBuddy(googleToken) {
    return bbGraphql({
      operationName: "authSocialSignIn",
      variables: { socialSignInInput: { token: googleToken, provider: "GOOGLE" } },
      query: "mutation authSocialSignIn($socialSignInInput: SocialSignInInput!) { authSocialSignIn(socialSignInInput: $socialSignInInput) { ... on Auth { accessToken refreshToken me { user { id __typename } feeders { ... on FeederForOwner { id __typename } __typename } __typename } } } }"
    }, null).then(function (r) { return r.json(); })
      .then(function (data) {
        var auth = data.data && data.data.authSocialSignIn;
        if (!auth || !auth.accessToken) {
          var err = (data.errors && data.errors[0] && data.errors[0].message) || "GetBirds sign-in failed";
          throw new Error(err);
        }
        var exp = resolveBbExpiryMs(auth.accessToken);
        localStorage.setItem(STORAGE_KEYS.bbAccessToken, auth.accessToken);
        localStorage.setItem(STORAGE_KEYS.bbExpiresAt, String(exp));
        if (auth.refreshToken) localStorage.setItem(STORAGE_KEYS.bbRefreshToken, auth.refreshToken);
        return auth.accessToken;
      });
  }

  function isBbTokenFresh() {
    var token = localStorage.getItem(STORAGE_KEYS.bbAccessToken);
    var exp = parseInt(localStorage.getItem(STORAGE_KEYS.bbExpiresAt) || "0", 10);
    if (!exp && token) {
      exp = getTokenExpiryMsFromJwt(token);
      if (exp) localStorage.setItem(STORAGE_KEYS.bbExpiresAt, String(exp));
    }
    return !!(token && exp && Date.now() < exp - BB_TOKEN_STALE_MS);
  }

  function clearBirdBuddyTokens() {
    localStorage.removeItem(STORAGE_KEYS.bbAccessToken);
    localStorage.removeItem(STORAGE_KEYS.bbExpiresAt);
    localStorage.removeItem(STORAGE_KEYS.bbRefreshToken);
  }

  /** Returns a valid BB access token: uses stored token if still fresh, otherwise re-auths with Google token. Rejects if not signed in. */
  function getValidBbToken() {
    if (isBbTokenFresh()) {
      var token = localStorage.getItem(STORAGE_KEYS.bbAccessToken);
      if (token) return Promise.resolve(token);
    }
    var googleToken = localStorage.getItem(STORAGE_KEYS.googleAccessToken);
    if (!googleToken) return Promise.reject(new Error("Not signed in to GetBirds"));
    return authBirdBuddy(googleToken);
  }

  var ME_QUERY = [
    "query me {",
    "  me {",
    "    user { id name __typename }",
    "    feeders {",
    "      __typename",
    "      ... on FeederForMember {",
    "        id name state housingType version ownerName locationCity locationCountry livestreamAccessStatus",
    "        supportsAudio supportsEnhancedLivestream supportsWebRTC",
    "        battery { charging percentage state __typename }",
    "        signal { state value __typename }",
    "        food { state __typename }",
    "        temperature { value __typename }",
    "      }",
    "      ... on FeederForOwner {",
    "        id name state housingType version",
    "        firmwareVersion availableFirmwareVersion",
    "        serialNumber videoQuality cameraFieldOfView",
    "        powerProfile frequency offGrid audioEnabled",
    "        supportsAudio supportsEnhancedLivestream supportsWebRTC",
    "        battery { charging percentage state __typename }",
    "        signal { state value __typename }",
    "        food { state __typename }",
    "        temperature { value __typename }",
    "        location { city country region latitude longitude __typename }",
    "        presenceUpdatedAt invitationsAvailable",
    "      }",
    "      ... on FeederForMemberPending { id name __typename }",
    "    }",
    "    __typename",
    "  }",
    "}"
  ].join(" ");

  var ME_QUERY_BASIC = "query me { me { user { id __typename } feeders { __typename ... on FeederForMember { id name __typename } ... on FeederForOwner { id name __typename } ... on FeederForMemberPending { id name __typename } } __typename } }";

  function runMeQuery(bbToken, query) {
    return bbGraphql({
      operationName: "me",
      variables: {},
      query: query
    }, bbToken).then(function (r) { return r.json(); }).then(function (data) {
      if (data.errors && data.errors.length) {
        var err = data.errors[0];
        var msg = err.message || "";
        var code = err.extensions && err.extensions.code;
        var e = new Error(msg);
        e.isAuthError = isBirdBuddyAuthError(code, msg);
        e.looksLikeSchemaError = /Cannot query field|Unknown field|FieldUndefined|Unknown type/i.test(msg);
        return Promise.reject(e);
      }
      return data;
    });
  }

  function fetchMe(bbToken) {
    return runMeQuery(bbToken, ME_QUERY).catch(function (e) {
      if (e && e.looksLikeSchemaError) {
        return runMeQuery(bbToken, ME_QUERY_BASIC);
      }
      return Promise.reject(e);
    });
  }

  function selectInboxFeedQuery(speciesMode) {
    var mode = speciesMode;
    if (mode === undefined || mode === true) mode = "species";
    if (mode === false) mode = "none";
    if (mode === "birds") return INBOX_FEED_QUERY_BIRDS;
    if (mode === "none") return INBOX_FEED_QUERY_NO_SPECIES;
    return INBOX_FEED_QUERY;
  }

  function isSpeciesSchemaError(msg) {
    msg = msg || "";
    return /Cannot query field\s+\"?(species|birds|commonName|scientificName|mediaSpeciesAssignedName|sightingReportPreview|sightings)\"?/i.test(msg) ||
           /Unknown field.*(species|birds|commonName|scientificName|mediaSpeciesAssignedName|sightingReportPreview|sightings)/i.test(msg) ||
           /FieldUndefined.*(species|birds|commonName|scientificName|mediaSpeciesAssignedName|sightingReportPreview|sightings)/i.test(msg) ||
           /Unknown type.*(SightingRecognizedBird|SightingRecognizedBirdUnlocked|SpeciesBird|SpeciesBirdFamily|SpeciesBirdGenus|SpeciesBirdOrder)/i.test(msg);
  }

  function fetchInboxFeed(bbToken, after, useFilter, speciesMode) {
    var variables = { first: 20 };
    if (after) variables.after = after;
    if (useFilter) variables.filter = { feedItemTypes: ["COLLECTED_POSTCARD", "NEW_POSTCARD"] };
    var query = selectInboxFeedQuery(speciesMode);
    return bbGraphql({
      operationName: "inboxFeed",
      variables: variables,
      query: query
    }, bbToken).then(function (r) { return r.json(); }).then(function (data) {
      if (data.errors && data.errors.length) {
        var err = data.errors[0];
        var msg = err.message || "";
        var code = err.extensions && err.extensions.code;
        var e = new Error(msg);
        e.isAuthError = isBirdBuddyAuthError(code, msg);
        e.isInternalError = code === "INTERNAL_SERVER_ERROR";
        e.retryWithFilter = !useFilter && e.isInternalError;
        e.looksLikeSpeciesFieldError = isSpeciesSchemaError(msg);
        return Promise.reject(e);
      }
      return data;
    });
  }

  function normalizeSpeciesName(value) {
    if (value == null) return "";
    if (typeof value === "string") return canonicalizeSpeciesLabel(value);
    if (typeof value !== "object") return "";
    if (value.commonName) return canonicalizeSpeciesLabel(value.commonName);
    if (value.name) return canonicalizeSpeciesLabel(value.name);
    if (value.label) return canonicalizeSpeciesLabel(value.label);
    if (value.scientificName) return canonicalizeSpeciesLabel(value.scientificName);
    if (value.species) return normalizeSpeciesName(value.species);
    return "";
  }

  function pushUniqueSpeciesName(out, value) {
    var name = normalizeSpeciesName(value);
    if (!name) return;
    var key = name.toLowerCase();
    for (var i = 0; i < out.length; i++) {
      if (String(out[i]).toLowerCase() === key) return;
    }
    out.push(name);
  }

  function extractSpeciesNames(node) {
    var out = [];
    if (!node) return out;

    var assigned = node.mediaSpeciesAssignedName;
    if (assigned) {
      pushUniqueSpeciesName(out, assigned);
      if (assigned.species) pushUniqueSpeciesName(out, assigned.species);
    }

    var s = node.species;
    if (s) {
      if (Array.isArray(s)) {
        s.forEach(function (x) {
          pushUniqueSpeciesName(out, x);
        });
      } else {
        pushUniqueSpeciesName(out, s);
      }
    }
    var b = node.birds;
    if (b) {
      if (Array.isArray(b)) {
        b.forEach(function (bird) {
          pushUniqueSpeciesName(out, bird);
          var sp = bird && bird.species;
          if (Array.isArray(sp)) {
            sp.forEach(function (x) { pushUniqueSpeciesName(out, x); });
          } else {
            pushUniqueSpeciesName(out, sp);
          }
        });
      } else {
        pushUniqueSpeciesName(out, b);
        if (Array.isArray(b.species)) {
          b.species.forEach(function (x) { pushUniqueSpeciesName(out, x); });
        } else {
          pushUniqueSpeciesName(out, b.species);
        }
      }
    }

    var preview = node.sightingReportPreview;
    var sightings = preview && preview.sightings;
    if (Array.isArray(sightings)) {
      sightings.forEach(function (sighting) {
        if (!sighting) return;
        pushUniqueSpeciesName(out, sighting.species);
      });
    }
    return out;
  }

  function fetchInboxFeedWithSpeciesFallback(bbToken, after, useFilter) {
    var modes = ["species", "birds", "none"];
    if (speciesQueryMode === "birds") modes = ["birds", "none"];
    if (speciesQueryMode === "none") modes = ["none"];
    var modeIndex = 0;

    function attempt(currentUseFilter) {
      var mode = modes[modeIndex];
      return fetchInboxFeed(bbToken, after, currentUseFilter, mode).then(function (data) {
        speciesQueryMode = mode;
        return data;
      }).catch(function (e) {
        if (e && e.retryWithFilter && !currentUseFilter) {
          return attempt(true);
        }
        if (e && e.looksLikeSpeciesFieldError && modeIndex < modes.length - 1) {
          modeIndex += 1;
          return attempt(currentUseFilter);
        }
        return Promise.reject(e);
      });
    }

    return attempt(!!useFilter);
  }

  function normalizePostcardFeeder(feeder) {
    if (!feeder || typeof feeder !== "object") return null;
    var location = feeder.location || {};
    return {
      id: feeder.id ? String(feeder.id) : "",
      name: feeder.name ? String(feeder.name) : "",
      type: feeder.__typename ? String(feeder.__typename) : "",
      housingType: feeder.housingType ? String(feeder.housingType) : "",
      version: feeder.version ? String(feeder.version) : "",
      city: (location.city || feeder.locationCity || "").toString(),
      country: (location.country || feeder.locationCountry || "").toString()
    };
  }

  function collectPostcardsFromFeed(data) {
    var postcards = [];
    var feed = data.data && data.data.me && data.data.me.feed;
    if (!feed || !feed.edges) return postcards;
    feed.edges.forEach(function (edge) {
      var node = edge.node;
      if (!node || !node.medias) return;
      var medias = [];
      node.medias.forEach(function (m, idx) {
        var url = m.contentUrl || m.thumbnailUrl;
        if (url) medias.push({
          url: url,
          thumb: m.thumbnailUrl,
          id: m.id,
          sourceIndex: idx,
          isVideo: m.__typename === "MediaVideo" || (!!m.contentUrl && m.contentUrl.indexOf(".mp4") !== -1)
        });
      });
      medias.reverse();
      var speciesNames = extractSpeciesNames(node);
      if (!speciesNames.length) speciesNames.push(UNKNOWN_SPECIES_LABEL);
      var feeder = normalizePostcardFeeder(node.feeder);
      if (medias.length) postcards.push({
        id: node.id,
        createdAt: node.createdAt,
        expiresAt: node.expiresAt,
        itemType: node.__typename || "",
        collected: node.__typename === "FeedItemCollectedPostcard",
        species: speciesNames,
        medias: medias,
        feeder: feeder,
        feederId: feeder && feeder.id ? feeder.id : ""
      });
    });
    return postcards;
  }

  function mediaSummary(medias) {
    var images = 0, videos = 0;
    medias.forEach(function (m) { if (m.isVideo) videos++; else images++; });
    var parts = [];
    if (images) parts.push(images + " photo" + (images === 1 ? "" : "s"));
    if (videos) parts.push(videos + " video" + (videos === 1 ? "" : "s"));
    return parts.length ? parts.join(", ") : "1 media";
  }

  function formatPostcardDate(value) {
    if (value == null || value === "") return "—";
    var d = new Date(value);
    return isNaN(d.getTime()) ? String(value) : d.toLocaleString(undefined, { dateStyle: "short", timeStyle: "short" });
  }

  function formatItemType(typename) {
    if (!typename) return "—";
    if (typename === "FeedItemNewPostcard") return "New postcard";
    if (typename === "FeedItemCollectedPostcard") return "Collected";
    return typename;
  }

  function getCanonicalSpeciesList(postcard) {
    var raw = postcard && Array.isArray(postcard.species) ? postcard.species : [];
    var out = [];
    var seen = {};
    for (var i = 0; i < raw.length; i++) {
      var s = canonicalizeSpeciesLabel(raw[i]);
      if (!s) continue;
      var key = s.toLowerCase();
      if (seen[key]) continue;
      seen[key] = true;
      out.push(s);
    }
    var known = out.filter(function (name) { return !isUnknownSpeciesLabel(name); });
    if (known.length) return known;
    if (out.length) return [UNKNOWN_SPECIES_LABEL];
    return [UNKNOWN_SPECIES_LABEL];
  }

  function formatSpeciesLabel(postcard) {
    if (!postcard) return "—";
    return getCanonicalSpeciesList(postcard).join(", ");
  }

  function postcardHasKnownSpecies(postcard) {
    return getCanonicalSpeciesList(postcard).some(function (name) {
      return !isUnknownSpeciesLabel(name);
    });
  }

  function isPostcardCollected(postcard) {
    return !!(postcard && (postcard.collected || postcard.itemType === "FeedItemCollectedPostcard"));
  }

  function md5HashString(input) {
    function cmn(q, a, b, x, s, t) {
      a = add32(add32(a, q), add32(x, t));
      return add32((a << s) | (a >>> (32 - s)), b);
    }
    function ff(a, b, c, d, x, s, t) { return cmn((b & c) | ((~b) & d), a, b, x, s, t); }
    function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & (~d)), a, b, x, s, t); }
    function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t); }
    function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | (~d)), a, b, x, s, t); }
    function md5cycle(state, block) {
      var a = state[0], b = state[1], c = state[2], d = state[3];
      a = ff(a, b, c, d, block[0], 7, -680876936); d = ff(d, a, b, c, block[1], 12, -389564586);
      c = ff(c, d, a, b, block[2], 17, 606105819); b = ff(b, c, d, a, block[3], 22, -1044525330);
      a = ff(a, b, c, d, block[4], 7, -176418897); d = ff(d, a, b, c, block[5], 12, 1200080426);
      c = ff(c, d, a, b, block[6], 17, -1473231341); b = ff(b, c, d, a, block[7], 22, -45705983);
      a = ff(a, b, c, d, block[8], 7, 1770035416); d = ff(d, a, b, c, block[9], 12, -1958414417);
      c = ff(c, d, a, b, block[10], 17, -42063); b = ff(b, c, d, a, block[11], 22, -1990404162);
      a = ff(a, b, c, d, block[12], 7, 1804603682); d = ff(d, a, b, c, block[13], 12, -40341101);
      c = ff(c, d, a, b, block[14], 17, -1502002290); b = ff(b, c, d, a, block[15], 22, 1236535329);
      a = gg(a, b, c, d, block[1], 5, -165796510); d = gg(d, a, b, c, block[6], 9, -1069501632);
      c = gg(c, d, a, b, block[11], 14, 643717713); b = gg(b, c, d, a, block[0], 20, -373897302);
      a = gg(a, b, c, d, block[5], 5, -701558691); d = gg(d, a, b, c, block[10], 9, 38016083);
      c = gg(c, d, a, b, block[15], 14, -660478335); b = gg(b, c, d, a, block[4], 20, -405537848);
      a = gg(a, b, c, d, block[9], 5, 568446438); d = gg(d, a, b, c, block[14], 9, -1019803690);
      c = gg(c, d, a, b, block[3], 14, -187363961); b = gg(b, c, d, a, block[8], 20, 1163531501);
      a = gg(a, b, c, d, block[13], 5, -1444681467); d = gg(d, a, b, c, block[2], 9, -51403784);
      c = gg(c, d, a, b, block[7], 14, 1735328473); b = gg(b, c, d, a, block[12], 20, -1926607734);
      a = hh(a, b, c, d, block[5], 4, -378558); d = hh(d, a, b, c, block[8], 11, -2022574463);
      c = hh(c, d, a, b, block[11], 16, 1839030562); b = hh(b, c, d, a, block[14], 23, -35309556);
      a = hh(a, b, c, d, block[1], 4, -1530992060); d = hh(d, a, b, c, block[4], 11, 1272893353);
      c = hh(c, d, a, b, block[7], 16, -155497632); b = hh(b, c, d, a, block[10], 23, -1094730640);
      a = hh(a, b, c, d, block[13], 4, 681279174); d = hh(d, a, b, c, block[0], 11, -358537222);
      c = hh(c, d, a, b, block[3], 16, -722521979); b = hh(b, c, d, a, block[6], 23, 76029189);
      a = hh(a, b, c, d, block[9], 4, -640364487); d = hh(d, a, b, c, block[12], 11, -421815835);
      c = hh(c, d, a, b, block[15], 16, 530742520); b = hh(b, c, d, a, block[2], 23, -995338651);
      a = ii(a, b, c, d, block[0], 6, -198630844); d = ii(d, a, b, c, block[7], 10, 1126891415);
      c = ii(c, d, a, b, block[14], 15, -1416354905); b = ii(b, c, d, a, block[5], 21, -57434055);
      a = ii(a, b, c, d, block[12], 6, 1700485571); d = ii(d, a, b, c, block[3], 10, -1894986606);
      c = ii(c, d, a, b, block[10], 15, -1051523); b = ii(b, c, d, a, block[1], 21, -2054922799);
      a = ii(a, b, c, d, block[8], 6, 1873313359); d = ii(d, a, b, c, block[15], 10, -30611744);
      c = ii(c, d, a, b, block[6], 15, -1560198380); b = ii(b, c, d, a, block[13], 21, 1309151649);
      a = ii(a, b, c, d, block[4], 6, -145523070); d = ii(d, a, b, c, block[11], 10, -1120210379);
      c = ii(c, d, a, b, block[2], 15, 718787259); b = ii(b, c, d, a, block[9], 21, -343485551);
      state[0] = add32(a, state[0]); state[1] = add32(b, state[1]); state[2] = add32(c, state[2]); state[3] = add32(d, state[3]);
    }
    function md5blk(s, i) {
      return s.charCodeAt(i) + (s.charCodeAt(i + 1) << 8) + (s.charCodeAt(i + 2) << 16) + (s.charCodeAt(i + 3) << 24);
    }
    function md51(s) {
      var n = s.length;
      var state = [1732584193, -271733879, -1732584194, 271733878];
      var i;
      for (i = 64; i <= n; i += 64) {
        md5cycle(state, [
          md5blk(s, i - 64), md5blk(s, i - 60), md5blk(s, i - 56), md5blk(s, i - 52),
          md5blk(s, i - 48), md5blk(s, i - 44), md5blk(s, i - 40), md5blk(s, i - 36),
          md5blk(s, i - 32), md5blk(s, i - 28), md5blk(s, i - 24), md5blk(s, i - 20),
          md5blk(s, i - 16), md5blk(s, i - 12), md5blk(s, i - 8), md5blk(s, i - 4)
        ]);
      }
      s = s.slice(i - 64);
      var tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
      for (i = 0; i < s.length; i++) tail[i >> 2] |= s.charCodeAt(i) << ((i % 4) << 3);
      tail[i >> 2] |= 0x80 << ((i % 4) << 3);
      if (i > 55) {
        md5cycle(state, tail);
        for (i = 0; i < 16; i++) tail[i] = 0;
      }
      tail[14] = n * 8;
      md5cycle(state, tail);
      return state;
    }
    function rhex(n) {
      var hexChr = "0123456789abcdef";
      var s = "";
      var j;
      for (j = 0; j < 4; j++) {
        s += hexChr.charAt((n >> (j * 8 + 4)) & 0x0f) + hexChr.charAt((n >> (j * 8)) & 0x0f);
      }
      return s;
    }
    function add32(a, b) { return (a + b) & 0xffffffff; }
    var utf8 = unescape(encodeURIComponent(String(input || "")));
    var hash = md51(utf8);
    return rhex(hash[0]) + rhex(hash[1]) + rhex(hash[2]) + rhex(hash[3]);
  }

  function detectDownloadExtension(url, isVideo) {
    var fallback = isVideo ? ".mp4" : ".jpg";
    try {
      var pathname = new URL(String(url || "")).pathname || "";
      var base = pathname.split("/").filter(Boolean).pop() || "";
      var match = base.match(/\.([a-z0-9]{2,5})$/i);
      if (match && match[1]) return "." + match[1].toLowerCase();
    } catch (_) {}
    return fallback;
  }

  /** Always use dynamic hashed file names: file_<hash>.<ext>. */
  function filenameFromMedia(m) {
    var url = m && m.url ? String(m.url) : "";
    var ext = detectDownloadExtension(url, !!(m && m.isVideo));
    var hashSource = url || String((m && m.id) || "");
    var hash = md5HashString(hashSource || ("fallback-" + Date.now()));
    return "file_" + hash + ext;
  }

  function getBirdBuddyMediaProxyUrl(url) {
    var raw = String(url || "").trim();
    if (!raw) return "";
    try {
      var parsed = new URL(raw, window.location.origin);
      var host = String(parsed.hostname || "").toLowerCase();
      if (host === "graphql.app-api.prod.aws.mybirdbuddy.com" || /\.mybirdbuddy\.com$/.test(host)) {
        return BIRDBUDDY_MEDIA_PROXY + "&url=" + encodeURIComponent(raw);
      }
    } catch (_) {}
    return raw;
  }

  function downloadMediaAsFile(url, filename) {
    if (!url) return Promise.reject(new Error("Missing media URL for download."));
    return fetch(getBirdBuddyMediaProxyUrl(url), { mode: "cors" })
      .then(function (res) {
        if (!res.ok) throw new Error("Download failed (" + res.status + ").");
        return res.blob();
      })
      .then(function (blob) {
        var objectUrl = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.style.display = "none";
        a.href = objectUrl;
        a.download = filename || ("file_" + md5HashString(url) + detectDownloadExtension(url, false));
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 15000);
      });
  }

  function getDownloadPayloadFromLink(link) {
    if (!link) return { url: "", filename: "" };
    var url = link.getAttribute("data-media-url") || link.href || "";
    var filename = link.getAttribute("download") || link.getAttribute("data-download-name") || "";
    if (!filename && url) {
      filename = "file_" + md5HashString(url) + detectDownloadExtension(url, /\.mp4|\.mov|\.webm/i.test(url));
    }
    return { url: url, filename: filename };
  }

  function triggerDownloadForLink(link) {
    var payload = getDownloadPayloadFromLink(link);
    if (!payload.url) return Promise.resolve();
    return downloadMediaAsFile(payload.url, payload.filename);
  }

  function setPostcardCollectedUi(postcardId, collected) {
    if (!postcardId) return;
    var isCollected = !!collected;
    for (var i = 0; i < allPostcards.length; i++) {
      var p = allPostcards[i];
      if (!p || p.id !== postcardId) continue;
      p.collected = isCollected;
      if (isCollected) p.itemType = "FeedItemCollectedPostcard";
    }
    mediaGrid.querySelectorAll(".postcard-group").forEach(function (group) {
      if ((group.getAttribute("data-postcard-id") || "") !== postcardId) return;
      group.setAttribute("data-collected", isCollected ? "1" : "0");
      var checkbox = group.querySelector("input[data-collected-indicator=\"true\"]");
      if (checkbox) checkbox.checked = isCollected;
      var saveBtn = group.querySelector("button[data-save-collection-button=\"true\"]");
      if (saveBtn) {
        saveBtn.classList.remove("btn-uploading");
        saveBtn.removeAttribute("aria-busy");
        saveBtn.disabled = isCollected;
        saveBtn.textContent = isCollected ? "Saved to collection" : "Save to collection";
      }
    });
    updateFooter();
  }

  function findCollectionSaveButton(postcardId) {
    if (!postcardId) return null;
    return mediaGrid.querySelector(
      ".postcard-group[data-postcard-id=\"" + String(postcardId).replace(/\"/g, "\\\"") + "\"] button[data-save-collection-button=\"true\"]"
    );
  }

  function setCollectionSaveButtonUploading(button, label) {
    if (!button) return;
    button.classList.add("btn-uploading");
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.textContent = label || "Saving…";
  }

  function resetCollectionSaveButton(button, postcard) {
    if (!button) return;
    var collected = isPostcardCollected(postcard);
    button.classList.remove("btn-uploading");
    button.removeAttribute("aria-busy");
    button.disabled = collected;
    button.textContent = collected ? "Saved to collection" : "Save to collection";
  }

  function appendPostcardGroups(postcards) {
    postcards.forEach(function (postcard) {
      allPostcards.push(postcard);
      var collectedState = isPostcardCollected(postcard);
      var wrap = document.createElement("div");
      wrap.className = "postcard-group";
      wrap.setAttribute("data-postcard-id", postcard.id || "");
      wrap.setAttribute("data-camera-id", postcard.feederId || "");
      wrap.setAttribute("data-collected", collectedState ? "1" : "0");
      var speciesStr = formatSpeciesLabel(postcard);
      wrap.setAttribute("data-species", speciesStr);
      var label = document.createElement("div");
      label.className = "postcard-group-label";
      label.textContent = speciesStr;
      var badge = document.createElement("span");
      badge.className = "badge";
      badge.textContent = mediaSummary(postcard.medias);
      label.appendChild(document.createTextNode(" "));
      label.appendChild(badge);
      wrap.appendChild(label);
      var grid = document.createElement("div");
      grid.className = "postcard-group-media";
      postcard.medias.forEach(function (m, mediaIndex) {
        var card = document.createElement("div");
        card.className = "media-card";
        card.setAttribute("data-postcard-tv", "1");
        card.setAttribute("role", "button");
        card.setAttribute("tabindex", "0");
        card.setAttribute("aria-label", "Play in TV view");
        card.addEventListener("click", function (ev) {
          if (ev.target.closest(".media-link")) return;
          ev.preventDefault();
          var segments = getPostcardSegments(postcard);
          if (segments.length === 0) return;
          openGetBirdsTVWithSegments(segments, mediaIndex);
        });
        card.addEventListener("keydown", function (ev) {
          if (ev.target.closest(".media-link")) return;
          if (ev.key !== "Enter" && ev.key !== " ") return;
          ev.preventDefault();
          var segments = getPostcardSegments(postcard);
          if (segments.length === 0) return;
          openGetBirdsTVWithSegments(segments, mediaIndex);
        });
        if (m.isVideo) {
          var v = document.createElement("video");
          v.src = m.url;
          v.controls = true;
          v.preload = "metadata";
          v.playsInline = true;
          v.addEventListener("play", function () {
            if (activeVideo && activeVideo !== v) activeVideo.pause();
            activeVideo = v;
          });
          v.addEventListener("pause", function () {
            if (activeVideo === v) activeVideo = null;
          });
          v.addEventListener("ended", function () {
            if (activeVideo === v) activeVideo = null;
          });
          card.appendChild(v);
        } else {
          var img = document.createElement("img");
          img.src = m.url;
          img.loading = "lazy";
          img.alt = "Postcard";
          card.appendChild(img);
        }
        var actionsBar = document.createElement("div");
        actionsBar.className = "media-actions";

        var a = document.createElement("a");
        a.className = "media-link";
        a.href = m.url;
        a.download = filenameFromMedia(m);
        a.setAttribute("data-download-name", a.download);
        a.setAttribute("data-media-url", m.url);
        a.textContent = "Download " + (m.isVideo ? "MP4" : "JPG");
        a.addEventListener("click", function (ev) {
          ev.preventDefault();
          triggerDownloadForLink(a).catch(function (err) {
            setHeaderStatus((err && err.message) || "Download failed.", true);
          });
        });
        actionsBar.appendChild(a);

        var send = document.createElement("a");
        send.className = "media-send-link";
        send.href = "#";
        send.textContent = "Send to Adobe";
        send.setAttribute("role", "button");
        send.setAttribute("data-frameio-button", "true");
        var sendKey = frameioMediaKey(m);
        if (sendKey) send.setAttribute("data-frameio-key", sendKey);
        // If this media was already pushed to Frame.io, show it as sent (re-upload
        // is blocked; clicking re-opens the existing asset preview).
        if (sendKey && isFrameioSent(sendKey)) {
          setSendToAdobeButton(send, "sent", "Sent to Adobe ✓");
          send.setAttribute("aria-disabled", "true");
          send.classList.add("frameio-sent");
        }
        send.addEventListener("click", function (ev) {
          ev.preventDefault();
          onSendToAdobe(postcard, m, send);
        });
        actionsBar.appendChild(send);
        card.appendChild(actionsBar);
        grid.appendChild(card);
      });
      wrap.appendChild(grid);

      var footer = document.createElement("div");
      footer.className = "postcard-group-footer";
      var meta = document.createElement("div");
      meta.className = "postcard-group-meta";
      function metaRow(label, value) {
        var row = document.createElement("div");
        row.className = "meta-row";
        var lab = document.createElement("span");
        lab.className = "meta-label";
        lab.textContent = label + ": ";
        var val = document.createElement("span");
        val.className = "meta-value";
        val.textContent = value != null && value !== "" ? value : "—";
        row.appendChild(lab);
        row.appendChild(val);
        return row;
      }
      function metaCheckboxRow(label, checked, indicatorKey) {
        var row = document.createElement("div");
        row.className = "meta-row meta-row-checkbox";
        var lab = document.createElement("span");
        lab.className = "meta-label";
        lab.textContent = label + ": ";
        var val = document.createElement("span");
        val.className = "meta-value";
        var checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.disabled = true;
        checkbox.checked = !!checked;
        checkbox.setAttribute("aria-label", label);
        if (indicatorKey) checkbox.setAttribute(indicatorKey, "true");
        val.appendChild(checkbox);
        row.appendChild(lab);
        row.appendChild(val);
        return row;
      }
      meta.appendChild(metaRow("ID", postcard.id));
      if (postcard.feeder && postcard.feeder.name) {
        meta.appendChild(metaRow("Camera", postcard.feeder.name));
      }
      if (postcard.feeder && (postcard.feeder.city || postcard.feeder.country)) {
        meta.appendChild(metaRow("Location", formatCameraLocation(postcard.feeder)));
      }
      meta.appendChild(metaRow("Species", formatSpeciesLabel(postcard)));
      meta.appendChild(metaCheckboxRow("Collected", collectedState, "data-collected-indicator"));
      meta.appendChild(metaRow("Created", formatPostcardDate(postcard.createdAt)));
      meta.appendChild(metaRow("Expires", formatPostcardDate(postcard.expiresAt)));
      meta.appendChild(metaRow("Media", postcard.medias.length + " item" + (postcard.medias.length === 1 ? "" : "s")));
      footer.appendChild(meta);
      var actions = document.createElement("div");
      actions.className = "postcard-group-actions";
      var downloadPostcardBtn = document.createElement("button");
      downloadPostcardBtn.type = "button";
      downloadPostcardBtn.className = "btn";
      downloadPostcardBtn.textContent = "Download Postcard";
      downloadPostcardBtn.dataset.postcardId = postcard.id || "";
      downloadPostcardBtn.addEventListener("click", function () { onDownloadPostcard(postcard, downloadPostcardBtn); });
      var saveCollectionBtn = document.createElement("button");
      saveCollectionBtn.type = "button";
      saveCollectionBtn.className = "btn btn-primary";
      saveCollectionBtn.textContent = collectedState ? "Saved to collection" : "Save to collection";
      saveCollectionBtn.dataset.postcardId = postcard.id || "";
      saveCollectionBtn.dataset.itemType = postcard.itemType || "";
      saveCollectionBtn.setAttribute("data-save-collection-button", "true");
      saveCollectionBtn.disabled = collectedState;
      saveCollectionBtn.addEventListener("click", function () { onSaveToCollection(postcard, saveCollectionBtn); });
      var saveYoutubeBtn = document.createElement("button");
      var hasVideoMedia = postcardHasVideoMedia(postcard);
      saveYoutubeBtn.type = "button";
      saveYoutubeBtn.className = "btn";
      saveYoutubeBtn.textContent = "Save to YouTube";
      saveYoutubeBtn.dataset.postcardId = postcard.id || "";
      saveYoutubeBtn.setAttribute("data-save-youtube-button", "true");
      saveYoutubeBtn.setAttribute("data-has-video", hasVideoMedia ? "true" : "false");
      saveYoutubeBtn.disabled = !hasVideoMedia || isYouTubeUploadBlocked();
      if (!hasVideoMedia) {
        saveYoutubeBtn.setAttribute("aria-disabled", "true");
        saveYoutubeBtn.title = "No MP4 video available in this postcard.";
      } else if (isYouTubeUploadBlocked()) {
        saveYoutubeBtn.setAttribute("aria-disabled", "true");
        saveYoutubeBtn.title = getYouTubeUploadBlockedMessage();
      }
      saveYoutubeBtn.addEventListener("click", function () { onSaveToYouTube(postcard, saveYoutubeBtn); });
      actions.appendChild(downloadPostcardBtn);
      actions.appendChild(saveCollectionBtn);
      actions.appendChild(saveYoutubeBtn);
      footer.appendChild(actions);
      wrap.appendChild(footer);
      mediaGrid.appendChild(wrap);
    });
  }

  function fetchBirdBuddyFeed() {
    var googleToken = localStorage.getItem(STORAGE_KEYS.googleAccessToken);

    function applyFeedResponse(data) {
      var postcards = collectPostcardsFromFeed(data);
      var feed = data.data && data.data.me && data.data.me.feed;
      var pageInfo = feed && feed.pageInfo;
      hasMore = !!(pageInfo && pageInfo.hasNextPage && pageInfo.endCursor);
      loadMoreCursor = pageInfo && pageInfo.endCursor ? pageInfo.endCursor : null;

      appendPostcardGroups(postcards);
      mediaStatus.hidden = true;
      if (postcards.length === 0 && !loadMoreCursor) {
        mediaEmpty.hidden = false;
      }
      if (hasMore) {
        loadMoreBtn.disabled = false;
        loadAllBtn.disabled = false;
      }
      updateFooter();
    }

    function doFetch(token) {
      return fetchMe(token).then(function (meData) {
        applyCameraInventoryFromMeData(meData);
        return fetchInboxFeedWithSpeciesFallback(token, loadMoreCursor, true);
      }).then(function (data) {
        applyFeedResponse(data);
      });
    }

    var isInitialLoad = loadMoreCursor === null;
    if (isInitialLoad) setBusy(true);
    loadMoreBtn.disabled = true;

    function runWithRetryOnTokenExpired() {
      return getValidBbToken().then(function (token) {
        return doFetch(token);
      });
    }

    var p = runWithRetryOnTokenExpired();

    return p.catch(function (e) {
      if (e && e.isAuthError && googleToken) {
        var tokenExpiredContext = {
          operation: "fetchBirdBuddyFeed",
          timestamp: new Date().toISOString(),
          loadMoreCursor: loadMoreCursor,
          hasMore: hasMore
        };
        if (typeof e.tokenExpiredContext === "undefined") e.tokenExpiredContext = tokenExpiredContext;
        clearBirdBuddyTokens();
        return runWithRetryOnTokenExpired();
      }
      return Promise.reject(e);
    }).then(function () {
      mediaError.hidden = true;
    }).catch(function (e) {
      mediaStatus.hidden = true;
      mediaError.hidden = false;
      mediaError.textContent = e.message || "Failed to load feed";
      if (e && (e.isCorsError || e.isNetworkError || (e.message && /CORS|Failed to fetch|ERR_FAILED|network/i.test(e.message)))) {
        mediaError.textContent += " Check that Apache/PHP is running and the PHP cURL extension is enabled.";
      }
    }).finally(function () {
      if (isInitialLoad) setBusy(false);
      loadMoreBtn.disabled = false;
    });
  }

  function loadAllPostcards() {
    return new Promise(function (resolve, reject) {
      if (!hasMore || busy) {
        resolve();
        return;
      }
      deferSpeciesFilterRefresh = true;
      speciesFilterRefreshPending = false;
      loadMoreBtn.hidden = true;
      loadAllBtn.hidden = true;
      mediaStatus.hidden = false;
      mediaStatus.textContent = "Loading all postcards…";
      function finishFilterRefresh() {
        deferSpeciesFilterRefresh = false;
        refreshSpeciesFilterBar();
      }
      function next() {
        if (!hasMore) {
          mediaStatus.hidden = true;
          loadMoreBtn.hidden = true;
          loadAllBtn.hidden = true;
          updateDownloadAllVisibility();
          finishFilterRefresh();
          resolve();
          return;
        }
        fetchBirdBuddyFeed().then(next).catch(function (e) {
          mediaStatus.hidden = true;
          loadMoreBtn.hidden = false;
          loadAllBtn.hidden = false;
          updateDownloadAllVisibility();
          finishFilterRefresh();
          reject(e);
        });
      }
      next();
    });
  }

  function triggerDownloadsSequentially(links, delayMs) {
    var list = Array.prototype.slice.call(links || []);
    if (list.length === 0) return Promise.resolve();
    delayMs = typeof delayMs === "number" ? delayMs : 400;
    var i = 0;
    var failed = 0;
    var firstError = null;
    function next() {
      if (i >= list.length) {
        if (failed > 0) {
          var msg = failed + " download" + (failed === 1 ? "" : "s") + " failed.";
          if (firstError && firstError.message) msg += " " + firstError.message;
          return Promise.reject(new Error(msg));
        }
        return Promise.resolve();
      }
      var link = list[i++];
      return triggerDownloadForLink(link).catch(function (err) {
        failed += 1;
        if (!firstError) firstError = err || new Error("Download failed.");
      }).then(function () {
        return waitMs(delayMs);
      }).then(next);
    }
    return next();
  }

  function triggerAllDownloads() {
    return triggerDownloadsSequentially(mediaGrid.querySelectorAll(".media-link"), 400);
  }

  function getMediaLinksForPostcard(postcardId) {
    var targetId = String(postcardId || "").trim();
    if (!targetId) return [];
    var links = [];
    mediaGrid.querySelectorAll(".postcard-group").forEach(function (group) {
      if ((group.getAttribute("data-postcard-id") || "") !== targetId) return;
      group.querySelectorAll(".media-link").forEach(function (a) {
        links.push(a);
      });
    });
    return links;
  }

  function triggerDownloadsForPostcard(postcard, delayMs) {
    var postcardId = postcard && postcard.id ? String(postcard.id) : "";
    if (!postcardId) return Promise.resolve();
    var links = getMediaLinksForPostcard(postcardId);
    if (!links.length) return Promise.resolve();
    return triggerDownloadsSequentially(links, delayMs || 350);
  }

  function setPostcardDownloadButtonUploading(button, label) {
    if (!button) return;
    button.classList.add("btn-uploading");
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.textContent = label || "Downloading…";
  }

  function resetPostcardDownloadButton(button) {
    if (!button) return;
    button.classList.remove("btn-uploading");
    button.disabled = false;
    button.removeAttribute("aria-busy");
    button.textContent = "Download Postcard";
  }

  function onDownloadPostcard(postcard, button) {
    if (!postcard || !postcard.id) return;
    if (busy) return;
    var links = getMediaLinksForPostcard(postcard.id);
    if (!links.length) {
      setHeaderStatus("No media in this postcard.", true);
      return;
    }
    setBusy(true);
    mediaError.hidden = true;
    mediaStatus.hidden = false;
    mediaStatus.textContent = "Downloading postcard media…";
    setPostcardDownloadButtonUploading(button, "Downloading…");
    triggerDownloadsForPostcard(postcard, 350)
      .then(function () {
        mediaStatus.hidden = true;
        setHeaderStatus("Download started for postcard media. Check your browser downloads.", false);
      })
      .catch(function (e) {
        mediaStatus.hidden = true;
        setHeaderStatus((e && e.message) || "Postcard download failed.", true);
      })
      .finally(function () {
        resetPostcardDownloadButton(button);
        setBusy(false);
      });
  }

  function onSaveToCollection(postcard, button) {
    if (!postcard || !postcard.id) return;
    var saveButton = button || findCollectionSaveButton(postcard.id);
    if (isPostcardCollected(postcard)) {
      setPostcardCollectedUi(postcard.id, true);
      setHeaderStatus("Already saved to collection.", false);
      return;
    }
    if (busy) return;
    setBusy(true);
    mediaError.hidden = true;
    mediaStatus.hidden = false;
    mediaStatus.textContent = "Saving postcard " + (postcard.id.slice(0, 8)) + "…";
    setCollectionSaveButtonUploading(saveButton, "Saving…");
    collectPostcardInBirdBuddy(postcard)
      .then(function (result) {
        setPostcardCollectedUi(postcard.id, true);
        postcard.collected = true;
        postcard.itemType = "FeedItemCollectedPostcard";
        mediaStatus.hidden = true;
        if (result && result.alreadyCollected) {
          setHeaderStatus("Already saved to collection.", false);
          return;
        }
        var savedCount = result && typeof result.savedMediaCount === "number"
          ? result.savedMediaCount
          : (Array.isArray(postcard.medias) ? postcard.medias.length : 0);
        var target = result && result.shareToCommunity
          ? "private + community collections"
          : "private catch-all collection";
        setHeaderStatus(
          "Saved " + savedCount + " media item" + (savedCount === 1 ? "" : "s") + " to " + target + ".",
          false
        );
      })
      .catch(function (e) {
        resetCollectionSaveButton(saveButton, postcard);
        mediaStatus.hidden = true;
        mediaError.hidden = true;
        setHeaderStatus(e.message || "Save to collection failed", true);
      })
      .finally(function () { setBusy(false); });
  }

  function setSendToAdobeButton(button, state, label) {
    if (!button) return;
    button.classList.remove("uploading", "sent");
    if (state === "uploading") {
      button.classList.add("uploading");
      button.textContent = label || "Sending…";
      button.setAttribute("aria-busy", "true");
    } else if (state === "sent") {
      button.classList.add("sent");
      button.textContent = label || "Sent to Adobe";
      button.removeAttribute("aria-busy");
    } else {
      button.textContent = label || "Send to Adobe";
      button.removeAttribute("aria-busy");
    }
  }

  function getFrameioUsageId() {
    // Persist in localStorage (not sessionStorage) so every tab/reload in this
    // browser reuses the SAME server-side Adobe session — a new tab must not force
    // another Adobe login. One master login per browser session.
    var id = "";
    try { id = localStorage.getItem(FRAMEIO_USAGE_KEY) || ""; } catch (_) {}
    if (!id) { try { id = sessionStorage.getItem(FRAMEIO_USAGE_KEY) || ""; } catch (_) {} }
    if (!id || !/^[A-Za-z0-9_-]{16,128}$/.test(id)) {
      if (window.crypto && crypto.randomUUID) {
        id = crypto.randomUUID().replace(/-/g, "");
      } else {
        id = (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9_-]/g, "");
      }
    }
    try { localStorage.setItem(FRAMEIO_USAGE_KEY, id); } catch (_) {}
    try { sessionStorage.setItem(FRAMEIO_USAGE_KEY, id); } catch (_) {}
    return id;
  }

  // ----- Dedupe + auth-state helpers (media already pushed to Frame.io) -----
  function frameioMediaKey(m) {
    var basis = (m && m.url) ? String(m.url) : String((m && m.id) || "");
    try { return basis ? md5HashString(basis) : ""; } catch (_) { return ""; }
  }
  function loadFrameioSentMap() {
    try { return JSON.parse(localStorage.getItem(FRAMEIO_SENT_KEY) || "{}") || {}; } catch (_) { return {}; }
  }
  function isFrameioSent(key) {
    if (!key) return false;
    var m = loadFrameioSentMap();
    return Object.prototype.hasOwnProperty.call(m, key);
  }
  function getFrameioSentUrl(key) {
    var m = loadFrameioSentMap();
    return (key && m[key]) ? m[key] : "";
  }
  function recordFrameioSent(key, url) {
    if (!key) return;
    var m = loadFrameioSentMap();
    m[key] = url || m[key] || "sent";
    try { localStorage.setItem(FRAMEIO_SENT_KEY, JSON.stringify(m)); } catch (_) {}
  }
  function markFrameioSentButtons(key) {
    if (!key || !mediaGrid) return;
    try {
      mediaGrid.querySelectorAll('[data-frameio-button="true"][data-frameio-key="' + key + '"]').forEach(function (btn) {
        setSendToAdobeButton(btn, "sent", "Sent to Adobe ✓");
        btn.setAttribute("aria-disabled", "true");
        btn.classList.add("frameio-sent");
      });
    } catch (_) {}
  }
  function setFrameioAuthed(on) {
    try {
      if (on) localStorage.setItem(FRAMEIO_AUTHED_FLAG, "1");
      else localStorage.removeItem(FRAMEIO_AUTHED_FLAG);
    } catch (_) {}
  }
  function isFrameioAuthedFlag() {
    try { return localStorage.getItem(FRAMEIO_AUTHED_FLAG) === "1"; } catch (_) { return false; }
  }

  function beginFrameioUsage() {
    var usageId = getFrameioUsageId();
    return fetch(FRAMEIO_BEGIN_USAGE + "&usage_id=" + encodeURIComponent(usageId), { credentials: "same-origin", cache: "no-store" })
      .then(function (r) { return r.json().then(function (d) { return { response: r, data: d }; }); })
      .then(function (result) {
        if (!result.response.ok || !result.data.ok) throw new Error(result.data.error || "Could not initialize Adobe session.");
        return result.data;
      });
  }

  function frameioStatus() {
    return fetch(GBIRDS_FRAMEIO_BASE + "?api=frameio-status", { credentials: "same-origin", cache: "no-store" })
      .then(function (r) { return r.json().then(function (d) { return { response:r, data:d }; }); });
  }

  function startFrameioLogin() {
    return frameioStatus().then(function (result) {
      var data = result.data || {};
      if (!data.configured) throw new Error("Frame.io is not configured on the server.");
      window.location.href = GBIRDS_FRAMEIO_BASE + "?api=frameio-login";
      return new Promise(function () {});
    });
  }

  function sendToFrameio(postcard, media) {
    return fetch(GBIRDS_FRAMEIO_BASE + "?api=frameio-send", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({
        mediaUrl: media.url,
        filename: filenameFromMedia(media),
        createdAt: postcard.createdAt || "",
        species: formatSpeciesLabel(postcard),
        postcardId: postcard.id || ""
      })
    }).then(function (r) {
      return r.json().catch(function(){ return {}; }).then(function (data) {
        if (r.status === 401 && data.needsAuth) {
          // Do NOT full-page redirect here — signal the caller so it can re-auth
          // via the popup flow (keeps the GetBirds page loaded).
          var e = new Error(data.error || "Adobe sign-in required.");
          e.needsAuth = true;
          e.authorizeUrl = data.authorizeUrl || "";
          throw e;
        }
        if (!r.ok || !data.ok) throw new Error(data.error || "Could not send media to Adobe.");
        return data;
      });
    });
  }

  // NOTE: these result/preview windows are intentionally opened WITHOUT
  // "noopener" — noopener makes window.open() return null, which is what left a
  // stray about:blank tab behind and blocked reuse. We keep the handle so the
  // same window can be navigated to the asset preview and closed cleanly.
  function openFrameioErrorTab() {
    var url = "https://next.frame.io/project/" + encodeURIComponent(FRAMEIO_PROJECT_ID);
    var win = null;
    try { win = window.__firebirdFrameioResultTab || null; } catch (_) {}
    window.__firebirdFrameioResultTab = null;
    try { if (win && !win.closed) { win.location.href = url; return true; } } catch (_) {}
    try { return !!window.open(url, "firebirdFrameioResult"); } catch (_) { return false; }
  }

  function openFrameioAssetPreview(result) {
    var url = result && (result.viewUrl || (result.file && (result.file.view_url || result.file.viewUrl)));
    if (!url) url = "https://next.frame.io/project/" + encodeURIComponent(FRAMEIO_PROJECT_ID);
    // Reuse the window opened during the click gesture / the auth popup when
    // available (avoids the pop-up blocker); otherwise fall back to window.open.
    var win = null;
    try { win = window.__firebirdFrameioResultTab || null; } catch (_) {}
    window.__firebirdFrameioResultTab = null;
    try {
      if (win && !win.closed) { win.location.href = url; return { url: url, opened: true }; }
    } catch (_) {}
    var opened = false;
    try { opened = !!window.open(url, "firebirdFrameioResult"); } catch (_) { opened = false; }
    return { url: url, opened: opened };
  }

  function onFrameioSendSuccess(result, button, mediaKey) {
    setFrameioAuthed(true); // a successful send proves the server session is live
    setSendToAdobeButton(button, "sent", "Sent to Adobe ✓");
    // Record the media as pushed so it can never be re-uploaded (dedupe), and mark
    // every button for that media across the grid.
    if (mediaKey) {
      recordFrameioSent(mediaKey, result && result.viewUrl);
      markFrameioSentButtons(mediaKey);
    }
    var projectName = result.projectName || "Project_FIREBIRD";
    var dateName = (result && result.dateName) ? (' (' + result.dateName + ')') : "";
    var already = result && (result.duplicate || result.status === "already-sent");
    var verb = already ? "Already in " : "Sent to ";
    var preview = openFrameioAssetPreview(result);
    if (preview.opened) {
      setHeaderStatus(verb + projectName + dateName + " — opened the asset preview in Frame.io.", false);
    } else {
      // Post-redirect pop-ups can be blocked; surface the exact asset URL so the
      // uploaded asset is still one click away.
      setHeaderStatus(verb + projectName + dateName + ". Pop-up blocked — open the asset: " + preview.url, false, { persist: true });
    }
  }

  function runFrameioUpload(postcard, media, button, mediaKey) {
    setSendToAdobeButton(button, "uploading", "Sending…");
    return sendToFrameio(postcard, media).then(function (result) {
      onFrameioSendSuccess(result, button, mediaKey);
    });
  }

  function frameioAuthPayload(postcard, media) {
    return JSON.stringify({
      usage_id: getFrameioUsageId(),
      mediaUrl: media.url,
      filename: filenameFromMedia(media),
      createdAt: postcard.createdAt || "",
      species: formatSpeciesLabel(postcard),
      postcardId: postcard.id || ""
    });
  }

  // Popup-based Adobe IMS auth — mirrors the smooth "Save to YouTube" (Google
  // token popup) UX: the GetBirds page is NEVER reloaded, so the feed/state stay
  // put and the user is not dumped back on the sign-in page. The same popup is
  // then reused to show the uploaded asset's Frame.io preview.
  function startAdobeAuthPopup(postcard, media, button, authStartedKey, existingWin, mediaKey) {
    var popup = (existingWin && !existingWin.closed) ? existingWin : null;
    if (!popup) {
      try { popup = window.open("about:blank", "firebirdAdobeLogin", "width=640,height=780,menubar=no,toolbar=no,location=yes"); } catch (_) { popup = null; }
    }
    window.__firebirdFrameioResultTab = popup;
    setSendToAdobeButton(button, "uploading", "Connecting…");
    setHeaderStatus("Opening Adobe sign-in…", false, { persist: true });

    fetch(GBIRDS_FRAMEIO_BASE + "?api=frameio-auth-begin", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      credentials: "same-origin",
      cache: "no-store",
      body: frameioAuthPayload(postcard, media)
    }).then(function (r) {
      return r.json().catch(function(){ return {}; }).then(function (data) {
        if (!r.ok || !data.ok || !data.authorizeUrl) throw new Error(data.error || "Could not start Adobe IMS authentication.");
        sessionStorage.setItem(authStartedKey, "1");
        if (popup && !popup.closed) {
          popup.location.href = data.authorizeUrl;
          waitForAdobeAuthMessage(postcard, media, button, popup, authStartedKey, mediaKey);
        } else {
          // Popup blocked → fall back to a full-page redirect. The server callback
          // detects "no opener" and redirects back to ?frameio_resume=1, which
          // completes the upload on load.
          window.location.href = data.authorizeUrl;
        }
        return null;
      });
    }).catch(function (err) {
      try { if (popup && !popup.closed) popup.close(); } catch (_) {}
      window.__firebirdFrameioResultTab = null;
      sessionStorage.removeItem(authStartedKey);
      setSendToAdobeButton(button, "idle", "Send to Adobe");
      setHeaderStatus((err && err.message) || "Could not start Adobe IMS authentication.", true);
    });
  }

  function waitForAdobeAuthMessage(postcard, media, button, popup, authStartedKey, mediaKey) {
    var settled = false;
    var pollTimer = null;
    function cleanup() {
      settled = true;
      window.removeEventListener("message", onMsg);
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    function onMsg(ev) {
      if (settled) return;
      if (ev.origin !== window.location.origin) return;
      var d = ev.data || {};
      if (!d || d.type !== "firebird-frameio-auth") return;
      cleanup();
      if (!d.ok) {
        setFrameioAuthed(false);
        sessionStorage.removeItem(authStartedKey);
        setSendToAdobeButton(button, "idle", "Send to Adobe");
        setHeaderStatus(d.error ? ("Adobe sign-in failed: " + d.error) : "Adobe sign-in failed.", true, { persist: true });
        try { if (popup && !popup.closed) popup.close(); } catch (_) {}
        window.__firebirdFrameioResultTab = null;
        return;
      }
      // Authenticated inside the popup — this is the single master login. Remember
      // it so no further Send to Adobe re-prompts, then upload without reloading.
      setFrameioAuthed(true);
      setHeaderStatus("Adobe authorized. Uploading the selected media to Frame.io…", false, { persist: true });
      runFrameioUpload(postcard, media, button, mediaKey).catch(function (err) {
        setSendToAdobeButton(button, "idle", "Send to Adobe");
        setHeaderStatus((err && err.message) || "Could not send media to Adobe.", true);
        openFrameioErrorTab();
      });
    }
    window.addEventListener("message", onMsg);
    // If the user closes the sign-in window before it finishes, reset cleanly.
    pollTimer = setInterval(function () {
      if (settled) { clearInterval(pollTimer); pollTimer = null; return; }
      if (popup && popup.closed) {
        cleanup();
        sessionStorage.removeItem(authStartedKey);
        setSendToAdobeButton(button, "idle", "Send to Adobe");
        setHeaderStatus("Adobe sign-in window was closed before finishing.", true);
        window.__firebirdFrameioResultTab = null;
      }
    }, 800);
  }

  function onSendToAdobe(postcard, media, button) {
    if (!postcard || !media || !media.url || busy) return;

    // DEDUPE: a media already pushed to Frame.io is never re-uploaded. Re-clicking
    // just re-opens the existing asset preview.
    var mediaKey = frameioMediaKey(media);
    if (mediaKey && isFrameioSent(mediaKey)) {
      markFrameioSentButtons(mediaKey);
      var existing = getFrameioSentUrl(mediaKey);
      if (existing && existing !== "sent") {
        try { window.open(existing, "firebirdFrameioResult"); } catch (_) {}
        setHeaderStatus("Already sent to Adobe — reopened the asset preview.", false);
      } else {
        setHeaderStatus("This media was already sent to Adobe.", false);
      }
      return;
    }

    var authStartedKey = "firebird_frameio_ims_started_" + getFrameioUsageId();

    // Open ONE window inside the click gesture; it becomes either the Adobe login
    // popup or the asset-preview tab. (No separate about:blank tab.)
    var gestureWin = null;
    try { gestureWin = window.open("about:blank", "firebirdFrameioResult"); } catch (_) { gestureWin = null; }
    window.__firebirdFrameioResultTab = gestureWin;
    setSendToAdobeButton(button, "uploading", "Connecting…");

    function loginThenUpload() {
      startAdobeAuthPopup(postcard, media, button, authStartedKey, gestureWin, mediaKey);
    }
    function directUpload() {
      runFrameioUpload(postcard, media, button, mediaKey).catch(function (err) {
        if (err && err.needsAuth) {
          // Server session lapsed — re-auth via popup, reusing the gesture window.
          setFrameioAuthed(false);
          sessionStorage.removeItem(authStartedKey);
          startAdobeAuthPopup(postcard, media, button, authStartedKey, gestureWin, mediaKey);
          return;
        }
        try { if (gestureWin && !gestureWin.closed) gestureWin.close(); } catch (_) {}
        window.__firebirdFrameioResultTab = null;
        setSendToAdobeButton(button, "idle", "Send to Adobe");
        setHeaderStatus((err && err.message) || "Could not send media to Adobe.", true);
      });
    }

    // ONE MASTER LOGIN: only prompt Adobe login when the server session is not
    // already authenticated. If we already know it is (fast-path flag), upload
    // directly; otherwise confirm with the server before deciding.
    if (isFrameioAuthedFlag()) {
      directUpload();
      return;
    }
    frameioStatus().then(function (res) {
      var ready = res && res.data && (res.data.ready || res.data.authenticated);
      if (ready) { setFrameioAuthed(true); directUpload(); }
      else { loginThenUpload(); }
    }).catch(function () {
      loginThenUpload();
    });
  }

  function resumeFrameioSendAfterIms() {
    try {
      var params = new URLSearchParams(window.location.search || "");
      if (params.get("frameio_resume") !== "1") return Promise.resolve(false);
    } catch (_) {
      return Promise.resolve(false);
    }
    setHeaderStatus("Adobe authorized. Sending the selected media to Frame.io…", false, { persist: true });
    return fetch(GBIRDS_FRAMEIO_BASE + "?api=frameio-resume-send", {
      method: "POST",
      headers: { "Accept": "application/json" },
      credentials: "same-origin",
      cache: "no-store"
    }).then(function (r) {
      return r.json().catch(function(){ return {}; }).then(function (data) {
        if (!r.ok || !data.ok) throw new Error(data.error || "Could not resume the Frame.io upload.");
        setFrameioAuthed(true);
        // No button/mediaKey after a full-page redirect; server-side dedupe still
        // prevents any re-upload of this media.
        onFrameioSendSuccess(data, null, "");
        return true;
      });
    });
  }

  function getPrimarySpeciesForYouTube(postcard) {
    var list = getCanonicalSpeciesList(postcard);
    return list.length ? list[0] : UNKNOWN_SPECIES_LABEL;
  }

  function formatYouTubeTitleDate(value) {
    if (value == null || value === "") return "Unknown date";
    var d = new Date(value);
    if (isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" });
  }

  function dedupeTagList(tags) {
    var out = [];
    var seen = {};
    (tags || []).forEach(function (tag) {
      var t = String(tag || "").trim();
      if (!t) return;
      var key = t.toLowerCase();
      if (seen[key]) return;
      seen[key] = true;
      out.push(t);
    });
    return out;
  }

  function getPostcardVideoMedia(postcard) {
    var medias = postcard && Array.isArray(postcard.medias) ? postcard.medias : [];
    return medias.filter(function (m) {
      return !!(m && m.isVideo && m.url);
    });
  }

  function postcardHasVideoMedia(postcard) {
    return getPostcardVideoMedia(postcard).length > 0;
  }

  function getMediaDownloadPath(m) {
    if (!m || !m.url) return "—";
    try {
      return new URL(m.url).toString();
    } catch (_) {
      return String(m.url);
    }
  }

  function clampYouTubeTitle(title) {
    var t = String(title || "");
    if (t.length <= 100) return t;
    return t.slice(0, 97) + "...";
  }

  function clampChars(text, maxChars) {
    var t = String(text || "").trim();
    if (maxChars <= 0) return "";
    if (t.length <= maxChars) return t;
    if (maxChars <= 3) return t.slice(0, maxChars);
    return t.slice(0, maxChars - 3).trimEnd() + "...";
  }

  function utf8ByteLength(text) {
    var value = String(text || "");
    if (typeof TextEncoder !== "undefined") {
      return new TextEncoder().encode(value).length;
    }
    try {
      return unescape(encodeURIComponent(value)).length;
    } catch (_) {
      return value.length;
    }
  }

  function clampUtf8Bytes(text, maxBytes) {
    var value = String(text || "");
    if (maxBytes <= 0) return "";
    if (utf8ByteLength(value) <= maxBytes) return value;
    var low = 0;
    var high = value.length;
    while (low < high) {
      var mid = Math.ceil((low + high) / 2);
      var candidate = value.slice(0, mid);
      if (utf8ByteLength(candidate) <= maxBytes) {
        low = mid;
      } else {
        high = mid - 1;
      }
    }
    return value.slice(0, low);
  }

  function sanitizeYouTubeTags(tags, maxTotalChars) {
    var cleaned = [];
    var seen = {};
    var total = 0;
    var limit = typeof maxTotalChars === "number" && maxTotalChars > 0 ? maxTotalChars : 500;
    (Array.isArray(tags) ? tags : []).forEach(function (tag) {
      var t = String(tag || "").replace(/[<>]/g, " ").replace(/\s+/g, " ").trim();
      if (!t) return;
      if (t.length > 60) t = t.slice(0, 60).trim();
      var key = t.toLowerCase();
      if (seen[key]) return;
      var extra = (cleaned.length ? 1 : 0) + t.length;
      if (total + extra > limit) return;
      seen[key] = true;
      cleaned.push(t);
      total += extra;
    });
    return cleaned;
  }

  function sanitizeYouTubeMetadata(metadata) {
    var m = metadata && typeof metadata === "object" ? metadata : {};
    var snippet = m.snippet && typeof m.snippet === "object" ? m.snippet : {};
    var status = m.status && typeof m.status === "object" ? m.status : {};
    var privacy = String(status.privacyStatus || "public").toLowerCase();
    if (privacy !== "public" && privacy !== "private" && privacy !== "unlisted") privacy = "public";
    var outSnippet = {
      title: clampYouTubeTitle(snippet.title || "GetBirds postcard")
    };
    var description = clampChars(snippet.description || "", 5000);
    description = clampUtf8Bytes(description, 5000).trim();
    if (description) outSnippet.description = description;
    var tags = sanitizeYouTubeTags(snippet.tags || [], 450);
    if (tags.length) outSnippet.tags = tags;
    outSnippet.categoryId = String(snippet.categoryId || "22").trim() || "22";
    var outStatus = {
      privacyStatus: privacy
    };
    if (typeof status.selfDeclaredMadeForKids === "boolean") {
      outStatus.selfDeclaredMadeForKids = status.selfDeclaredMadeForKids;
    }
    return {
      snippet: outSnippet,
      status: outStatus
    };
  }

  function buildYouTubeInsertMetadataAttempts(metadata) {
    var safe = sanitizeYouTubeMetadata(metadata);
    var attempts = [];
    attempts.push(safe);
    attempts.push({
      snippet: {
        title: safe.snippet.title,
        description: safe.snippet.description || "",
        categoryId: safe.snippet.categoryId || "22"
      },
      status: safe.status
    });
    attempts.push({
      snippet: {
        title: safe.snippet.title,
        categoryId: safe.snippet.categoryId || "22"
      },
      status: {
        privacyStatus: safe.status.privacyStatus
      }
    });
    attempts.push({
      snippet: {
        title: safe.snippet.title
      },
      status: {
        privacyStatus: safe.status.privacyStatus
      }
    });
    return attempts;
  }

  function buildCompactYouTubeMetadata(metadata) {
    var normalized = sanitizeYouTubeMetadata(metadata);
    var compact = {
      snippet: {
        title: clampYouTubeTitle(normalized.snippet.title || "GetBirds postcard")
      }
    };
    if (!compact.snippet.title) {
      compact.snippet.title = "GetBirds postcard";
    }
    return compact;
  }

  function buildUploadBootstrapMetadata(metadata) {
    var normalized = sanitizeYouTubeMetadata(metadata);
    return {
      snippet: {
        title: clampYouTubeTitle(normalized.snippet.title || "GetBirds postcard")
      }
    };
  }

  function buildUltraMinimalUploadMetadata(metadata) {
    var normalized = sanitizeYouTubeMetadata(metadata);
    return {
      snippet: {
        title: clampYouTubeTitle(normalized.snippet.title || "GetBirds postcard")
      }
    };
  }

  function buildPublishOnlyMetadata(metadata) {
    var normalized = sanitizeYouTubeMetadata(metadata);
    var out = {
      snippet: {
        title: clampYouTubeTitle(normalized.snippet.title || "GetBirds postcard"),
        categoryId: String(normalized.snippet.categoryId || "22")
      },
      status: {
        privacyStatus: String(normalized.status.privacyStatus || "public")
      }
    };
    if (typeof normalized.status.selfDeclaredMadeForKids === "boolean") {
      out.status.selfDeclaredMadeForKids = normalized.status.selfDeclaredMadeForKids;
    }
    return out;
  }

  function normalizeSpeciesListForYouTube(postcard) {
    return getCanonicalSpeciesList(postcard);
  }

  function pickPrimarySpeciesFromList(speciesList) {
    var list = Array.isArray(speciesList) ? speciesList : [];
    for (var i = 0; i < list.length; i++) {
      var s = canonicalizeSpeciesLabel(list[i]);
      if (s && !isUnknownSpeciesLabel(s)) return s;
    }
    return list.length ? canonicalizeSpeciesLabel(list[0]) || UNKNOWN_SPECIES_LABEL : UNKNOWN_SPECIES_LABEL;
  }

  function getSpeciesTriviaLine(speciesName) {
    var canonical = canonicalizeSpeciesLabel(speciesName);
    var key = canonical.toLowerCase();
    var triviaMap = {
      "northern flicker": "Northern Flickers are one of the few woodpeckers that forage on the ground for ants.",
      "house finch": "House Finches can signal local habitat quality through subtle shifts in plumage color.",
      "american robin": "American Robins can produce several broods in one season when food is abundant.",
      "downy woodpecker": "Downy Woodpeckers can cling to thin stems thanks to stiff tail feathers and zygodactyl feet.",
      "black-capped chickadee": "Black-capped Chickadees cache seeds and remember many hiding spots over long periods.",
      "european starling": "European Starlings can mimic sounds and coordinate in large murmurations."
    };
    if (triviaMap[key]) return triviaMap[key];
    if (isUnknownSpeciesLabel(canonical)) {
      return "Unknown sightings still reveal behavior patterns, weather effects, and feeding timing over time.";
    }
    return canonical + " sightings can expose repeatable feeding windows and behavior shifts across seasons.";
  }

  function seededIndex(seed, modulo, salt) {
    if (!modulo || modulo <= 0) return 0;
    var hash = md5HashString(String(seed || "") + "|" + String(salt || ""));
    var n = parseInt(hash.slice(0, 8), 16);
    if (!isFinite(n)) n = 0;
    return n % modulo;
  }

  function pickSeeded(list, seed, salt) {
    if (!Array.isArray(list) || list.length === 0) return "";
    return list[seededIndex(seed, list.length, salt)];
  }

  function formatNaturalList(items) {
    var out = [];
    for (var i = 0; i < (items || []).length; i++) {
      var t = canonicalizeSpeciesLabel(items[i]);
      if (!t) continue;
      out.push(t);
    }
    if (out.length === 0) return UNKNOWN_SPECIES_LABEL;
    if (out.length === 1) return out[0];
    if (out.length === 2) return out[0] + " and " + out[1];
    return out.slice(0, -1).join(", ") + ", and " + out[out.length - 1];
  }

  function getSpeciesFactLines(speciesName) {
    var canonical = canonicalizeSpeciesLabel(speciesName);
    var key = canonical.toLowerCase();
    var facts = {
      "house finch": [
        "Male House Finches usually get redder when their diet has more carotenoids, so color can hint at food quality.",
        "House Finches thrive around human neighborhoods but still shift feeder timing quickly when weather changes.",
        "Their song can be long and warbly, and individuals often remix phrases like tiny jazz vocalists."
      ],
      "brown-headed cowbird": [
        "Brown-Headed Cowbirds are brood parasites that lay eggs in other birds' nests instead of building their own.",
        "Cowbirds historically tracked grazing herds, taking advantage of insects disturbed by large mammals.",
        "Host species vary in how well they detect and reject cowbird eggs, shaping local nesting outcomes."
      ],
      "northern flicker": [
        "Northern Flickers are unusual woodpeckers because they often forage on the ground for ants.",
        "Their tongues are built for ant-heavy diets, and they can consume large numbers of insects in one day.",
        "Flickers flash bright underwing colors in flight, which can be useful for identification."
      ],
      "american robin": [
        "American Robins can raise multiple broods in one season when conditions are good.",
        "Robins often use visual cues to locate prey and can quickly exploit worm activity after rain.",
        "Even common robins show local behavior differences based on yard layout and predator pressure."
      ],
      "downy woodpecker": [
        "Downy Woodpeckers use stiff tail feathers and specialized feet to brace against bark.",
        "They often tap lightly while foraging, listening for insect movement beneath wood.",
        "Downies are small but persistent, and their winter feeder visits can become highly regular."
      ],
      "black-capped chickadee": [
        "Black-capped Chickadees cache seeds and remember many storage locations with remarkable accuracy.",
        "Chickadee call structure can encode predator risk, and flock members react to that detail.",
        "Their winter survival depends on rapid foraging decisions and social information sharing."
      ],
      "blue jay": [
        "Blue Jays cache acorns and can help move oak trees across landscapes over time.",
        "They use varied calls and can mimic other birds, adding complexity to backyard soundscapes.",
        "Jay social behavior can look dramatic, but much of it is strategic communication."
      ],
      "mourning dove": [
        "Mourning Doves can produce nutrient-rich crop milk to feed chicks.",
        "Their wing whistle at takeoff is caused by specialized feather structure.",
        "Doves often feed quickly on open ground and rely on fast escape behavior."
      ],
      "european starling": [
        "European Starlings can mimic sounds and perform highly coordinated flock maneuvers.",
        "Starling murmurations are shaped by local neighbor interactions and rapid response timing.",
        "Their iridescent plumage can look plain from one angle and metallic from another."
      ],
      "red-winged blackbird": [
        "Male Red-winged Blackbirds defend breeding territories aggressively during nesting season.",
        "Their iconic song is part display, part warning, and part territory map.",
        "Female Red-winged Blackbirds use cover and nest placement to reduce predation risk."
      ]
    };
    if (facts[key] && facts[key].length) return facts[key];
    if (isUnknownSpeciesLabel(canonical)) {
      return [
        "Unknown birds still provide real data through posture, feeding rhythm, flock spacing, and alarm responses.",
        "Mystery sightings are often resolved by combining behavior, silhouette, and repeat visits over time.",
        "In feeder ecology, uncertainty is useful: it highlights exactly what observations to capture next."
      ];
    }
    return [
      canonical + " can be profiled through feeder timing, posture, perch preference, and weather-linked activity shifts.",
      "Repeated sightings of " + canonical + " help separate random visits from true behavior patterns.",
      "Even one short clip of " + canonical + " can teach useful details about local habitat and food pressure."
    ];
  }

  function buildSpeciesScene(speciesName, createdTitleDate, seed, index) {
    var species = canonicalizeSpeciesLabel(speciesName) || UNKNOWN_SPECIES_LABEL;
    var facts = getSpeciesFactLines(species);
    var factA = facts[seededIndex(seed, facts.length, "fact-a-" + index)];
    var factB = facts[seededIndex(seed, facts.length, "fact-b-" + index + "-x")];
    if (factA === factB && facts.length > 1) {
      factB = facts[(seededIndex(seed, facts.length, "fact-b2-" + index) + 1) % facts.length];
    }
    var sceneOpeners = [
      "Scene " + (index + 1) + ": the camera catches " + species + " cannonballing into frame like a tiny superhero paid in sunflower seeds.",
      "Scene " + (index + 1) + ": " + species + " lands with Saturday-morning-cartoon confidence and zero concern for feeder traffic laws.",
      "Scene " + (index + 1) + ": " + species + " touches down, pauses, and delivers a dramatic head tilt worthy of an orchestra hit."
    ];
    var comedyTags = [
      "The seed tray reacts like it just heard a plot-twist trombone.",
      "Background extras blink twice and instantly form a suspiciously organized committee.",
      "Somewhere offscreen, a serious squirrel files a formal complaint and is politely ignored."
    ];
    var open = pickSeeded(sceneOpeners, seed, "scene-open-" + index);
    var comedy = pickSeeded(comedyTags, seed, "scene-comedy-" + index);
    if (isUnknownSpeciesLabel(species)) {
      return (
        open +
        " The species label is " + UNKNOWN_SPECIES_LABEL + ", which upgrades this episode into a mystery special nobody saw coming. " +
        factA + " " + factB + " " + comedy
      );
    }
    return open + " " + factA + " " + factB + " " + comedy;
  }

  function applyTemplatePlaceholders(text, speciesListLabel, createdTitleDate) {
    return String(text || "")
      .replace(/\{species\}/g, speciesListLabel)
      .replace(/\{date\}/g, createdTitleDate);
  }

  function expandDescriptionToNearLimit(baseText, seed, speciesListLabel, createdTitleDate) {
    var out = String(baseText || "").trim();
    var targetChars = 5000;
    var maxChars = 5000;
    var expansions = [
      "Cartoon-energy fact drop: birds may look chaotic, but feeder behavior is structured. Timing, perch choice, and peck order can signal dominance and risk tolerance in real time.",
      "The plot on {date} moves fast because HH5HH's GetBirds app is basically a turbo button for sorting and publishing, so @BirdsOfBroomfield can spend less time tapping menus and more time watching feathered theater.",
      "Science with giggles: {species} can be analyzed by feeding cadence, posture, and spacing from neighbors, and yes, that absolutely counts as educational entertainment.",
      "If this were a cartoon episode, this is the moment the brass section goes bananas while one bird steals center stage and another bird pretends it definitely meant to miss the landing.",
      "Ornithology note: repeated clips across days help separate random chance from true behavior patterns, especially when weather and light conditions shift.",
      "Viewer mission: track who arrives first, who gets displaced, and who quietly reroutes. That tiny choreography reveals more ecology than people expect.",
      "Every cut in this upload says the same thing: fast tooling matters. HH5HH's GetBirds flow keeps momentum high while the birds keep improvising nonsense at professional speed.",
      "Mildly ridiculous but true: one feeder can host strategy, comedy, and applied animal behavior in the same minute, which is why these uploads are both funny and useful.",
      "Unknown species moments are not failures. They are clues. Clues drive better observation, better ID, and better episodes the next time the mystery guest appears.",
      "Family-friendly chaos report: nobody swears, everyone snacks, and the drama still lands harder than most scripted TV.",
      "Education checkpoint: body shape, tail movement, and food handling often identify birds more reliably than one blurry color patch.",
      "Backyard lore update: if {species} had an agent, it would demand top billing, hazard pay, and first rights to the premium perch."
    ];
    var i = 0;
    while (out.length < targetChars && i < 80) {
      var add = pickSeeded(expansions, seed, "expand-" + i);
      add = applyTemplatePlaceholders(add, speciesListLabel, createdTitleDate);
      out += "\n\n" + add;
      i += 1;
    }
    return clampChars(out, maxChars);
  }

  function buildYouTubeLongDescription(postcard, speciesList, createdTitleDate) {
    var seed = (postcard && postcard.id) || (postcard && postcard.createdAt) || createdTitleDate || "getbirds";
    var list = Array.isArray(speciesList) && speciesList.length ? speciesList : [UNKNOWN_SPECIES_LABEL];
    var speciesListLabel = formatNaturalList(list);
    var openerTemplates = [
      "Cold open: @BirdsOfBroomfield on IG goes live at the feeder, {species} storms the stage on {date}, and HH5HH's GetBirds app rockets this from raw clips to publish-ready hilarity in record time.",
      "Welcome back to the seed-powered cartoon universe: {species} appears on {date}, @BirdsOfBroomfield captures the chaos, and HH5HH's GetBirds app turns it into a same-day wildlife blockbuster.",
      "Today in family-friendly feather mayhem: {species} enters frame on {date}, steals the spotlight, and GetBirds by HH5HH moves the whole episode from capture to upload before the snacks run out."
    ];
    var actOneTemplates = [
      "Act I - Setup. The camera catches micro-behaviors before humans finish guessing names: posture, spacing, and peck timing all hint at strategy.",
      "Act I - Establishing shot. The feeder looks peaceful for six seconds, then one confident landing rewrites neighborhood diplomacy.",
      "Act I - Opening tension. Perch positions and eye-lines expose social rank faster than any narrated documentary voice-over."
    ];
    var actTwoTemplates = [
      "Act II - Escalation. Comedy rises, but so does the science: behavior becomes evidence, and evidence sharpens identification.",
      "Act II - Momentum. Clip by clip, this turns into a lesson in foraging strategy, risk management, and social negotiation with bonus slapstick.",
      "Act II - Rising action. Every new arrival rewrites the script in real time, and the feeder becomes an improv stage with ornithology baked in."
    ];
    var finaleTemplates = [
      "Finale. Upload lands while the joke is still hot: quick workflow, high replay value, and one more episode in the backyard bird cinematic universe.",
      "Finale. Story shipped, facts intact, laughs secured, and the feeder cast is already rehearsing the sequel with no budget meetings required.",
      "Finale. Fast capture-to-publish means less button mashing and more wildlife storytelling people actually want to watch, share, and learn from."
    ];

    var paragraphs = [];
    paragraphs.push(applyTemplatePlaceholders(pickSeeded(openerTemplates, seed, "opener"), speciesListLabel, createdTitleDate));
    paragraphs.push(pickSeeded(actOneTemplates, seed, "act1"));
    paragraphs.push(pickSeeded(actTwoTemplates, seed, "act2"));

    for (var i = 0; i < list.length; i++) {
      paragraphs.push(buildSpeciesScene(list[i], createdTitleDate, seed, i));
    }

    var primarySpecies = pickPrimarySpeciesFromList(list);
    paragraphs.push(
      "Trivia drop: " + getSpeciesTriviaLine(primarySpecies) +
      " Translation: this is educational, timely, and still ridiculous in the best possible way."
    );
    paragraphs.push(
      "Behind the scenes: HH5HH's GetBirds app keeps the production line moving faster than legacy tap-heavy workflows, " +
      "so @BirdsOfBroomfield spends more time watching birds and less time wrestling UI."
    );
    paragraphs.push(pickSeeded(finaleTemplates, seed, "finale"));
    paragraphs.push("For more episodes, field notes, and feathered plot twists, follow @BirdsOfBroomfield on IG.");

    var base = paragraphs.join("\n\n");
    return expandDescriptionToNearLimit(base, seed, speciesListLabel, createdTitleDate);
  }

  function buildYouTubeSnippetFromPostcard(postcard, videoIndex, totalVideos) {
    var speciesList = normalizeSpeciesListForYouTube(postcard);
    var primarySpecies = pickPrimarySpeciesFromList(speciesList);
    var speciesStr = getPrimarySpeciesForYouTube(postcard);
    var createdTitleDate = formatYouTubeTitleDate(postcard.createdAt);
    var title = speciesStr + " from GetBirds app on " + createdTitleDate + " by @BirdsOfBroomfield";
    if (totalVideos > 1) title += " (" + (videoIndex + 1) + "/" + totalVideos + ")";
    title = clampYouTubeTitle(title);
    var description = buildYouTubeLongDescription(postcard, speciesList, createdTitleDate);
    var tags = ["GetBirds", "BirdBuddy", "bird feeder", "postcard", "hh5hh", "birdsofbroomfield"];
    if (primarySpecies && primarySpecies.toLowerCase() !== "unknown") tags.push(primarySpecies);
    for (var i = 0; i < speciesList.length; i++) {
      var s = speciesList[i];
      if (!s || s.toLowerCase() === "unknown") continue;
      tags.push(s);
    }
    if (postcard.id) tags.push("postcard-" + postcard.id.slice(0, 8));
    tags = dedupeTagList(tags);
    return { title: title, description: description, tags: tags };
  }

  function uploadOneVideoMultipartOnce(blob, metadata, accessToken) {
    var boundary = "ytboundary" + Math.random().toString(36).slice(2, 14);
    var contentType = blob.type || "video/mp4";
    var prefix =
      "--" + boundary + "\r\n" +
      "Content-Type: application/json; charset=UTF-8\r\n\r\n" +
      JSON.stringify(metadata) + "\r\n" +
      "--" + boundary + "\r\n" +
      "Content-Type: " + contentType + "\r\n\r\n";
    var suffix = "\r\n--" + boundary + "--\r\n";
    var body = new Blob([prefix, blob, suffix], { type: "multipart/related; boundary=" + boundary });
    var url = "https://www.googleapis.com/upload/youtube/v3/videos?uploadType=multipart&part=snippet,status";
    return fetch(url, {
      method: "POST",
      headers: {
        "Authorization": "Bearer " + accessToken,
        "Content-Type": "multipart/related; boundary=" + boundary
      },
      body: body
    }).then(function (res) {
      return res.text().then(function (text) {
        if (!res.ok) {
          var details = parseYouTubeApiErrorDetails(text, "YouTube upload failed.");
          var err = new Error(details.message);
          err.status = res.status;
          err.raw = text;
          err.youtubeReason = details.reason || "";
          err.youtubeCode = details.code || 0;
          err.retryAfterSeconds = parseRetryAfterSeconds(res.headers && res.headers.get ? res.headers.get("Retry-After") : "");
          throw err;
        }
        if (!text) return {};
        try {
          return JSON.parse(text);
        } catch (_) {
          return {};
        }
      });
    });
  }

  /** Upload with progressive metadata fallbacks for strict videos.insert validation. */
  async function uploadOneVideoMultipart(blob, metadata, accessToken) {
    var attempts = buildYouTubeInsertMetadataAttempts(metadata);
    var lastErr = null;
    for (var i = 0; i < attempts.length; i++) {
      try {
        var result = await uploadOneVideoMultipartOnce(blob, attempts[i], accessToken);
        if (result && typeof result === "object") {
          result.__metadataAttempt = i;
          result.__usedFallback = i > 0;
        }
        return result;
      } catch (err) {
        lastErr = err;
        if (isYouTubeUploadLimitError(err)) throw err;
        var isBadRequest = err && Number(err.status) === 400;
        if (!isBadRequest || i === attempts.length - 1) throw err;
      }
    }
    throw lastErr || new Error("YouTube upload failed.");
  }

  function parseYouTubeApiErrorDetails(text, fallbackMessage) {
    var out = {
      message: fallbackMessage || "YouTube API request failed.",
      reason: "",
      code: 0
    };
    if (!text) return out;
    try {
      var data = JSON.parse(text);
      if (data && data.error) {
        var errObj = data.error;
        out.code = Number(errObj.code) || 0;
        out.message = errObj.message || out.message;
        var first = Array.isArray(errObj.errors) && errObj.errors.length ? errObj.errors[0] : null;
        if (first) {
          if (first.reason) out.reason = String(first.reason);
          if (!out.message && first.message) out.message = String(first.message);
        }
        if (out.reason && out.message.indexOf("[reason: ") === -1) {
          out.message = out.message + " [reason: " + out.reason + "]";
        }
        if (out.message) return out;
      }
    } catch (_) {}
    out.message = text || out.message;
    return out;
  }

  function parseYouTubeApiError(text, fallbackMessage) {
    return parseYouTubeApiErrorDetails(text, fallbackMessage).message;
  }

  function youtubeApiJson(url, opts, fallbackMessage) {
    opts = opts || {};
    return fetch(url, opts).then(function (res) {
      return res.text().then(function (text) {
        if (!res.ok) throw new Error(parseYouTubeApiError(text, fallbackMessage || ("YouTube API request failed (" + res.status + ").")));
        if (!text) return {};
        try {
          return JSON.parse(text);
        } catch (_) {
          return {};
        }
      });
    });
  }

  function updateYouTubeVideoMetadata(accessToken, videoId, metadata) {
    if (!accessToken || !videoId) return Promise.resolve({});
    var safe = sanitizeYouTubeMetadata(metadata);
    return youtubeApiJson("https://www.googleapis.com/youtube/v3/videos?part=snippet,status", {
      method: "PUT",
      headers: {
        "Authorization": "Bearer " + accessToken,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        id: videoId,
        snippet: safe.snippet,
        status: safe.status
      })
    }, "Could not apply full YouTube metadata.");
  }

  function findGetBirdsPlaylistId(accessToken, playlistName) {
    var normalized = String(playlistName || "").trim().toLowerCase();
    var pageToken = "";

    function nextPage() {
      var params = new URLSearchParams({
        part: "snippet",
        mine: "true",
        maxResults: "50"
      });
      if (pageToken) params.set("pageToken", pageToken);
      var url = "https://www.googleapis.com/youtube/v3/playlists?" + params.toString();
      return youtubeApiJson(url, {
        method: "GET",
        headers: { "Authorization": "Bearer " + accessToken }
      }, "Could not list YouTube playlists.")
        .then(function (data) {
          var items = (data && data.items) || [];
          for (var i = 0; i < items.length; i++) {
            var it = items[i] || {};
            var title = (((it.snippet || {}).title) || "").trim().toLowerCase();
            if (title === normalized && it.id) return it.id;
          }
          pageToken = data && data.nextPageToken ? data.nextPageToken : "";
          if (!pageToken) return "";
          return nextPage();
        });
    }

    return nextPage();
  }

  function createGetBirdsPlaylist(accessToken, playlistName) {
    return youtubeApiJson("https://www.googleapis.com/youtube/v3/playlists?part=snippet,status", {
      method: "POST",
      headers: {
        "Authorization": "Bearer " + accessToken,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        snippet: {
          title: playlistName,
          description: "Uploads from the GetBirds app."
        },
        status: {
          privacyStatus: "private"
        }
      })
    }, "Could not create the GetBirds playlist.")
      .then(function (data) {
        var id = data && data.id;
        if (!id) throw new Error("Could not create the GetBirds playlist.");
        return id;
      });
  }

  function ensureGetBirdsPlaylist(accessToken) {
    var playlistName = "GetBirds";
    return findGetBirdsPlaylistId(accessToken, playlistName).then(function (existingId) {
      if (existingId) return existingId;
      return createGetBirdsPlaylist(accessToken, playlistName);
    });
  }

  function addVideoToPlaylist(accessToken, playlistId, videoId) {
    if (!playlistId || !videoId) return Promise.resolve();
    return youtubeApiJson("https://www.googleapis.com/youtube/v3/playlistItems?part=snippet", {
      method: "POST",
      headers: {
        "Authorization": "Bearer " + accessToken,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        snippet: {
          playlistId: playlistId,
          resourceId: {
            kind: "youtube#video",
            videoId: videoId
          }
        }
      })
    }, "Could not add uploaded video to the GetBirds playlist.");
  }

  function isLikelyJpgMediaUrl(url) {
    var u = String(url || "").toLowerCase();
    return /\.jpe?g(?:$|[?#])/i.test(u);
  }

  function waitMs(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function getImageCandidateUrls(media) {
    var out = [];
    function push(url) {
      var u = String(url || "").trim();
      if (!u) return;
      if (out.indexOf(u) !== -1) return;
      out.push(u);
    }
    push(media && media.url);
    push(media && media.thumb);
    return out;
  }

  function getFinalThumbnailSourceFromPostcard(postcard) {
    var medias = postcard && postcard.medias ? postcard.medias : [];
    var bestJpg = null;
    var bestAny = null;
    var bestJpgScore = -Infinity;
    var bestAnyScore = -Infinity;
    for (var i = 0; i < medias.length; i++) {
      var m = medias[i];
      if (!m || m.isVideo) continue;
      var score = typeof m.sourceIndex === "number" ? m.sourceIndex : i;
      var urls = getImageCandidateUrls(m);
      if (urls.length === 0) continue;

      if (score >= bestAnyScore) {
        bestAny = { media: m, url: urls[0], isJpg: isLikelyJpgMediaUrl(urls[0]), sourceIndex: score };
        bestAnyScore = score;
      }

      for (var j = 0; j < urls.length; j++) {
        var u = urls[j];
        if (!isLikelyJpgMediaUrl(u)) continue;
        var preferPrimaryUrl = j === 0 ? 1 : 0;
        var candidateScore = score * 10 + preferPrimaryUrl;
        if (candidateScore >= bestJpgScore) {
          bestJpg = { media: m, url: u, isJpg: true, sourceIndex: score };
          bestJpgScore = candidateScore;
        }
      }
    }
    return bestJpg || bestAny || null;
  }

  function imageElementFromBlob(blob) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(blob);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error("Could not decode postcard image for thumbnail."));
      };
      img.src = url;
    });
  }

  function canvasToJpegBlob(canvas, quality) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (!blob) {
          reject(new Error("Could not encode thumbnail image."));
          return;
        }
        resolve(blob);
      }, "image/jpeg", quality);
    });
  }

  async function buildYouTubePreviewThumbnailBlob(sourceBlob) {
    if (!sourceBlob) throw new Error("Postcard thumbnail blob is missing.");
    var img = await imageElementFromBlob(sourceBlob);
    var targetWidth = 1280;
    var targetHeight = 720;
    var canvas = document.createElement("canvas");
    canvas.width = targetWidth;
    canvas.height = targetHeight;
    var ctx = canvas.getContext("2d");
    if (!ctx) throw new Error("Could not initialize canvas for thumbnail rendering.");
    var scale = Math.max(targetWidth / img.width, targetHeight / img.height);
    var drawWidth = img.width * scale;
    var drawHeight = img.height * scale;
    var drawX = (targetWidth - drawWidth) / 2;
    var drawY = (targetHeight - drawHeight) / 2;
    ctx.fillStyle = "#000";
    ctx.fillRect(0, 0, targetWidth, targetHeight);
    ctx.drawImage(img, drawX, drawY, drawWidth, drawHeight);
    var qualities = [0.9, 0.82, 0.74, 0.66, 0.58];
    var maxBytes = 2 * 1024 * 1024;
    var out = null;
    for (var i = 0; i < qualities.length; i++) {
      out = await canvasToJpegBlob(canvas, qualities[i]);
      if (out.size <= maxBytes) return out;
    }
    if (out && out.size <= maxBytes) return out;
    throw new Error("Prepared thumbnail is larger than YouTube's 2MB limit.");
  }

  function setYouTubeVideoThumbnail(accessToken, videoId, thumbnailBlob) {
    if (!accessToken || !videoId || !thumbnailBlob) return Promise.resolve({});
    var url = "https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=" + encodeURIComponent(videoId);
    return fetch(url, {
      method: "POST",
      headers: {
        "Authorization": "Bearer " + accessToken,
        "Content-Type": "image/jpeg"
      },
      body: thumbnailBlob
    }).then(function (res) {
      return res.text().then(function (text) {
        if (!res.ok) {
          var msg = parseYouTubeApiError(text, "Could not set YouTube thumbnail.");
          var err = new Error(msg);
          err.status = res.status;
          throw err;
        }
        if (!text) return {};
        try {
          return JSON.parse(text);
        } catch (_) {
          return {};
        }
      });
    });
  }

  function isRetryableYouTubeThumbnailError(err) {
    if (err && err.status === 403) return false;
    var msg = err && err.message ? String(err.message) : "";
    return /processing|process|not.?ready|video.?not.?found|notFound|try again|temporar/i.test(msg);
  }

  async function setYouTubeVideoThumbnailWithRetry(accessToken, videoId, thumbnailBlob) {
    var retryDelays = [1500, 3000, 6000, 9000];
    var lastErr = null;
    for (var attempt = 0; attempt <= retryDelays.length; attempt++) {
      try {
        return await setYouTubeVideoThumbnail(accessToken, videoId, thumbnailBlob);
      } catch (err) {
        lastErr = err;
        if (attempt >= retryDelays.length || !isRetryableYouTubeThumbnailError(err)) {
          throw err;
        }
        await waitMs(retryDelays[attempt]);
      }
    }
    throw lastErr || new Error("Could not set YouTube thumbnail.");
  }

  function isCORSOrNetworkError(e) {
    if (!e) return false;
    var msg = (e.message || "") + (e.name || "");
    return e.name === "TypeError" || /Failed to fetch|CORS|Access-Control|network|Load failed|ERR_FAILED/i.test(msg);
  }

  function triggerVideoDownloadsForPostcard(postcard, delayMs) {
    delayMs = delayMs || 400;
    var videos = getPostcardVideoMedia(postcard);
    if (videos.length === 0) return Promise.resolve();
    var links = [];
    mediaGrid.querySelectorAll(".postcard-group").forEach(function (group) {
      if ((group.getAttribute("data-postcard-id") || "") !== (postcard.id || "")) return;
      group.querySelectorAll(".media-link").forEach(function (a) {
        var d = (a.getAttribute("download") || "").toLowerCase();
        if (/\.(mp4|webm|mov)$/.test(d)) links.push(a);
      });
    });
    return triggerDownloadsSequentially(links, delayMs);
  }

  var YOUTUBE_STUDIO_URL = "https://studio.youtube.com";

  function setYouTubeUploadButtonState(button, label) {
    if (!button) return;
    if (activeYouTubeUploadButton && activeYouTubeUploadButton !== button) {
      resetYouTubeUploadButtonState();
    }
    if (!activeYouTubeUploadButton) {
      activeYouTubeUploadButton = button;
      activeYouTubeUploadButtonLabel = button.textContent || "Save to YouTube";
    }
    button.classList.add("btn-uploading");
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.textContent = label || "Uploading…";
  }

  function resetYouTubeUploadButtonState() {
    if (!activeYouTubeUploadButton) return;
    activeYouTubeUploadButton.classList.remove("btn-uploading");
    activeYouTubeUploadButton.removeAttribute("aria-busy");
    activeYouTubeUploadButton.textContent = activeYouTubeUploadButtonLabel || "Save to YouTube";
    var hasVideo = activeYouTubeUploadButton.getAttribute("data-has-video") === "true";
    if (!hasVideo) {
      activeYouTubeUploadButton.disabled = true;
      activeYouTubeUploadButton.setAttribute("aria-disabled", "true");
      activeYouTubeUploadButton.title = "No MP4 video available in this postcard.";
    } else if (isYouTubeUploadBlocked()) {
      activeYouTubeUploadButton.disabled = true;
      activeYouTubeUploadButton.setAttribute("aria-disabled", "true");
      activeYouTubeUploadButton.title = getYouTubeUploadBlockedMessage();
    } else {
      activeYouTubeUploadButton.disabled = false;
      activeYouTubeUploadButton.removeAttribute("aria-disabled");
      activeYouTubeUploadButton.title = "";
    }
    activeYouTubeUploadButton = null;
    activeYouTubeUploadButtonLabel = "";
    applyYouTubeUploadBlockedUi();
  }

  function getStudioUrlForVideo(videoId) {
    if (!videoId) return YOUTUBE_STUDIO_URL;
    return "https://studio.youtube.com/video/" + encodeURIComponent(videoId) + "/edit";
  }

  function openYouTubeStudioPopup(studioUrl) {
    var popup = null;
    try {
      popup = window.open(studioUrl || YOUTUBE_STUDIO_URL, "_blank", "noopener,noreferrer");
    } catch (_) {
      popup = null;
    }
    return !!popup;
  }

  /** Show YouTube modal with message. Only call after upload completes (success or fallback). */
  function openYouTubeUploadModal(opts) {
    opts = opts || {};
    var msg = "";
    if (opts.success && opts.popupBlocked && opts.count != null) {
      msg = "Uploaded " + opts.count + " video(s) to YouTube and published as Public. Your browser blocked the pop-up, so click below to open the uploaded video in YouTube Studio.";
    } else if (opts.success && opts.count != null) {
      msg = "Uploaded " + opts.count + " video(s) to YouTube and published as Public. Open YouTube Studio to edit details.";
    } else if (opts.fallback && opts.count != null) {
      msg = "Upload from this browser was blocked. " + opts.count + " video(s) were downloaded. Open YouTube Studio in a new tab to upload them and set visibility.";
    } else {
      msg = "Open YouTube Studio to manage your videos.";
    }
    if (youtubeModalMessage) youtubeModalMessage.textContent = msg;
    if (youtubeModalOpenStudio) {
      youtubeModalOpenStudio.href = opts.studioUrl || YOUTUBE_STUDIO_URL;
      youtubeModalOpenStudio.textContent = opts.studioUrl ? "Open uploaded video in Studio" : "Open YouTube Studio";
    }
    if (youtubeUploadModalOverlay) youtubeUploadModalOverlay.classList.remove("hidden");
  }

  function closeYouTubeUploadModal() {
    if (youtubeUploadModalOverlay) youtubeUploadModalOverlay.classList.add("hidden");
  }

  /** Upload all postcard videos via multipart (sample-style). Uses GSI accessToken; no gapi/iframe. */
  async function uploadPostcardVideosWithToken(postcard, accessToken, requestCtx) {
    var videos = getPostcardVideoMedia(postcard);
    if (videos.length === 0) {
      setBusy(false);
      resetYouTubeUploadButtonState();
      return;
    }
    mediaError.hidden = true;
    var uploadedVideoIds = [];
    var playlistId = "";
    var playlistWarning = "";
    var metadataWarning = "";
    var thumbnailWarning = "";
    var thumbnailBlob = null;
    try {
      setHeaderStatus("Preparing GetBirds playlist…", false);
      try {
        playlistId = await ensureGetBirdsPlaylist(accessToken);
      } catch (playlistErr) {
        playlistWarning = playlistErr && playlistErr.message ? playlistErr.message : "Could not use the GetBirds playlist.";
      }
      var thumbnailSource = getFinalThumbnailSourceFromPostcard(postcard);
      if (thumbnailSource && thumbnailSource.url) {
        try {
          if (thumbnailSource.isJpg) {
            setHeaderStatus("Preparing final postcard JPG for YouTube thumbnail…", false);
          } else {
            setHeaderStatus("Preparing final postcard image fallback for YouTube thumbnail…", false);
          }
          var thumbnailRes = await fetch(getBirdBuddyMediaProxyUrl(thumbnailSource.url), {
            mode: "cors",
            cache: "no-store",
            credentials: "omit"
          });
          if (!thumbnailRes.ok) throw new Error("Could not fetch postcard thumbnail image (" + thumbnailRes.status + ").");
          var sourceThumbBlob = await thumbnailRes.blob();
          if (!sourceThumbBlob || !sourceThumbBlob.size) throw new Error("Downloaded thumbnail image was empty.");
          thumbnailBlob = await buildYouTubePreviewThumbnailBlob(sourceThumbBlob);
        } catch (thumbErr) {
          thumbnailWarning = thumbErr && thumbErr.message ? thumbErr.message : "Could not prepare postcard thumbnail.";
        }
      }
      for (var i = 0; i < videos.length; i++) {
        var progressMsg = "Uploading video " + (i + 1) + " of " + videos.length + "…";
        setHeaderStatus(progressMsg, false);
        setYouTubeUploadButtonState(requestCtx && requestCtx.button, "Uploading " + (i + 1) + "/" + videos.length + "…");
        var res = await fetch(getBirdBuddyMediaProxyUrl(videos[i].url), { mode: "cors" });
        if (!res.ok) throw new Error("Could not fetch video: " + res.status);
        var blob = await res.blob();
        var snippet = buildYouTubeSnippetFromPostcard(postcard, i, videos.length);
        var tags = snippet.tags && snippet.tags.length ? snippet.tags : ["GetBirds", "BirdBuddy"];
        var metadata = {
          snippet: {
            title: snippet.title || "GetBirds postcard",
            description: snippet.description || "Bird feeder postcard from GetBirds",
            tags: tags,
            categoryId: "22"
          },
          status: {
            privacyStatus: "public",
            selfDeclaredMadeForKids: true
          }
        };
        var uploadResult = await uploadOneVideoMultipart(blob, metadata, accessToken);
        if (uploadResult && uploadResult.id) {
          uploadedVideoIds.push(uploadResult.id);
          if (uploadResult.__usedFallback) {
            try {
              await updateYouTubeVideoMetadata(accessToken, uploadResult.id, metadata);
            } catch (metadataErr) {
              if (!metadataWarning) {
                metadataWarning = metadataErr && metadataErr.message ? metadataErr.message : "Could not apply full YouTube metadata.";
              }
            }
          }
          if (playlistId) {
            try {
              await addVideoToPlaylist(accessToken, playlistId, uploadResult.id);
            } catch (playlistItemErr) {
              if (!playlistWarning) {
                playlistWarning = playlistItemErr && playlistItemErr.message ? playlistItemErr.message : "Could not add one or more videos to the GetBirds playlist.";
              }
            }
          }
          if (thumbnailBlob) {
            try {
              await setYouTubeVideoThumbnailWithRetry(accessToken, uploadResult.id, thumbnailBlob);
            } catch (thumbnailErr) {
              if (!thumbnailWarning) {
                var is403 = thumbnailErr && thumbnailErr.status === 403;
                thumbnailWarning = is403
                  ? "Custom thumbnail was not set (permission or policy). You can set it in YouTube Studio."
                  : (thumbnailErr && thumbnailErr.message ? thumbnailErr.message : "Could not set custom thumbnail on one or more videos.");
              }
            }
          }
        }
      }
      var newestVideoId = uploadedVideoIds.length ? uploadedVideoIds[uploadedVideoIds.length - 1] : "";
      var studioUrl = getStudioUrlForVideo(newestVideoId);
      var successPrefix = "Uploaded " + videos.length + " video(s) and published as Public";
      if (playlistId) {
        successPrefix += " and added to GetBirds playlist";
      } else if (playlistWarning) {
        successPrefix += " (playlist step skipped)";
      }
      if (metadataWarning) {
        successPrefix += " (metadata simplified)";
      }
      if (thumbnailBlob && !thumbnailWarning) {
        successPrefix += " with postcard thumbnail";
      } else if (thumbnailWarning) {
        successPrefix += " (thumbnail step skipped)";
      }
      if (openYouTubeStudioPopup(studioUrl)) {
        setHeaderStatus(successPrefix + " and opened YouTube Studio.", false);
      } else {
        setHeaderStatus(successPrefix + ". Pop-up was blocked — open YouTube Studio manually.", false);
        openYouTubeUploadModal({ success: true, popupBlocked: true, count: videos.length, studioUrl: studioUrl });
      }
    } catch (e) {
      if (isYouTubeUploadLimitError(e)) {
        var until = computeYouTubeUploadBlockedUntilMs(e);
        blockYouTubeUploadsUntil(until, (e && e.message) || youtubeUploadBlockedReason);
        setHeaderStatus(getYouTubeUploadBlockedMessage(), true, { persist: true, youtubeLimit: true });
      } else if (isCORSOrNetworkError(e)) {
        setHeaderStatus("Upload from this browser was blocked. Downloading videos…", false);
        await triggerVideoDownloadsForPostcard(postcard, 350);
        openYouTubeUploadModal({ fallback: true, count: videos.length });
      } else {
        setHeaderStatus(e.message || "YouTube upload failed.", true);
      }
    } finally {
      resetYouTubeUploadButtonState();
      setBusy(false);
    }
  }

  function getTokenClientForYouTube() {
    if (youtubeTokenClient) return youtubeTokenClient;
    if (typeof google === "undefined" || !google.accounts || !google.accounts.oauth2) {
      throw new Error("Google Sign-In not loaded. Reload the page.");
    }
    youtubeTokenClient = google.accounts.oauth2.initTokenClient({
      client_id: CLIENT_ID,
      scope: SCOPE_YOUTUBE,
      callback: function (res) {
        var request = pendingYouTubeUploadRequest;
        pendingYouTubeUploadRequest = null;
        if (res.error) {
          setBusy(false);
          setHeaderStatus(res.error + (res.error_description ? ": " + res.error_description : "") + " Add yourself as a test user in Google Cloud Console if needed.", true);
          resetYouTubeUploadButtonState();
          return;
        }
        localStorage.setItem(STORAGE_KEYS.googleAccessToken, res.access_token);
        if (res.expires_in) localStorage.setItem(STORAGE_KEYS.googleExpiresAt, String(Date.now() + res.expires_in * 1000));
        if (request && request.postcard) {
          uploadPostcardVideosWithToken(request.postcard, res.access_token, request);
        } else {
          setBusy(false);
          resetYouTubeUploadButtonState();
        }
      }
    });
    return youtubeTokenClient;
  }

  function onSaveToYouTube(postcard, button) {
    if (isYouTubeUploadBlocked()) {
      applyYouTubeUploadBlockedUi();
      return;
    }
    var videos = getPostcardVideoMedia(postcard);
    if (videos.length === 0) {
      if (button) {
        button.disabled = true;
        button.setAttribute("aria-disabled", "true");
        button.title = "No MP4 video available in this postcard.";
      }
      setHeaderStatus("No MP4 video in this postcard. Save to collection still works.", true);
      return;
    }
    if (busy) return;
    setBusy(true);
    mediaError.hidden = true;
    setYouTubeUploadButtonState(button, "Authorizing…");
    setHeaderStatus("Requesting YouTube permission…", false);
    pendingYouTubeUploadRequest = { postcard: postcard, button: button };
    try {
      getTokenClientForYouTube().requestAccessToken();
    } catch (e) {
      setBusy(false);
      pendingYouTubeUploadRequest = null;
      resetYouTubeUploadButtonState();
      setHeaderStatus(e.message || "Could not request YouTube access.", true);
    }
  }

  async function onDownloadAllClick() {
    if (busy) return;
    setBusy(true);
    try {
      if (hasMore) {
        mediaStatus.hidden = false;
        mediaStatus.textContent = "Loading all postcards…";
        await loadAllPostcards();
      }
      var c = getDisplayedCounts();
      if (c.media === 0) return;
      mediaStatus.textContent = "Downloading…";
      mediaStatus.hidden = false;
      if (footerStats) footerStats.textContent = "Downloading " + c.media + " file(s)…";
      await triggerAllDownloads();
      mediaStatus.hidden = true;
      if (footerStats) footerStats.textContent = "All media download started — check your browser downloads.";
      setHeaderStatus("Download started for all media. Check your browser downloads.", false);
      setTimeout(updateFooter, 2000);
    } catch (e) {
      mediaStatus.hidden = true;
      setHeaderStatus(e.message || "Download failed", true);
      if (footerStats) footerStats.textContent = "Download failed.";
      setTimeout(updateFooter, 2000);
    } finally {
      setBusy(false);
    }
  }

  /** First image in postcard (for prev/next panel); null if none. */
  function getFirstPostcardImageUrl(postcard) {
    if (!postcard || !postcard.medias) return null;
    for (var i = 0; i < postcard.medias.length; i++) {
      if (!postcard.medias[i].isVideo) return postcard.medias[i].url || null;
    }
    return null;
  }

  /** Build segments for a single postcard (all media: images + videos). Each segment: { url, isVideo, species, createdAt, firstImageUrl, downloadName, postcardId }. */
  function getPostcardSegments(postcard) {
    if (!postcard || !postcard.medias || postcard.medias.length === 0) return [];
    var species = formatSpeciesLabel(postcard);
    var createdAt = postcard.createdAt ? formatPostcardDate(postcard.createdAt) : "—";
    var postcardId = postcard.id || "";
    var firstImageUrl = getFirstPostcardImageUrl(postcard);
    var segments = [];
    postcard.medias.forEach(function (m) {
      var panelImageUrl = m.isVideo ? (m.thumb || firstImageUrl) : m.url;
      segments.push({
        url: m.url,
        isVideo: !!m.isVideo,
        species: species,
        createdAt: createdAt,
        firstImageUrl: panelImageUrl || firstImageUrl,
        downloadName: filenameFromMedia(m),
        postcardId: postcardId
      });
    });
    return segments;
  }

  /** Collect visible MP4s from the grid (honors species/camera filter). Returns same segment shape as getPostcardSegments (video-only). */
  function getVisibleVideoSegments() {
    if (!mediaGrid) return [];
    var segments = [];
    mediaGrid.querySelectorAll(".postcard-group").forEach(function (group) {
      if (group.style.display === "none") return;
      var postcardId = group.getAttribute("data-postcard-id") || "";
      var species = (group.getAttribute("data-species") || "").trim() || "—";
      var postcard = allPostcards.filter(function (p) { return (p.id || "") === postcardId; })[0];
      var createdAt = postcard && postcard.createdAt ? formatPostcardDate(postcard.createdAt) : "—";
      var firstImageUrl = getFirstPostcardImageUrl(postcard);
      var links = group.querySelectorAll(".media-link");
      for (var i = 0; i < links.length; i++) {
        var a = links[i];
        var name = (a.getAttribute("data-download-name") || a.getAttribute("download") || "").toLowerCase();
        if (!/\.(mp4|webm|mov)$/.test(name)) continue;
        var url = a.getAttribute("data-media-url") || a.href || "";
        if (!url) continue;
        segments.push({
          url: url,
          isVideo: true,
          postcardId: postcardId,
          species: species,
          createdAt: createdAt,
          firstImageUrl: firstImageUrl,
          downloadName: a.getAttribute("data-download-name") || a.getAttribute("download") || ""
        });
      }
    });
    return segments;
  }

  var getbirdsTvSegments = [];
  var getbirdsTvIndex = 0;
  var getbirdsTvKeyHandler = null;
  var getbirdsTvEscapeHandler = null;

  function playGetBirdsTvSegmentAtIndex(index) {
    if (getbirdsTvSegments.length === 0) return;
    var n = getbirdsTvSegments.length;
    getbirdsTvIndex = ((index % n) + n) % n;
    var segment = getbirdsTvSegments[getbirdsTvIndex];
    var isVideo = segment.isVideo !== false;
    if (getbirdsTvVideo) {
      if (isVideo) {
        getbirdsTvVideo.src = segment.url;
        getbirdsTvVideo.load();
        getbirdsTvVideo.classList.remove("hidden");
        getbirdsTvVideo.muted = false;
        getbirdsTvVideo.play().catch(function () {});
      } else {
        getbirdsTvVideo.pause();
        getbirdsTvVideo.removeAttribute("src");
        getbirdsTvVideo.load();
        getbirdsTvVideo.classList.add("hidden");
      }
    }
    if (getbirdsTvImage) {
      if (!isVideo) {
        getbirdsTvImage.src = segment.url;
        getbirdsTvImage.alt = segment.species || "Postcard";
        getbirdsTvImage.classList.remove("hidden");
      } else {
        getbirdsTvImage.removeAttribute("src");
        getbirdsTvImage.classList.add("hidden");
      }
    }
    updateGetBirdsTvOverlays(segment);
    updateGetBirdsTvDownloadButton(segment);
    updateGetBirdsTvUnmuteButton();
    if (getbirdsTvUnmute) getbirdsTvUnmute.style.display = isVideo ? "" : "none";
  }

  function updateGetBirdsTvOverlays(segment) {
    if (getbirdsTvLowerThird) {
      getbirdsTvLowerThird.textContent = segment ? segment.species + " · " + segment.createdAt : "";
      getbirdsTvLowerThird.style.display = segment ? "block" : "none";
      getbirdsTvLowerThird.setAttribute("aria-hidden", segment ? "false" : "true");
    }
    if (getbirdsTvSnipe) {
      var next = getbirdsTvSegments[getbirdsTvIndex + 1] || getbirdsTvSegments[0];
      getbirdsTvSnipe.textContent = next ? "Up next: " + next.species : "";
      getbirdsTvSnipe.style.display = next ? "block" : "none";
      getbirdsTvSnipe.setAttribute("aria-hidden", next ? "false" : "true");
    }
    updateGetBirdsTvPrevNextPanels();
  }

  function updateGetBirdsTvPrevNextPanels() {
    var n = getbirdsTvSegments.length;
    var showPanels = n >= 2;
    var prevSeg = showPanels ? getbirdsTvSegments[((getbirdsTvIndex - 1) % n + n) % n] : null;
    var nextSeg = showPanels ? getbirdsTvSegments[(getbirdsTvIndex + 1) % n] : null;
    function setPanel(panel, seg) {
      if (!panel) return;
      panel.style.display = seg ? "flex" : "none";
      panel.innerHTML = "";
      if (seg && seg.firstImageUrl) {
        var img = document.createElement("img");
        img.alt = seg.species || "Previous postcard";
        img.src = seg.firstImageUrl;
        panel.appendChild(img);
      }
    }
    setPanel(getbirdsTvPrevPanel, prevSeg);
    setPanel(getbirdsTvNextPanel, nextSeg);
    if (getbirdsTvPrevPanel) getbirdsTvPrevPanel.setAttribute("aria-hidden", showPanels ? "false" : "true");
    if (getbirdsTvNextPanel) getbirdsTvNextPanel.setAttribute("aria-hidden", showPanels ? "false" : "true");
  }

  function playNextGetBirdsTvSegment() {
    playGetBirdsTvSegmentAtIndex(getbirdsTvIndex);
  }

  function openGetBirdsTVWithSegments(segments, startIndex) {
    if (!segments || segments.length === 0) return;
    if (!getbirdsTvOverlay) return;
    getbirdsTvSegments = segments;
    getbirdsTvIndex = typeof startIndex === "number" ? startIndex : 0;
    getbirdsTvOverlay.classList.remove("hidden");
    if (!getbirdsTvEscapeHandler) {
      getbirdsTvEscapeHandler = function (e) {
        if (e.key !== "Escape") return;
        if (!getbirdsTvOverlay || getbirdsTvOverlay.classList.contains("hidden")) return;
        closeGetBirdsTV();
      };
      document.addEventListener("keydown", getbirdsTvEscapeHandler);
    }
    if (getbirdsTvVideo) {
      getbirdsTvVideo.muted = false;
      getbirdsTvVideo.onended = function () {
        if (getbirdsTvSegments.length === 0) return;
        var seg = getbirdsTvSegments[getbirdsTvIndex];
        if (seg && seg.isVideo !== false) {
          getbirdsTvIndex = (getbirdsTvIndex + 1) % getbirdsTvSegments.length;
          playNextGetBirdsTvSegment();
        }
      };
    }
    playNextGetBirdsTvSegment();
    if (getbirdsTvSegments.length >= 2 && !getbirdsTvKeyHandler) {
      getbirdsTvKeyHandler = function (e) {
        if (!getbirdsTvOverlay || getbirdsTvOverlay.classList.contains("hidden")) return;
        if (getbirdsTvSegments.length < 2) return;
        if (e.key === "ArrowLeft") {
          e.preventDefault();
          playGetBirdsTvSegmentAtIndex(getbirdsTvIndex - 1);
        } else if (e.key === "ArrowRight") {
          e.preventDefault();
          playGetBirdsTvSegmentAtIndex(getbirdsTvIndex + 1);
        }
      };
      document.addEventListener("keydown", getbirdsTvKeyHandler);
    }
  }

  function openGetBirdsTV() {
    var segments = getVisibleVideoSegments();
    if (segments.length === 0) {
      setHeaderStatus("No visible MP4s. Add postcards or change the filter.", true);
      return;
    }
    openGetBirdsTVWithSegments(segments, 0);
  }

  function updateGetBirdsTvUnmuteButton() {
    if (!getbirdsTvUnmute || !getbirdsTvVideo) return;
    var muted = getbirdsTvVideo.muted;
    getbirdsTvUnmute.textContent = muted ? "🔇" : "🔊";
    getbirdsTvUnmute.classList.toggle("muted", muted);
    getbirdsTvUnmute.setAttribute("aria-label", muted ? "Unmute" : "Mute");
  }

  function updateGetBirdsTvDownloadButton(segment) {
    if (!getbirdsTvDownload) return;
    if (!segment || !segment.url) {
      getbirdsTvDownload.style.display = "none";
      getbirdsTvDownload.removeAttribute("data-download-url");
      getbirdsTvDownload.removeAttribute("data-download-name");
      return;
    }
    getbirdsTvDownload.style.display = "";
    getbirdsTvDownload.setAttribute("data-download-url", segment.url);
    getbirdsTvDownload.setAttribute("data-download-name", segment.downloadName || "");
  }

  function closeGetBirdsTV() {
    if (getbirdsTvKeyHandler) {
      document.removeEventListener("keydown", getbirdsTvKeyHandler);
      getbirdsTvKeyHandler = null;
    }
    if (getbirdsTvEscapeHandler) {
      document.removeEventListener("keydown", getbirdsTvEscapeHandler);
      getbirdsTvEscapeHandler = null;
    }
    if (getbirdsTvOverlay) getbirdsTvOverlay.classList.add("hidden");
    if (getbirdsTvVideo) {
      getbirdsTvVideo.pause();
      getbirdsTvVideo.removeAttribute("src");
      getbirdsTvVideo.load();
      getbirdsTvVideo.onended = null;
      getbirdsTvVideo.classList.remove("hidden");
    }
    if (getbirdsTvImage) {
      getbirdsTvImage.removeAttribute("src");
      getbirdsTvImage.classList.add("hidden");
    }
    if (getbirdsTvDownload) {
      getbirdsTvDownload.style.display = "none";
      getbirdsTvDownload.removeAttribute("data-download-url");
      getbirdsTvDownload.removeAttribute("data-download-name");
    }
    if (getbirdsTvUnmute) getbirdsTvUnmute.style.display = "";
    getbirdsTvSegments = [];
  }

  function getTokenClient() {
    if (tokenClient) return tokenClient;
    if (typeof google === "undefined" || !google.accounts || !google.accounts.oauth2) {
      throw new Error("Google Sign-In script not loaded yet. Try again in a moment.");
    }
    tokenClient = google.accounts.oauth2.initTokenClient({
      client_id: CLIENT_ID,
      scope: SCOPE,
      callback: function (res) {
        if (res.error) {
          setBusy(false);
          showLogin();
          return;
        }
        onGoogleTokenReceived(res.access_token, res.expires_in || 3600);
      }
    });
    return tokenClient;
  }

  function onGoogleTokenReceived(accessToken, expiresIn) {
    var expiresAt = Date.now() + expiresIn * 1000;
    localStorage.setItem(STORAGE_KEYS.googleAccessToken, accessToken);
    localStorage.setItem(STORAGE_KEYS.googleExpiresAt, String(expiresAt));
    fetch(USERINFO_URL, { headers: { Authorization: "Bearer " + accessToken, Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (profile) {
        if (!profile.sub) throw new Error(profile.error || "Profile failed");
        localStorage.setItem(STORAGE_KEYS.picture, profile.picture || "");
        localStorage.setItem(STORAGE_KEYS.name, profile.name || "");
        showLoggedIn({ picture: profile.picture, name: profile.name });
      })
      .catch(function () { showLogin(); })
      .finally(function () { setBusy(false); });
  }

  /* Google token treated as expired this many ms before expiry (force re-sign-in). */
  var GOOGLE_TOKEN_BUFFER_MS = 60 * 1000;

  async function restoreSession() {
    var token = localStorage.getItem(STORAGE_KEYS.googleAccessToken);
    var expiresAt = parseInt(localStorage.getItem(STORAGE_KEYS.googleExpiresAt) || "0", 10);
    if (!token || !expiresAt || Date.now() >= expiresAt - GOOGLE_TOKEN_BUFFER_MS) return false;
    try {
      var r = await fetch(USERINFO_URL, { headers: { Authorization: "Bearer " + token, Accept: "application/json" } });
      var profile = await r.json();
      if (!r.ok || !profile.sub) return false;
      localStorage.setItem(STORAGE_KEYS.picture, profile.picture || "");
      localStorage.setItem(STORAGE_KEYS.name, profile.name || "");
      showLoggedIn({ picture: profile.picture, name: profile.name });
      return true;
    } catch (_) { return false; }
  }

  function clearSession() {
    [
      STORAGE_KEYS.googleAccessToken,
      STORAGE_KEYS.googleExpiresAt,
      STORAGE_KEYS.picture,
      STORAGE_KEYS.name,
      STORAGE_KEYS.bbAccessToken,
      STORAGE_KEYS.bbExpiresAt,
      STORAGE_KEYS.bbRefreshToken
    ].forEach(function (key) { localStorage.removeItem(key); });
  }

  async function signOut() {
    var token = localStorage.getItem(STORAGE_KEYS.googleAccessToken);
    if (token) {
      try {
        await fetch(REVOKE_URL, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams({ token: token }).toString() });
      } catch (_) {}
    }
    clearSession();
    resetLoggedInUI();
    showLogin();
  }

  function startLogin() {
    setBusy(true);
    try {
      getTokenClient().requestAccessToken();
    } catch (_) {
      setBusy(false);
      showLogin();
    }
  }

  loadMoreBtn.addEventListener("click", function () {
    if (!hasMore || busy) return;
    mediaStatus.hidden = false;
    mediaStatus.textContent = "Loading more…";
    loadMoreBtn.hidden = true;
    loadAllBtn.hidden = true;
    fetchBirdBuddyFeed();
  });

  loadAllBtn.addEventListener("click", loadAllPostcards);

  if (downloadAllBtn) {
    downloadAllBtn.addEventListener("click", function () { onDownloadAllClick(); });
  }
  if (downloadAllMediaBtn) {
    downloadAllMediaBtn.addEventListener("click", function () { onDownloadAllClick(); });
  }

  loginBtn.addEventListener("click", function () {
    if (busy) return;
    startLogin();
  });

  signoutBtn.addEventListener("click", function () {
    if (busy) return;
    setBusy(true);
    signOut().finally(function () { setBusy(false); });
  });


  if (youtubeModalClose) {
    youtubeModalClose.addEventListener("click", closeYouTubeUploadModal);
  }
  if (getbirdsTvBtn) {
    getbirdsTvBtn.addEventListener("click", function () { openGetBirdsTV(); });
  }
  if (getbirdsTvClose) {
    getbirdsTvClose.addEventListener("click", closeGetBirdsTV);
  }
  if (getbirdsTvPrevPanel) {
    getbirdsTvPrevPanel.addEventListener("click", function () {
      if (getbirdsTvSegments.length >= 2) playGetBirdsTvSegmentAtIndex(getbirdsTvIndex - 1);
    });
    getbirdsTvPrevPanel.addEventListener("keydown", function (e) {
      if ((e.key === "Enter" || e.key === " ") && getbirdsTvSegments.length >= 2) { e.preventDefault(); playGetBirdsTvSegmentAtIndex(getbirdsTvIndex - 1); }
    });
  }
  if (getbirdsTvNextPanel) {
    getbirdsTvNextPanel.addEventListener("click", function () {
      if (getbirdsTvSegments.length >= 2) playGetBirdsTvSegmentAtIndex(getbirdsTvIndex + 1);
    });
    getbirdsTvNextPanel.addEventListener("keydown", function (e) {
      if ((e.key === "Enter" || e.key === " ") && getbirdsTvSegments.length >= 2) { e.preventDefault(); playGetBirdsTvSegmentAtIndex(getbirdsTvIndex + 1); }
    });
  }
  if (getbirdsTvUnmute && getbirdsTvVideo) {
    getbirdsTvUnmute.addEventListener("click", function () {
      getbirdsTvVideo.muted = !getbirdsTvVideo.muted;
      updateGetBirdsTvUnmuteButton();
    });
  }
  if (getbirdsTvDownload) {
    getbirdsTvDownload.addEventListener("click", function () {
      var url = getbirdsTvDownload.getAttribute("data-download-url");
      var name = getbirdsTvDownload.getAttribute("data-download-name") || "";
      if (!url) return;
      if (!name && url) name = "file_" + md5HashString(url) + detectDownloadExtension(url, /\.mp4|\.mov|\.webm/i.test(url));
      downloadMediaAsFile(url, name).catch(function (err) {
        setHeaderStatus((err && err.message) || "Download failed.", true);
      });
    });
  }
  if (youtubeUploadModalOverlay) {
    youtubeUploadModalOverlay.addEventListener("click", function (e) {
      if (e.target === youtubeUploadModalOverlay) closeYouTubeUploadModal();
    });
  }

  try {
    var authErrorParam = new URLSearchParams(window.location.search || "").get("frameio_auth_error");
    if (authErrorParam === "1") {
      fetch(GBIRDS_FRAMEIO_BASE + "?api=frameio-status", { credentials: "same-origin", cache: "no-store" })
        .then(function (r) { return r.json().catch(function(){ return {}; }); })
        .then(function () {
          setHeaderStatus("Adobe IMS authorization/profile access failed. Sign out of Adobe, disable automatic profile selection if needed, sign in with the profile that has access to Project_FIREBIRD, then retry Send to Adobe. Open https://account.adobe.com/ to manage profiles.", true, {persist:true});
        });
    }
    var connectedParam = new URLSearchParams(window.location.search || "").get("frameio_connected");
    if (connectedParam === "1") {
      setHeaderStatus("Adobe IMS connected. Frame.io is ready.", false);
    }
  } catch (_) {}

  syncGraphqlOverrideFromUrl();
  restoreYouTubeUploadBlockedState();
  setBusy(true);
  beginFrameioUsage().catch(function () {}).then(function () {
    var hasFrameioResume = false;
    try { hasFrameioResume = new URLSearchParams(window.location.search || '').get('frameio_resume') === '1'; } catch (_) {}
    if (hasFrameioResume) return resumeFrameioSendAfterIms().then(function () { return true; });
    return restoreSession();
  }).then(function (ok) {
    if (!ok) { showLogin(); return false; }
    return true;
  }).catch(function (e) {
    if (e && e.message) {
      setHeaderStatus(e.message, true, { persist: true });
      try { if (new URLSearchParams(window.location.search || "").get("frameio_resume") === "1") openFrameioErrorTab(); } catch (_) {}
    } else showLogin();
  }).finally(function () { setBusy(false); });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {});
  }
})();
  </script>
</body>
</html>
