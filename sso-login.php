<?php

require_once __DIR__ . '/config.php';
init_db();

if (is_logged_in()) {
    redirect(post_login_redirect_target());
}

$token = $_GET['token'] ?? '';

if (!is_string($token) || strlen($token) < 32) {
    redirect('/login.php');
}

$db = db();
$stmt = $db->prepare("SELECT s.id, s.user_id, s.used, s.expires_at, u.username, u.role, u.package_id, u.status
                      FROM sso_tokens s
                      JOIN users u ON u.id = s.user_id
                      WHERE s.token_hash = ?");
$stmt->execute([hash('sha256', $token)]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || $row['used'] != 0 || strtotime($row['expires_at']) < time() || $row['status'] !== 'active') {
    redirect('/login.php');
}

$db->prepare("DELETE FROM sso_tokens WHERE id = ?")->execute([$row['id']]);
$db->prepare("DELETE FROM sso_tokens WHERE user_id = ? AND used = 0")->execute([$row['user_id']]);

session_regenerate_id(true);

$_SESSION['user_id'] = $row['user_id'];
$_SESSION['username'] = $row['username'];
$_SESSION['role'] = $row['role'];
$_SESSION['package_id'] = $row['package_id'];

unset($_SESSION['2fa_pending_user']);

$next = $_GET['next'] ?? '';
if (is_string($next) && $next !== '' && $next[0] === '/' && strpos($next, '//') !== 0) {
    redirect($next);
}

if ($row['role'] === 'whm') {
    redirect('/whm/');
}
redirect('/cpanel/');