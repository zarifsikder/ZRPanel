<?php
// ============================================================
//  ZRPanel Router — Ultra-Fast Request Handler
// ============================================================

// --- Performance: Start timer ---
$_SERVER['_START_TIME'] = microtime(true);

// --- Security headers (all responses) ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: private, no-cache, must-revalidate');
header('Vary: Accept-Encoding');

// --- Remove the server fingerprint header ---
@ini_set('expose_php', '0');
header_remove('X-Powered-By');

// --- Compression + HTML minification output buffers ---
// Buffer order matters: the gzip buffer is started FIRST (outer) and the
// minifier SECOND (inner), so at shutdown output flows minify -> gzip -> client.
if (extension_loaded('zlib') && !headers_sent() && !ini_get('zlib.output_compression')
    && strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
    ob_start('ob_gzhandler', 4096);
}
ob_start('html_minify_buffer', 4096);

/**
 * Conservative HTML minifier for text/html responses. Preserves the contents
 * of <pre>, <textarea>, <script> and <style> blocks untouched and only strips
 * comments plus inter-tag whitespace, so rendered pages are pixel-identical.
 */
function html_minify_buffer($buffer) {
    $isHtml = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') !== false) {
            $isHtml = true;
            break;
        }
    }
    if (!$isHtml || strlen($buffer) < 512) {
        return $buffer;
    }

    $protected = [];
    $buffer = preg_replace_callback(
        '#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
        function ($m) use (&$protected) {
            $k = '##M' . count($protected) . '##';
            $protected[$k] = $m[0];
            return $k;
        },
        $buffer
    );

    $buffer = preg_replace('/<!--(?!\[if)([\s\S]*?)-->/', '', $buffer);
    $buffer = preg_replace('/>\s+</', '><', $buffer);
    $buffer = preg_replace('/\s{2,}/', ' ', $buffer);

    foreach ($protected as $k => $v) {
        $buffer = str_replace($k, $v, $buffer);
    }
    return $buffer;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';
$uriRaw = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;

// Static asset requests never need a session: no Set-Cookie, no session files,
// no session I/O. Defined before config.php is loaded by webapps.php.
$__asset_ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
if (in_array($__asset_ext, ['css','js','png','jpg','jpeg','gif','svg','ico','webp','avif','woff','woff2','ttf','eot','txt','map','json','pdf'], true)) {
    define('NO_SESSION', true);
}

// --- Web Apps: path-based serving (must run before custom-domain handling) ---
require_once __DIR__ . '/webapps.php';
if (wa_proxy_handle()) {
    return true;
}

// --- Custom domain detection: serve user content for ALL paths ---
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\d+$/', '', $host);
$host = preg_replace('/^www\./', '', $host);
$is_localhost = ($host === 'localhost' || $host === '127.0.0.1' || preg_match('/^(\d+\.){3}\d+$/', $host));
$is_server_domain = ($host === 'dzhost.shop');
// The configured server hostname (WHM → Hostname) is also a panel host, so
// the admin panel and its assets keep working when reached through it.
if (!$is_localhost && !$is_server_domain && $host !== '' && function_exists('server_hostname')) {
    $is_server_domain = ($host === server_hostname());
}

if (!$is_localhost && !$is_server_domain && $host !== '') {
    require $root . '/index.php';
    return true;
}

// --- Static assets with aggressive caching ---
$ext = pathinfo($uri, PATHINFO_EXTENSION);
$staticExts = ['css','js','png','jpg','jpeg','gif','svg','ico','woff','woff2','ttf','eot','map','json','txt','webp','avif'];
if ($ext && in_array(strtolower($ext), $staticExts)) {
    $file = $root . $uri;
    if (is_file($file)) {
        $mime = [
            'css'=>'text/css','js'=>'application/javascript','png'=>'image/png',
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
            'svg'=>'image/svg+xml','ico'=>'image/x-icon','woff'=>'font/woff',
            'woff2'=>'font/woff2','ttf'=>'font/ttf','eot'=>'application/vnd.ms-fontobject',
            'map'=>'application/json','json'=>'application/json','txt'=>'text/plain',
            'webp'=>'image/webp','avif'=>'image/avif',
        ];
        $etag = '"' . md5_file($file) . '"';
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            return true;
        }
        header('Content-Type: ' . ($mime[strtolower($ext)] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}

// --- KODExplorer web file manager (served in-tree, gated by panel login) ---
// Must run before the Root block so /file-manager/… always stays inside the
// app. Static code assets are streamed by the static-asset branch above;
// everything else (the SPA shell, AJAX, uploads, downloads) needs a session.
if ($uri === '/file-manager' || strpos($uri, '/file-manager/') === 0) {
    require_once $root . '/config.php';
    // The app shell uses relative asset URLs (./static/...). Requesting
    // /file-manager without a trailing slash resolves them against the site
    // root, so CSS/JS/fonts 404. Canonicalize to the trailing-slash form.
    // ($uri is rtrim'd, so compare against the raw path.)
    if ($uriRaw === '/file-manager') {
        $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
        header('Location: /file-manager/' . $qs);
        return true;
    }
    require_login();
    if (!feature_flag('kod_file_manager')) {
        http_response_code(404);
        echo 'File Manager is disabled.';
        return true;
    }
    // SSO: provision a KOD account for the panel user and auto-log them in.
    require_once $root . '/kod_sso.php';
    $door = kod_sso_panel_door();
    if ($door === 'redirect') {
        $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
        header('Location: /file-manager/' . $qs);
        return true;
    }
    $kodRoot = $root . '/file-manager';
    $kodPath  = $kodRoot . (($uri === '/file-manager') ? '/' : substr($uri, strlen('/file-manager')));
    if (is_file($kodPath) && strtolower(pathinfo($kodPath, PATHINFO_EXTENSION)) !== 'php') {
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ((@finfo_open(FILEINFO_MIME_TYPE) ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $kodPath) : 'application/octet-stream')));
        readfile($kodPath);
        return true;
    }
    // Pretend the request landed on KODExplorer's own front controller so its
    // URL generation (it reads SCRIPT_NAME) keeps producing /file-manager/… links.
    $_SERVER['SCRIPT_NAME']     = '/file-manager/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $kodRoot . '/index.php';
    $_SERVER['PHP_SELF']        = '/file-manager/index.php';
    // Close the panel session: KODExplorer needs to open its own session under
    // a different name (KOD_SESSION_ID_xxx) and session_name() is a no-op while
    // a session is still active. After write_close() PHP would keep reusing the
    // panel's session id, so re-seed the id from the KOD session cookie and
    // disable strict mode (strict mode throws away hand/php-minted ids when a
    // session file for them is already on disk).
    @session_write_close();
    $kodCookie = $_COOKIE[kod_sso_session_name()] ?? '';
    if ($kodCookie !== '' && preg_match('/^[A-Za-z0-9,-]{1,128}$/', $kodCookie)) {
        @session_id($kodCookie);
    }
    @ini_set('session.use_strict_mode', '0');
    chdir($kodRoot);
    require $kodRoot . '/index.php';
    return true;
}

// --- Root ---
if ($uri === '/') {
    require $root . '/index.php';
    return true;
}

// --- API v1 authentication (must be before clean URL routes) ---
if (strpos($uri, '/api/v1/') === 0) {
    require_once $root . '/api/auth.php';
    authenticate_api_request();
}

// --- Clean-URL routes ---
$routes = [
    '/login'             => $root . '/login.php',
    '/logout'            => $root . '/logout.php',
    '/sso-login'         => $root . '/sso-login.php',
    '/whm/login'         => $root . '/whm/login.php',
    '/whm'               => $root . '/whm/index.php',
    '/whm/accounts'      => $root . '/whm/accounts.php',
    '/whm/password'      => $root . '/whm/password.php',
    '/whm/packages'      => $root . '/whm/packages.php',
    '/whm/domains'       => $root . '/whm/domains.php',

    '/whm/server'        => $root . '/whm/server.php',
    '/whm/tunnels'       => $root . '/whm/tunnels.php',
    '/cpanel'            => $root . '/cpanel/index.php',
    '/cpanel/domains'    => $root . '/cpanel/domains.php',
    '/cpanel/subdomains' => $root . '/cpanel/subdomains.php',
    '/cpanel/ftp'        => $root . '/cpanel/ftp.php',
    '/cpanel/dns'        => $root . '/cpanel/dns.php',
    '/cpanel/ssl'        => $root . '/cpanel/ssl.php',
    '/cpanel/cron'       => $root . '/cpanel/cron.php',
    '/cpanel/backups'    => $root . '/cpanel/backups.php',

    '/cpanel/error-pages'=> $root . '/cpanel/error-pages.php',
    '/cpanel/ip-blocker' => $root . '/cpanel/ip-blocker.php',
    '/cpanel/mime-types' => $root . '/cpanel/mime-types.php',
    '/cpanel/metrics'    => $root . '/cpanel/metrics.php',
    '/cpanel/cache'      => $root . '/cpanel/cache.php',
    '/cpanel/php-versions'=>$root . '/cpanel/php-versions.php',
    '/cpanel/phpinfo'    => $root . '/cpanel/phpinfo.php',
    '/cpanel/tunnel'     => $root . '/cpanel/tunnel.php',
    '/docs/domain-setup' => $root . '/docs/domain-setup.php',
    '/whm/health'        => $root . '/whm/health.php',
    '/whm/api-keys'      => $root . '/whm/api-keys.php',
    '/whm/editor'        => $root . '/whm/sys-editor.php',
    '/api/v1/server'     => $root . '/api/v1/server.php',
    '/api/v1/accounts'   => $root . '/api/v1/accounts.php',
    '/api/v1/accounts/suspend'   => $root . '/api/v1/accounts.php',
    '/api/v1/accounts/unsuspend' => $root . '/api/v1/accounts.php',
    '/api/v1/packages'   => $root . '/api/v1/packages.php',
    '/api/v1/auth/sso-login' => $root . '/api/v1/auth/sso-login.php',
    '/cpanel/two-factor'          => $root . '/cpanel/two-factor.php',
    '/cpanel/directory-privacy'   => $root . '/cpanel/directory-privacy.php',
    '/cpanel/leech-protection'    => $root . '/cpanel/leech-protection.php',
    '/cpanel/modsecurity'         => $root . '/cpanel/modsecurity.php',
    '/cpanel/malware-scanner'     => $root . '/cpanel/malware-scanner.php',
    '/cpanel/indexes'             => $root . '/cpanel/indexes.php',
    '/cpanel/apache-handlers'     => $root . '/cpanel/apache-handlers.php',
    '/cpanel/php-ini'             => $root . '/cpanel/php-ini.php',
    '/cpanel/php-extensions'      => $root . '/cpanel/php-extensions.php',
    '/cpanel/terminal'            => $root . '/cpanel/terminal.php',
    '/cpanel/track-dns'           => $root . '/cpanel/track-dns.php',
    '/cpanel/resource-usage'      => $root . '/cpanel/resource-usage.php',
    '/cpanel/nodejs-apps'         => $root . '/cpanel/nodejs-apps.php',
    '/cpanel/web-apps'            => $root . '/cpanel/web-apps.php',
    '/cpanel/python-apps'         => $root . '/cpanel/python-apps.php',
    '/cpanel/wordpress'           => $root . '/cpanel/wordpress.php',
    '/cpanel/bandwidth'           => $root . '/cpanel/bandwidth.php',
    '/cpanel/password-security'   => $root . '/cpanel/password-security.php',
    '/cpanel/contact-info'        => $root . '/cpanel/contact-info.php',
    '/features'                   => $root . '/features.php',
];

if (isset($routes[$uri])) {
    require $routes[$uri];
    return true;
}

// --- Direct .php files ---
$filePath = $root . $uri;
if (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'php') {
    require $filePath;
    return true;
}

// --- Redirect non-existent paths under system directories ---
if (preg_match('#^/(cpanel|whm|api)#', $uri, $m)) {
    $baseFile = $root . '/' . $m[1] . '/index.php';
    if (is_file($baseFile)) {
        header('Location: /' . $m[1] . '/');
        exit;
    }
    http_response_code(404);
    return true;
}

// --- API files ---
if (strpos($uri, '/api/') === 0 && is_file($filePath)) {
    require $filePath;
    return true;
}

// --- Fallback: serve real files ---
if (is_file($filePath)) {
    header('Content-Type: application/octet-stream');
    readfile($filePath);
    return true;
}

// --- 404 ---
http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404</title><link rel="stylesheet" href="/assets/style.css?v=20260731b"></head><body class="login-body"><div class="login-container"><div class="login-card"><div class="login-logo"><div class="logo-icon"><i data-lucide="file-x" class="lucide"></i></div><h1>Page Not Found</h1><p>The page you requested does not exist.</p></div><a href="/" class="btn btn-primary btn-full" style="background:var(--text);color:#fff">Go Home</a></div></div><script defer src="/assets/lucide.min.js"></script><script>(function(){function i(){if(window.lucide&&typeof window.lucide.createIcons==="function"){window.lucide.createIcons();return true}return false}if(!i()){var t=setInterval(function(){if(i())clearInterval(t)},50);setTimeout(function(){clearInterval(t)},8000)}})();</script></body></html>';
return true;
