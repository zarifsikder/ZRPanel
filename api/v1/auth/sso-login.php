<?php

require_once __DIR__ . '/../../../config.php';

header('Content-Type: application/json');
init_db();
$db = db();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$username = trim($body['username'] ?? '');
if ($username === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'username is required']);
    exit;
}

$stmt = $db->prepare("SELECT id, username, role, package_id FROM users WHERE username = ? AND status = 'active'");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Account not found or inactive']);
    exit;
}
if (($user['role'] ?? '') === 'whm') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'SSO is only available for client accounts']);
    exit;
}

$next = $body['next'] ?? '';
if (!is_string($next) || $next === '' || $next[0] !== '/' || strpos($next, '//') === 0) {
    $next = '/cpanel/';
}

$token = bin2hex(random_bytes(32));
$stmt = $db->prepare("INSERT INTO sso_tokens (token_hash, user_id, used, expires_at) VALUES (?, ?, 0, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
$stmt->execute([hash('sha256', $token), $user['id']]);

$url = '/sso-login.php?token=' . $token . '&next=' . rawurlencode($next);

echo json_encode([
    'success' => true,
    'url' => $url,
    'username' => $user['username'],
    'role' => $user['role'],
]);