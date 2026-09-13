<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();

$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT u.home_dir, p.disk_quota FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$home = $user['home_dir'] ?? '/sdcard/Download/Hosting/public_html';
$force = isset($_GET['force']);
$stats = cached_dir_stats($home, 60, $force);
$total_files = $stats['total_files'];
$total_size = $stats['total_size'];
$breakdown = $stats['breakdown'];

header('Content-Type: application/json');
echo json_encode([
    'total_files' => $total_files,
    'total_size' => $total_size,
    'total_size_human' => format_size($total_size),
    'disk_quota' => $user['disk_quota'] ?: 0,
    'disk_quota_human' => $user['disk_quota'] ? format_size($user['disk_quota']) : 'Unlimited',
    'percentage' => $user['disk_quota'] > 0 ? min(round(($total_size / $user['disk_quota']) * 100), 100) : 0,
    'actual_percent' => min(round(($total_size / max(1, disk_total_space('/'))) * 100), 100),
    'breakdown' => [
        'html' => format_size($breakdown['html']),
        'images' => format_size($breakdown['images']),
        'scripts' => format_size($breakdown['scripts']),
        'data' => format_size($breakdown['data']),
        'other' => format_size($breakdown['other']),
    ]
]);
