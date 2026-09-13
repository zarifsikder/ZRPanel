<?php

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');
init_db();
$db = db();

$method = $_SERVER['REQUEST_METHOD'];
$action = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$action = preg_replace('#^/api/v1/accounts#', '', $action);
$action = trim($action, '/');

if ($method === 'POST' && ($action === 'suspend' || $action === 'unsuspend')) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $username = trim($input['username'] ?? '');

    if (empty($username)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username is required']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, username, status FROM users WHERE username = ? AND role = 'cpanel'");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => "Account '{$username}' not found"]);
        exit;
    }

    $newStatus = $action === 'suspend' ? 'suspended' : 'active';
    $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $user['id']]);
    clear_customer_site_cache();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Account '{$username}' {$newStatus}",
        'data' => ['username' => $username, 'status' => $newStatus],
    ]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $username = trim($input['username'] ?? '');

    if (empty($username)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username is required']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, username, home_dir FROM users WHERE username = ? AND role = 'cpanel'");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => "Account '{$username}' not found"]);
        exit;
    }

    if ($user['home_dir'] && is_dir($user['home_dir'])) {
        @array_map('unlink', glob($user['home_dir'] . '/*'));
        @rmdir($user['home_dir']);
    }
    $symlink = __DIR__ . '/../../user_data/' . $username;
    if (is_link($symlink)) @unlink($symlink);

    $db->prepare("DELETE FROM dns_records WHERE user_id = ?")->execute([$user['id']]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);

    clear_customer_site_cache();

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => "Account '{$username}' terminated and deleted"]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $email = trim($input['email'] ?? '');
    $domain = strtolower(trim($input['domain'] ?? ''));
    $package_id = (int)($input['package_id'] ?? 0);
    $subdomain = strtolower(trim($input['subdomain'] ?? ''));

    $gd = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
    $server_domain = $gd['value'] ?? SITE_DOMAIN;

    if (!$domain) {
        if ($subdomain) {
            $domain = $subdomain . '.' . $server_domain;
        } elseif ($username) {
            $domain = $username . '.' . $server_domain;
        }
    }

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username and password are required']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username must be 3-32 alphanumeric characters']);
        exit;
    }

    $existing = $db->prepare("SELECT id FROM users WHERE username = ?");
    $existing->execute([$username]);
    if ($existing->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        exit;
    }

    $existing_domain = $db->prepare("SELECT id FROM users WHERE domain = ?");
    $existing_domain->execute([$domain]);
    if ($existing_domain->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => "Domain '{$domain}' is already assigned"]);
        exit;
    }

    $home = "/sdcard/Download/Hosting/user_data/{$username}";
    if (!is_dir($home)) mkdir($home, 0755, true);
    if (!is_dir("{$home}/public_html")) mkdir("{$home}/public_html", 0755, true);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, email, domain, role, package_id, home_dir) VALUES (?, ?, ?, ?, 'cpanel', ?, ?)");
    $stmt->execute([$username, $hash, $email, $domain, $package_id ?: null, $home]);

    file_put_contents("{$home}/public_html/index.php",
        "<!DOCTYPE html>\n<html><head><title>Welcome to {$domain}</title></head><body>\n" .
        "<h1>Welcome, " . htmlspecialchars($username) . "!</h1>\n" .
        "<p>Your hosting account <strong>" . htmlspecialchars($domain) . "</strong> is ready.</p>\n" .
        "</body></html>"
    );

    $symlink = __DIR__ . '/../../user_data/' . $username;
    if (!is_link($symlink) && !is_dir($symlink)) {
        @symlink($home . '/public_html', $symlink);
    }

    $server_ip = SERVER_IP;
    if ($server_ip && $server_ip !== '127.0.0.1') {
        $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl) VALUES (0, ?, '@', 'A', ?, 3600)")
           ->execute([$domain, $server_ip]);
    }

    if (CF_ENABLED) {
        $tunnel_cname = CF_TUNNEL_ID . '.cfargotunnel.com';
        $cf_a = cloudflare_create_dns('CNAME', $domain, $tunnel_cname, 3600, true);
        $cf_cname = cloudflare_create_dns('CNAME', "www.{$domain}", $domain, 3600, true);
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => "Account '{$username}' created",
        'data' => [
            'username' => $username,
            'domain' => $domain,
            'email' => $email,
            'home_dir' => $home,
        ],
    ]);
    exit;
}

if ($method === 'GET') {
    $accounts = $db->query(
        "SELECT u.id, u.username, u.email, u.domain, u.role, u.status, u.created_at, p.name as package_name, p.disk_quota
         FROM users u
         LEFT JOIN packages p ON u.package_id = p.id
         WHERE u.role = 'cpanel'
         ORDER BY u.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $accounts]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
