<?php
require_once __DIR__ . '/config.php';
init_db();
$db = db();

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/^www\./', '', $host);

error_log("[SUBDOMAIN_ROUTER] Host: {$host} | URI: {$_SERVER['REQUEST_URI']}");

$parts = explode('.', $host);
if (count($parts) < 3) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$subdomain = $parts[0];
$domain = implode('.', array_slice($parts, 1));

error_log("[SUBDOMAIN_ROUTER] Looking for subdomain: {$subdomain} | domain: {$domain}");

$stmt = $db->prepare("SELECT s.*, u.home_dir, u.status FROM subdomains s JOIN users u ON s.user_id = u.id WHERE s.subdomain = ? AND s.domain = ? AND u.status = 'active'");
$stmt->execute([$subdomain, $domain]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    error_log("[SUBDOMAIN_ROUTER] Not found in subdomains table, checking users table...");
    $stmt2 = $db->prepare("SELECT username, home_dir, domain, status FROM users WHERE domain = ? AND role = 'cpanel' AND status = 'active'");
    $stmt2->execute([$host]);
    $user = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $sub = [
            'document_root' => rtrim($user['home_dir'], '/') . '/public_html',
            'subdomain' => $subdomain,
            'domain' => $domain,
        ];
        error_log("[SUBDOMAIN_ROUTER] Found in users table: home_dir={$user['home_dir']}");
    }
}

if (!$sub) {
    error_log("[SUBDOMAIN_ROUTER] Subdomain NOT FOUND anywhere: {$host}");
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px"><h1>Subdomain Not Found</h1><p>The subdomain <strong>' . htmlspecialchars($host) . '</strong> does not exist or is suspended.</p></body></html>';
    exit;
}

$doc_root = $sub['document_root'];
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_uri = rtrim($request_uri, '/') ?: '/';

$file = $doc_root . $request_uri;

error_log("[SUBDOMAIN_ROUTER] DocRoot: {$doc_root}");
error_log("[SUBDOMAIN_ROUTER] Requested file: {$file}");
error_log("[SUBDOMAIN_ROUTER] File exists: " . (is_file($file) ? 'YES' : 'NO'));
error_log("[SUBDOMAIN_ROUTER] Dir exists: " . (is_dir($file) ? 'YES' : 'NO'));

if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
    if (!is_file($file)) {
        $file = rtrim($doc_root . $request_uri, '/') . '/index.html';
    }
    error_log("[SUBDOMAIN_ROUTER] After dir check, file: {$file} | exists: " . (is_file($file) ? 'YES' : 'NO'));
}

if (!is_file($file)) {
    error_log("[SUBDOMAIN_ROUTER] FILE NOT FOUND: {$file}");
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px"><h1>404 Not Found</h1><p>The requested file was not found on <strong>' . htmlspecialchars($host) . '</strong>.</p><p style="font-size:12px;color:#999">Path: ' . htmlspecialchars($file) . '</p></body></html>';
    exit;
}

error_log("[SUBDOMAIN_ROUTER] Serving: {$file}");

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'html' => 'text/html', 'htm' => 'text/html', 'php' => 'text/html',
    'css' => 'text/css', 'js' => 'application/javascript',
    'json' => 'application/json', 'xml' => 'application/xml',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'webp' => 'image/webp', 'avif' => 'image/avif',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    'pdf' => 'application/pdf', 'zip' => 'application/zip',
    'txt' => 'text/plain', 'csv' => 'text/csv',
];

if ($ext === 'php') {
    $_SERVER['DOCUMENT_ROOT'] = $doc_root;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    chdir($doc_root);
    require $file;
} else {
    $mime = $mime_map[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
}
