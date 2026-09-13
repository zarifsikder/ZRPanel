<?php

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

$stats = get_server_stats();

$pdo = db();
$accountsTotal = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'cpanel'")->fetchColumn();
$accountsActive = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'cpanel'")->fetchColumn();
$packages = $pdo->query("SELECT COUNT(*) FROM packages")->fetchColumn();

echo json_encode([
    'status' => 'ok',
    'server' => [
        'php_version'     => $stats['php_version'],
        'server_software' => $stats['server_software'],
        'uptime'          => $stats['uptime'],
        'cpu'             => $stats['cpu'],
        'disk'            => [
            'used'  => format_size($stats['disk_used']),
            'total' => format_size($stats['disk_total']),
            'pct'   => $stats['disk_total'] > 0 ? round($stats['disk_used'] / $stats['disk_total'] * 100, 1) : 0,
        ],
        'memory'          => [
            'used'  => format_size($stats['memory']['used']),
            'total' => format_size($stats['memory']['total']),
            'pct'   => $stats['memory']['total'] > 0 ? round($stats['memory']['used'] / $stats['memory']['total'] * 100, 1) : 0,
        ],
    ],
    'accounts' => [
        'total'  => (int)$accountsTotal,
        'active' => (int)$accountsActive,
    ],
    'packages'  => (int)$packages,
]);
