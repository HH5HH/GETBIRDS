<?php
/*
 * Optional private FireBird configuration.
 *
 * For Hostinger, upload firebird-config.php alongside this file (or update the
 * require path below if you keep it outside public_html). The config file is
 * intentionally separate from Git so Client Secrets are never committed.
 * This is the SAME config file gbirds.php reads — add the PBIRDS_* constants
 * below to it, you don't need a second config file.
 */
$firebirdConfigPath = __DIR__ . '/firebird-config.php';
if (is_file($firebirdConfigPath)) {
    require_once $firebirdConfigPath;
}

/*
 * PutBirds / FireBird "publish" gateway — sibling to gbirds.php.
 *
 * gbirds.php gets BirdBuddy postcards INTO Frame.io/Project_FIREBIRD.
 * pbirds.php PUTS the finished FLOCKED.png OUT to the world:
 *
 *   A. Adobe login          — IMS OAuth Web App, same provider/flow shape as
 *                              gbirds.php's Frame.io login, re-pointed at the
 *                              Adobe Express API - Firefly Services credential.
 *   B. Post to Instagram     — Adobe Express does NOT publish to social
 *                              platforms; that's Meta's Instagram Graph API,
 *                              a separate app/OAuth entirely. Implemented for
 *                              real below (Content Publishing API, two-step
 *                              container + publish).
 *   C. Other platforms       — same shape as Instagram: each platform is its
 *                              own OAuth + its own publish endpoint. Stubbed
 *                              router entries left ready for X/Twitter,
 *                              Facebook Page, Pinterest, etc. — none built
 *                              yet since each needs its own app registration.
 */

function pbirds_json_response($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function pbirds_cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && preg_match('/^https?:\/\//', $origin)) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = preg_replace('/:\d+$/', '', $host);
        if ($originHost && strcasecmp($originHost, $requestHost) === 0) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
}

function pbirds_require_curl(): void {
    if (!function_exists('curl_init')) {
        pbirds_json_response(['error' => 'PHP cURL extension is required.'], 500);
    }
}

function pbirds_config_value(string $name, string $default = ''): string {
    if (defined($name)) {
        $value = constant($name);
        if ($value !== null && $value !== '') return trim((string)$value);
    }
    $env = getenv($name);
    if ($env !== false && trim((string)$env) !== '') return trim((string)$env);
    return $default;
}

// Bump whenever pbirds.php is redeployed, mirrors gbirds.php's FIREBIRD_BUILD_VERSION.
if (!defined('PBIRDS_BUILD_VERSION')) {
    define('PBIRDS_BUILD_VERSION', 'pbirds-2026-01-01-initial');
}

function pbirds_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Same session cookie name as gbirds.php on purpose — pbirds.php and
        // gbirds.php are siblings on the same origin and share one FireBird
        // session. Keys below are namespaced (pbirds_express_*, pbirds_ig_*)
        // so they can never collide with gbirds.php's frameio_* keys.
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

function pbirds_http(string $method, string $url, ?array $formBody, ?array $jsonBody, array $extraHeaders = []): array {
    pbirds_require_curl();
    $headers = ['Accept: application/json'];
    foreach ($extraHeaders as $h) { $headers[] = $h; }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
    } elseif ($formBody !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts[CURLOPT_POSTFIELDS] = http_build_query($formBody);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Request failed: ' . $err); }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) { $decoded = ['raw' => trim(substr($raw, 0, 2000))]; }
    return [$status, $decoded];
}

// =============================================================================
// A. ADOBE LOGIN — Adobe Express API (Firefly Services), IMS OAuth Web App.
//    Same IMS provider/flow shape as gbirds.php's Frame.io login (authorize/v2,
//    token/v3, Basic client auth, CSRF state in session). Re-pointed at the
//    Express credential's client id/secret/redirect URI.
// =============================================================================

function pbirds_express_config(): array {
    return [
        'client_id'     => pbirds_config_value('PBIRDS_EXPRESS_CLIENT_ID'),
        'client_secret' => pbirds_config_value('PBIRDS_EXPRESS_CLIENT_SECRET'),
        'redirect_uri'  => pbirds_config_value('PBIRDS_EXPRESS_REDIRECT_URI', 'https://hh5hh.com/pbirds.php?api=express-callback'),
        // TODO: set this from the exact scopes your Express API - Firefly
        // Services credential grants in Adobe Developer Console. Left
        // unguessed on purpose — do not fabricate a scope string here.
        'scopes'        => pbirds_config_value('PBIRDS_EXPRESS_SCOPES', 'openid'),
        'ims_base'      => 'https://ims-na1.adobelogin.com',
        // Verified against Adobe's own OpenAPI spec (AdobeDocs/ffs-express-api).
        'api_base'      => 'https://express-api.adobe.io',
        // Express API - Firefly Services operates on an EXISTING, tagged
        // Express template/document (identified by its Creative Cloud file
        // id) — it does not accept an arbitrary raster file. One-time setup:
        //   1. In Express, build a template with an image placeholder and a
        //      text placeholder, then use Express's "tag" feature to name
        //      them (these names are what tagName below must match).
        //   2. Hit GET ?api=express-templates once you're connected — it
        //      lists your tagged documents and their Creative Cloud file ids
        //      via GET /beta/tagged-documents.
        //   3. Set the three constants below from that template.
        'template_cc_file_id' => pbirds_config_value('PBIRDS_EXPRESS_TEMPLATE_FILE_ID'),
        'image_tag_name'       => pbirds_config_value('PBIRDS_EXPRESS_IMAGE_TAG', 'photo'),
        'text_tag_name'        => pbirds_config_value('PBIRDS_EXPRESS_TEXT_TAG', 'caption'),
    ];
}

/** Every Express API call needs BOTH the API key header and the IMS bearer token. */
function pbirds_express_auth_headers(): array {
    pbirds_session_start();
    if (!pbirds_express_token_is_fresh()) {
        throw new RuntimeException('Adobe authorization is required.');
    }
    $cfg = pbirds_express_config();
    return [
        'X-API-KEY: ' . $cfg['client_id'],
        'Authorization: Bearer ' . $_SESSION['pbirds_express_access_token'],
    ];
}

function pbirds_express_token_is_fresh(): bool {
    pbirds_session_start();
    $exp = (int)($_SESSION['pbirds_express_expires_at'] ?? 0);
    return !empty($_SESSION['pbirds_express_access_token']) && $exp > (time() + 60);
}

function pbirds_express_store_tokens(array $tokens): void {
    pbirds_session_start();
    $_SESSION['pbirds_express_access_token'] = (string)$tokens['access_token'];
    $_SESSION['pbirds_express_refresh_token'] = (string)($tokens['refresh_token'] ?? ($_SESSION['pbirds_express_refresh_token'] ?? ''));
    $_SESSION['pbirds_express_expires_at'] = time() + max(60, ((int)($tokens['expires_in'] ?? 3600)) - 60);
}

function pbirds_express_authorize_url(): string {
    $cfg = pbirds_express_config();
    if ($cfg['client_id'] === '') throw new RuntimeException('Adobe Express Client ID is not configured on the server.');
    pbirds_session_start();
    $state = bin2hex(random_bytes(24));
    $_SESSION['pbirds_express_oauth_state'] = $state;
    $scope = preg_replace('/\s*,\s*/', ',', preg_replace('/\s+/', ',', trim($cfg['scopes'])));
    $query = http_build_query([
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'scope' => $scope,
        'response_type' => 'code',
        'response_mode' => 'query',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
    return $cfg['ims_base'] . '/ims/authorize/v2?' . $query;
}

function pbirds_express_exchange_code(string $code): array {
    $cfg = pbirds_express_config();
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') throw new RuntimeException('Adobe Express OAuth credentials are not configured on the server.');
    pbirds_require_curl();
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

function pbirds_express_login(): void {
    try { header('Location: ' . pbirds_express_authorize_url(), true, 302); exit; }
    catch (Throwable $e) { pbirds_json_response(['ok'=>false,'error'=>$e->getMessage()],500); }
}

function pbirds_render_popup_result(string $type, bool $ok, string $error, string $redirectQuery): void {
    $appBase = 'https://hh5hh.com/pbirds.php';
    $redirect = $appBase . '?' . $redirectQuery;
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $payload = json_encode(['type' => $type, 'ok' => $ok, 'error' => $error], $jsonFlags);
    $redirectJs = json_encode($redirect, $jsonFlags);
    $origin = json_encode('https://hh5hh.com', $jsonFlags);
    $msg = $ok ? 'Connected — finishing up…' : ('Sign-in failed' . ($error !== '' ? ': ' . htmlspecialchars($error, ENT_QUOTES) : '.'));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Connecting…</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f5f1f8;color:#211b26;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}'
        . '.card{max-width:420px}button{margin-top:16px;padding:10px 18px;border-radius:8px;border:0;background:#6f3c8f;color:#fff;font-size:14px;cursor:pointer}</style></head>'
        . '<body><div class="card"><p>' . $msg . '</p>'
        . '<button type="button" onclick="__done()">Close</button></div>'
        . '<script>(function(){'
        . 'var payload=' . $payload . ';var origin=' . $origin . ';var redirect=' . $redirectJs . ';'
        . 'var hasOpener=false;try{hasOpener=!!(window.opener&&!window.opener.closed);}catch(e){hasOpener=!!window.opener;}'
        . 'if(hasOpener){try{window.opener.postMessage(payload,origin);}catch(e){}'
        . 'window.__done=function(){try{window.close();}catch(e){}};'
        . 'if(!payload.ok){setTimeout(function(){try{window.close();}catch(e){}},1500);}'
        . '}else{'
        . 'window.__done=function(){window.location.replace(redirect);};'
        . 'window.location.replace(redirect);'
        . '}'
        . '})();</script></body></html>';
    exit;
}

function pbirds_express_callback(): void {
    pbirds_session_start();
    if (!empty($_GET['error'])) {
        $error = trim((string)($_GET['error'] ?? 'authorization_failed'));
        $description = trim((string)($_GET['error_description'] ?? 'Adobe authorization was cancelled or denied.'));
        unset($_SESSION['pbirds_express_oauth_state']);
        pbirds_render_popup_result('pbirds-express-auth', false, $error . ($description ? ': ' . $description : ''), 'express_auth_error=1');
    }
    $state = (string)($_GET['state'] ?? '');
    $expectedState = (string)($_SESSION['pbirds_express_oauth_state'] ?? '');
    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        unset($_SESSION['pbirds_express_oauth_state']);
        pbirds_render_popup_result('pbirds-express-auth', false, 'Invalid Adobe OAuth state.', 'express_auth_error=1');
    }
    unset($_SESSION['pbirds_express_oauth_state']);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        pbirds_render_popup_result('pbirds-express-auth', false, 'Adobe IMS returned no authorization code.', 'express_auth_error=1');
    }
    try {
        $tokens = pbirds_express_exchange_code($code);
        pbirds_express_store_tokens($tokens);
    } catch (Throwable $e) {
        pbirds_render_popup_result('pbirds-express-auth', false, $e->getMessage(), 'express_auth_error=1');
    }
    pbirds_render_popup_result('pbirds-express-auth', true, '', 'express_connected=1');
}

function pbirds_express_status(): void {
    pbirds_session_start();
    pbirds_json_response(['ok'=>true,'connected'=>pbirds_express_token_is_fresh()]);
}

function pbirds_express_logout(): void {
    pbirds_session_start();
    unset($_SESSION['pbirds_express_access_token'], $_SESSION['pbirds_express_refresh_token'], $_SESSION['pbirds_express_expires_at'], $_SESSION['pbirds_express_oauth_state']);
    pbirds_json_response(['ok'=>true]);
}

/**
 * Lists your Adobe Express tagged documents/templates — use this once to
 * find the Creative Cloud file id and tag names for PBIRDS_EXPRESS_TEMPLATE_FILE_ID
 * / _IMAGE_TAG / _TEXT_TAG. Verified against Adobe's ffs-express-api OpenAPI
 * spec: GET /beta/tagged-documents.
 */
function pbirds_express_list_templates(): void {
    try {
        $cfg = pbirds_express_config();
        [$status, $data] = pbirds_http('GET', $cfg['api_base'] . '/beta/tagged-documents', null, null, pbirds_express_auth_headers());
        pbirds_json_response(['ok' => $status >= 200 && $status < 300, 'httpStatus' => $status, 'data' => $data]);
    } catch (Throwable $e) {
        pbirds_json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

/**
 * POST /beta/create-variation — drops FLOCKED.png and a caption into the
 * tagged Express template configured above and renders a composited image
 * (e.g. a branded Instagram-post frame around the photo). Async: returns a
 * jobId/statusUrl; poll pbirds_express_job_status() until it completes, then
 * use the resulting rendition URL as the imageUrl for ig-publish.
 */
function pbirds_express_prepare_creative(): void {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $imageUrl = trim((string)($input['imageUrl'] ?? ''));
    $caption = (string)($input['caption'] ?? '');
    if ($imageUrl === '' || !preg_match('/^https:\/\//i', $imageUrl)) {
        pbirds_json_response(['ok'=>false,'error'=>'A public https imageUrl is required.'], 400);
    }

    $cfg = pbirds_express_config();
    if ($cfg['template_cc_file_id'] === '') {
        pbirds_json_response(['ok'=>false,'error'=>'PBIRDS_EXPRESS_TEMPLATE_FILE_ID is not configured. Call ?api=express-templates first to find it.'], 400);
    }

    try {
        $headers = pbirds_express_auth_headers();
        $body = [
            'templateOrDocument' => [ 'creativeCloudFileId' => $cfg['template_cc_file_id'] ],
            'input' => [
                'mappings' => [
                    'imageMappings' => [
                        [ 'tagName' => $cfg['image_tag_name'], 'source' => [ 'url' => $imageUrl ] ],
                    ],
                    'textMappings' => $caption !== '' ? [
                        [ 'tagName' => $cfg['text_tag_name'], 'text' => $caption ],
                    ] : [],
                ],
            ],
            'outputs' => [
                [ 'type' => 'image', 'mediaType' => 'image/png', 'size' => 2048 ],
            ],
        ];
        [$status, $data] = pbirds_http('POST', $cfg['api_base'] . '/beta/create-variation', null, $body, $headers);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($data['message'] ?? ('Express create-variation failed (' . $status . ')'));
        }
        pbirds_json_response(['ok'=>true,'jobId'=>$data['jobId'] ?? null,'statusUrl'=>$data['statusUrl'] ?? null,'raw'=>$data]);
    } catch (Throwable $e) {
        pbirds_json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

/** GET /status/{jobId} — poll until an Express create-variation/export job finishes. */
function pbirds_express_job_status(): void {
    $jobId = trim((string)($_GET['jobId'] ?? ''));
    if ($jobId === '') pbirds_json_response(['ok'=>false,'error'=>'jobId is required.'], 400);
    try {
        $cfg = pbirds_express_config();
        $headers = pbirds_express_auth_headers();
        [$status, $data] = pbirds_http('GET', $cfg['api_base'] . '/status/' . rawurlencode($jobId), null, null, $headers);
        pbirds_json_response(['ok' => $status >= 200 && $status < 300, 'httpStatus' => $status, 'data' => $data]);
    } catch (Throwable $e) {
        pbirds_json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

// =============================================================================
// B. POST TO INSTAGRAM — Meta Instagram Graph API (Content Publishing API).
//    Separate app registration (developers.facebook.com), separate OAuth,
//    separate credentials. Two-step publish: create a media container from a
//    public image URL, then publish that container.
// =============================================================================

function pbirds_ig_config(): array {
    return [
        'app_id'       => pbirds_config_value('PBIRDS_IG_APP_ID'),
        'app_secret'   => pbirds_config_value('PBIRDS_IG_APP_SECRET'),
        'redirect_uri' => pbirds_config_value('PBIRDS_IG_REDIRECT_URI', 'https://hh5hh.com/pbirds.php?api=ig-callback'),
        // instagram_content_publish requires a Business/Creator IG account
        // linked to a Facebook Page, and Meta app review for production use.
        'scopes'       => pbirds_config_value('PBIRDS_IG_SCOPES', 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement'),
        'graph_base'   => 'https://graph.facebook.com/v21.0',
        'oauth_base'   => 'https://www.facebook.com/v21.0/dialog/oauth',
        // Optional: if you already know the IG Business Account ID, set it
        // here to skip the Pages/IG-account discovery round trip.
        'ig_user_id'   => pbirds_config_value('PBIRDS_IG_BUSINESS_ACCOUNT_ID', ''),
    ];
}

function pbirds_ig_store_tokens(string $accessToken, int $expiresIn): void {
    pbirds_session_start();
    $_SESSION['pbirds_ig_access_token'] = $accessToken;
    $_SESSION['pbirds_ig_expires_at'] = time() + max(60, $expiresIn - 60);
}

function pbirds_ig_token_is_fresh(): bool {
    pbirds_session_start();
    $exp = (int)($_SESSION['pbirds_ig_expires_at'] ?? 0);
    return !empty($_SESSION['pbirds_ig_access_token']) && $exp > (time() + 60);
}

function pbirds_ig_authorize_url(): string {
    $cfg = pbirds_ig_config();
    if ($cfg['app_id'] === '') throw new RuntimeException('Instagram/Facebook App ID is not configured on the server.');
    pbirds_session_start();
    $state = bin2hex(random_bytes(24));
    $_SESSION['pbirds_ig_oauth_state'] = $state;
    $query = http_build_query([
        'client_id' => $cfg['app_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'scope' => $cfg['scopes'],
        'response_type' => 'code',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
    return $cfg['oauth_base'] . '?' . $query;
}

function pbirds_ig_exchange_code(string $code): array {
    $cfg = pbirds_ig_config();
    if ($cfg['app_id'] === '' || $cfg['app_secret'] === '') throw new RuntimeException('Instagram/Facebook OAuth credentials are not configured on the server.');
    [$status, $data] = pbirds_http('GET', $cfg['graph_base'] . '/oauth/access_token?' . http_build_query([
        'client_id' => $cfg['app_id'],
        'client_secret' => $cfg['app_secret'],
        'redirect_uri' => $cfg['redirect_uri'],
        'code' => $code,
    ]), null, null);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        $msg = $data['error']['message'] ?? ('Facebook token exchange failed (' . $status . ')');
        throw new RuntimeException($msg);
    }
    // Exchange the short-lived token for a long-lived one (~60 days) so the
    // publish step doesn't need a re-login every hour.
    [$status2, $long] = pbirds_http('GET', $cfg['graph_base'] . '/oauth/access_token?' . http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $cfg['app_id'],
        'client_secret' => $cfg['app_secret'],
        'fb_exchange_token' => $data['access_token'],
    ]), null, null);
    if ($status2 >= 200 && $status2 < 300 && !empty($long['access_token'])) {
        return $long;
    }
    return $data;
}

function pbirds_ig_login(): void {
    try { header('Location: ' . pbirds_ig_authorize_url(), true, 302); exit; }
    catch (Throwable $e) { pbirds_json_response(['ok'=>false,'error'=>$e->getMessage()],500); }
}

function pbirds_ig_callback(): void {
    pbirds_session_start();
    if (!empty($_GET['error'])) {
        $description = trim((string)($_GET['error_description'] ?? $_GET['error_reason'] ?? 'Instagram authorization was cancelled or denied.'));
        unset($_SESSION['pbirds_ig_oauth_state']);
        pbirds_render_popup_result('pbirds-ig-auth', false, $description, 'ig_auth_error=1');
    }
    $state = (string)($_GET['state'] ?? '');
    $expectedState = (string)($_SESSION['pbirds_ig_oauth_state'] ?? '');
    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        unset($_SESSION['pbirds_ig_oauth_state']);
        pbirds_render_popup_result('pbirds-ig-auth', false, 'Invalid Instagram OAuth state.', 'ig_auth_error=1');
    }
    unset($_SESSION['pbirds_ig_oauth_state']);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        pbirds_render_popup_result('pbirds-ig-auth', false, 'Instagram returned no authorization code.', 'ig_auth_error=1');
    }
    try {
        $tokens = pbirds_ig_exchange_code($code);
        pbirds_ig_store_tokens((string)$tokens['access_token'], (int)($tokens['expires_in'] ?? 5184000));
    } catch (Throwable $e) {
        pbirds_render_popup_result('pbirds-ig-auth', false, $e->getMessage(), 'ig_auth_error=1');
    }
    pbirds_render_popup_result('pbirds-ig-auth', true, '', 'ig_connected=1');
}

function pbirds_ig_status(): void {
    pbirds_session_start();
    pbirds_json_response(['ok'=>true,'connected'=>pbirds_ig_token_is_fresh()]);
}

function pbirds_ig_logout(): void {
    pbirds_session_start();
    unset($_SESSION['pbirds_ig_access_token'], $_SESSION['pbirds_ig_expires_at'], $_SESSION['pbirds_ig_oauth_state']);
    pbirds_json_response(['ok'=>true]);
}

/** Discover the IG Business Account ID behind the logged-in user's Pages, if not preset. */
function pbirds_ig_resolve_user_id(string $accessToken): string {
    $cfg = pbirds_ig_config();
    if ($cfg['ig_user_id'] !== '') return $cfg['ig_user_id'];
    [$status, $pages] = pbirds_http('GET', $cfg['graph_base'] . '/me/accounts?access_token=' . urlencode($accessToken), null, null);
    if ($status < 200 || $status >= 300 || empty($pages['data'][0]['id'])) {
        throw new RuntimeException('No Facebook Page found for this account.');
    }
    $pageId = (string)$pages['data'][0]['id'];
    $pageToken = (string)($pages['data'][0]['access_token'] ?? $accessToken);
    [$status2, $igData] = pbirds_http('GET', $cfg['graph_base'] . '/' . rawurlencode($pageId) . '?fields=instagram_business_account&access_token=' . urlencode($pageToken), null, null);
    $igId = (string)($igData['instagram_business_account']['id'] ?? '');
    if ($igId === '') throw new RuntimeException('This Facebook Page has no linked Instagram Business account.');
    return $igId;
}

/**
 * Publish an already-public image URL (e.g. the FLOCKED.png hosted URL from
 * Project_FIREBIRD's Frame.io/S3 output) to Instagram Feed. Two-step Content
 * Publishing API: create a media container, then publish it.
 */
function pbirds_ig_publish(): void {
    pbirds_session_start();
    if (!pbirds_ig_token_is_fresh()) {
        pbirds_json_response(['ok'=>false,'error'=>'Instagram authorization is required.'], 401);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $imageUrl = trim((string)($input['imageUrl'] ?? ''));
    $caption = (string)($input['caption'] ?? '');
    if ($imageUrl === '' || !preg_match('/^https:\/\//i', $imageUrl)) {
        pbirds_json_response(['ok'=>false,'error'=>'A public https imageUrl is required (Instagram fetches it directly).'], 400);
    }

    $cfg = pbirds_ig_config();
    $accessToken = (string)$_SESSION['pbirds_ig_access_token'];

    try {
        $igUserId = pbirds_ig_resolve_user_id($accessToken);

        [$status, $container] = pbirds_http('POST', $cfg['graph_base'] . '/' . rawurlencode($igUserId) . '/media', [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $accessToken,
        ], null);
        if ($status < 200 || $status >= 300 || empty($container['id'])) {
            throw new RuntimeException($container['error']['message'] ?? ('Media container creation failed (' . $status . ')'));
        }
        $creationId = (string)$container['id'];

        [$status2, $publish] = pbirds_http('POST', $cfg['graph_base'] . '/' . rawurlencode($igUserId) . '/media_publish', [
            'creation_id' => $creationId,
            'access_token' => $accessToken,
        ], null);
        if ($status2 < 200 || $status2 >= 300 || empty($publish['id'])) {
            throw new RuntimeException($publish['error']['message'] ?? ('Publish failed (' . $status2 . ')'));
        }

        pbirds_json_response(['ok'=>true,'mediaId'=>$publish['id']]);
    } catch (Throwable $e) {
        pbirds_json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

// =============================================================================
// C. OTHER PLATFORMS — same shape, not built. Each of these needs its own
//    app registration + OAuth + publish endpoint, same as Instagram above:
//      - X/Twitter:      developer.x.com, OAuth 2.0, POST /2/tweets (+ media upload)
//      - Facebook Page:  same Meta app as Instagram, POST /{page-id}/photos
//      - Pinterest:      developers.pinterest.com, OAuth 2.0, POST /v5/pins
//    Router entries below return 501 until one of these is actually wanted.
// =============================================================================

function pbirds_platform_placeholder(string $platform): void {
    pbirds_json_response([
        'ok' => false,
        'service' => $platform,
        'status' => 'not_configured',
        'message' => ucfirst($platform) . ' publishing is not built yet — needs its own app registration and OAuth.'
    ], 501);
}

// =============================================================================
// ROUTER
// =============================================================================

pbirds_cors_headers();

$api = strtolower(trim((string)($_GET['api'] ?? '')));
if ($api === 'express-login') pbirds_express_login();
if ($api === 'express-callback') pbirds_express_callback();
if ($api === 'express-status') pbirds_express_status();
if ($api === 'express-logout') pbirds_express_logout();
if ($api === 'express-templates') pbirds_express_list_templates();
if ($api === 'express-prepare') pbirds_express_prepare_creative();
if ($api === 'express-job-status') pbirds_express_job_status();
if ($api === 'ig-login') pbirds_ig_login();
if ($api === 'ig-callback') pbirds_ig_callback();
if ($api === 'ig-status') pbirds_ig_status();
if ($api === 'ig-logout') pbirds_ig_logout();
if ($api === 'ig-publish') pbirds_ig_publish();
if ($api === 'twitter-publish') pbirds_platform_placeholder('twitter');
if ($api === 'facebook-publish') pbirds_platform_placeholder('facebook');
if ($api === 'pinterest-publish') pbirds_platform_placeholder('pinterest');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PutBirds — Send your postcards out</title>
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
      --success: #2e7d32;
      --radius: 12px;
      --radius-sm: 8px;
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

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      width: 100%;
      max-width: 420px;
      margin-bottom: 1rem;
    }

    .card h2 {
      font-size: 1rem;
      margin: 0 0 0.75rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .status-pill {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.2rem 0.6rem;
      border-radius: 999px;
      background: var(--surface-alt);
      color: var(--muted);
    }

    .status-pill.connected {
      background: #e3f2e5;
      color: var(--success);
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.6rem;
      width: 100%;
      padding: 0.75rem 1.25rem;
      font-size: 0.9375rem;
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

    .btn:hover:not(:disabled) {
      background: var(--accent-hover);
      box-shadow: var(--shadow-hover);
      transform: translateY(-1px);
    }

    .btn:disabled { opacity: 0.6; cursor: not-allowed; }

    .btn.secondary {
      background: var(--surface-alt);
      color: var(--text);
    }

    input[type="text"], input[type="url"], textarea {
      width: 100%;
      padding: 0.65rem 0.75rem;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      font-family: inherit;
      font-size: 0.9375rem;
      margin-bottom: 0.75rem;
      background: var(--surface);
      color: var(--text);
    }

    textarea { min-height: 80px; resize: vertical; }

    .field-label {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--text-soft);
      margin-bottom: 0.25rem;
      display: block;
    }

    .msg {
      font-size: 0.875rem;
      margin-top: 0.75rem;
      display: none;
    }

    .msg.show { display: block; }
    .msg.ok { color: var(--success); }
    .msg.err { color: var(--error); }
  </style>
</head>
<body>
  <main class="screen">
    <h1 class="brand">PutBirds</h1>
    <p class="tagline">send your postcards out into the world</p>

    <div class="card">
      <h2>Adobe <span id="expressPill" class="status-pill">checking…</span></h2>
      <button type="button" id="expressBtn" class="btn">Sign in with Adobe</button>
    </div>

    <div class="card">
      <h2>Instagram <span id="igPill" class="status-pill">checking…</span></h2>
      <button type="button" id="igBtn" class="btn">Connect Instagram</button>
    </div>

    <div class="card">
      <h2>Prepare with Express</h2>
      <label class="field-label" for="sourceUrl">FLOCKED.png URL (public, https)</label>
      <input type="url" id="sourceUrl" placeholder="https://.../FLOCKED.png" />
      <label class="field-label" for="caption">Caption</label>
      <textarea id="caption" placeholder="From Project FIREBIRD 🐦"></textarea>
      <button type="button" id="prepareBtn" class="btn secondary">Drop into Express template</button>
      <p id="prepareMsg" class="msg"></p>
    </div>

    <div class="card">
      <h2>Post to Instagram</h2>
      <label class="field-label" for="imageUrl">Image URL to publish (public, https)</label>
      <input type="url" id="imageUrl" placeholder="Filled in automatically once Express finishes, or paste FLOCKED.png directly" />
      <button type="button" id="publishBtn" class="btn secondary">Post to Instagram</button>
      <p id="publishMsg" class="msg"></p>
    </div>
  </main>

  <script>
    (function () {
      var BASE = "https://hh5hh.com/pbirds.php";

      function popup(url, name) {
        var w = 480, h = 640;
        var left = (screen.width - w) / 2, top = (screen.height - h) / 2;
        return window.open(url, name, "width=" + w + ",height=" + h + ",left=" + left + ",top=" + top);
      }

      function refreshExpress() {
        fetch(BASE + "?api=express-status", { credentials: "same-origin", cache: "no-store" })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var pill = document.getElementById("expressPill");
            var btn = document.getElementById("expressBtn");
            if (d.connected) {
              pill.textContent = "connected"; pill.classList.add("connected");
              btn.textContent = "Reconnect Adobe";
            } else {
              pill.textContent = "not connected"; pill.classList.remove("connected");
              btn.textContent = "Sign in with Adobe";
            }
          });
      }

      function refreshIg() {
        fetch(BASE + "?api=ig-status", { credentials: "same-origin", cache: "no-store" })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var pill = document.getElementById("igPill");
            var btn = document.getElementById("igBtn");
            if (d.connected) {
              pill.textContent = "connected"; pill.classList.add("connected");
              btn.textContent = "Reconnect Instagram";
            } else {
              pill.textContent = "not connected"; pill.classList.remove("connected");
              btn.textContent = "Connect Instagram";
            }
          });
      }

      window.addEventListener("message", function (ev) {
        if (ev.origin !== "https://hh5hh.com") return;
        if (!ev.data || typeof ev.data !== "object") return;
        if (ev.data.type === "pbirds-express-auth") refreshExpress();
        if (ev.data.type === "pbirds-ig-auth") refreshIg();
      });

      document.getElementById("expressBtn").addEventListener("click", function () {
        popup(BASE + "?api=express-login", "pbirds_express_auth");
      });

      document.getElementById("igBtn").addEventListener("click", function () {
        popup(BASE + "?api=ig-login", "pbirds_ig_auth");
      });

      function pollExpressJob(jobId, onDone) {
        var msg = document.getElementById("prepareMsg");
        var attempts = 0;
        (function tick() {
          attempts++;
          fetch(BASE + "?api=express-job-status&jobId=" + encodeURIComponent(jobId), { credentials: "same-origin", cache: "no-store" })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              // Verified against Adobe's CreateVariationStatusResponse schema:
              // status is one of pending/running/succeeded/failed/cancelled/
              // partially_succeeded/cancel_requested.
              var jobStatus = (d.data && d.data.status) || "";
              if (jobStatus === "succeeded") {
                msg.className = "msg show ok"; msg.textContent = "Express render ready.";
                onDone(d.data);
              } else if (jobStatus === "failed" || jobStatus === "cancelled" || jobStatus === "partially_succeeded") {
                msg.className = "msg show err"; msg.textContent = "Express job " + jobStatus + ".";
              } else if (attempts < 30) {
                msg.textContent = "Rendering in Express… (" + attempts + ")";
                setTimeout(tick, 2000);
              } else {
                msg.className = "msg show err"; msg.textContent = "Timed out waiting on Express.";
              }
            });
        })();
      }

      document.getElementById("prepareBtn").addEventListener("click", function () {
        var msg = document.getElementById("prepareMsg");
        var sourceUrl = document.getElementById("sourceUrl").value.trim();
        var caption = document.getElementById("caption").value;
        msg.className = "msg show"; msg.textContent = "Sending to Express…";
        fetch(BASE + "?api=express-prepare", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ imageUrl: sourceUrl, caption: caption })
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok || !d.jobId) {
              msg.className = "msg show err"; msg.textContent = d.error || "Express request failed.";
              return;
            }
            pollExpressJob(d.jobId, function (result) {
              // Verified path: outputs[0].destination.url (ImageOutputResult).
              var outUrl = (result && result.outputs && result.outputs[0] && result.outputs[0].destination && result.outputs[0].destination.url) || "";
              if (outUrl) document.getElementById("imageUrl").value = outUrl;
            });
          });
      });

      document.getElementById("publishBtn").addEventListener("click", function () {
        var msg = document.getElementById("publishMsg");
        var imageUrl = document.getElementById("imageUrl").value.trim();
        var caption = document.getElementById("caption").value;
        msg.className = "msg show"; msg.textContent = "Posting…";
        fetch(BASE + "?api=ig-publish", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ imageUrl: imageUrl, caption: caption })
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.ok) {
              msg.className = "msg show ok"; msg.textContent = "Posted! Media ID " + d.mediaId;
            } else {
              msg.className = "msg show err"; msg.textContent = d.error || "Post failed.";
            }
          })
          .catch(function () {
            msg.className = "msg show err"; msg.textContent = "Network error.";
          });
      });

      refreshExpress();
      refreshIg();
    })();
  </script>
</body>
</html>
