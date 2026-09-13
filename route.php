<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';
$root = __DIR__;

// Customer domains must never reach the panel's system pages (login, cpanel,
// whm, api, ...). Only the panel's own host (localhost, an IP, or the panel
// domain) may serve them. Everything else goes through index.php, which only
// ever serves content from the customer's own document root.
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\d+$/', '', $host);
$host = preg_replace('/^www\./', '', $host);
$is_localhost = ($host === 'localhost' || $host === '127.0.0.1' || preg_match('/^(\d+\.){3}\d+$/', $host));
if (!$is_localhost && $host !== '' && $host !== 'dzhost.shop') {
    // Allow the configured server hostname (WHM → Hostname) to reach the
    // panel too. Read the cached value first so customer traffic stays fast;
    // fall back to the DB when the cache is cold.
    $hn = '';
    $raw = @file_get_contents(__DIR__ . '/.cache/servername');
    if (is_string($raw) && $raw !== '') {
        $c = json_decode($raw, true);
        if (is_array($c) && isset($c['value'])) {
            $hn = strtolower(trim((string)$c['value']));
        }
    }
    if ($hn === '') {
        try {
            require_once __DIR__ . '/config.php';
            $hn = strtolower(trim((string)server_hostname()));
        } catch (Throwable $e) {}
    }
    if ($hn === '' || $hn === 'localhost' || $host !== $hn) {
        require __DIR__ . '/index.php';
        return true;
    }
}

// --- Static assets ---
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
        header('Content-Type: ' . ($mime[strtolower($ext)] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}

// --- Root ---
if ($uri === '/') {
    require $root . '/index.php';
    return true;
}

// --- Clean-URL routes (no extension) ---
$routes = [
    '/login'          => $root . '/login.php',
    '/logout'         => $root . '/logout.php',
    '/whm/login'      => $root . '/whm/login.php',
    '/whm'            => $root . '/whm/index.php',
    '/whm/password'   => $root . '/whm/password.php',

    '/features'       => $root . '/features.php',

    '/cpanel'            => $root . '/cpanel/index.php',
    '/cpanel/domains'    => $root . '/cpanel/domains.php',
    '/whm/health'        => $root . '/whm/health.php',
    '/api/v1/accounts'   => $root . '/api/v1/accounts.php',
];

if (isset($routes[$uri])) {
    require $routes[$uri];
    return true;
}

// --- Direct .php files (with or without query string) ---
$filePath = $root . $uri;
if (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'php') {
    require $filePath;
    return true;
}

// --- Fallback: if it's a real file, serve it ---
if (is_file($filePath)) {
    header('Content-Type: application/octet-stream');
    readfile($filePath);
    return true;
}

// --- 404 ---
http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404</title><link rel="stylesheet" href="/assets/style.css?v=20260731b"></head><body class="login-body"><div class="login-container"><div class="login-card"><div class="login-logo"><div class="logo-icon"><i data-lucide="file-x" class="lucide"></i></div><h1>Page Not Found</h1><p>The page you requested does not exist.</p></div><a href="/" class="btn btn-primary btn-full" style="background:var(--text);color:#fff">Go Home</a></div></div><script defer src="/assets/lucide.min.js"></script><script>(function(){function i(){if(window.lucide&&typeof window.lucide.createIcons==="function"){window.lucide.createIcons();return true}return false}if(!i()){var t=setInterval(function(){if(i())clearInterval(t)},50);setTimeout(function(){clearInterval(t)},8000)}})();</script></body></html>';
return true;
