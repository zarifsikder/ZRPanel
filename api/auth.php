<?php

require_once __DIR__ . '/../config.php';

function authenticate_api_request() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    if (empty($key)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API key required. Provide via X-API-Key header or api_key query parameter.']);
        exit;
    }

    try {
        $pdo = db();
        $hash = hash('sha256', $key);
        $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE key_hash = ? AND status = 'active'");
        $stmt->execute([$hash]);
        $apiKey = $stmt->fetch();

        if (!$apiKey) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid or revoked API key']);
            exit;
        }

        if ($apiKey['expires_at'] && strtotime($apiKey['expires_at']) < time()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'API key has expired']);
            exit;
        }

        $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$apiKey['id']]);

        $cached = $apiKey;
        return $cached;
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication service unavailable']);
        exit;
    }
}
