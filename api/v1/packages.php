<?php

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');
init_db();
$db = db();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $packages = $db->query(
        "SELECT p.*, (SELECT COUNT(*) FROM users WHERE package_id = p.id AND role='cpanel') as user_count
         FROM packages p
         ORDER BY p.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $packages]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);