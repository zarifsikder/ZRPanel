<?php
/**
 * ZRPanel WordPress — async build worker.
 *
 * Usage: php wordpress_worker.php <site_id>
 * Runs the WP-CLI download/config/install steps in the background, appending
 * output to the site's build log and updating the site status.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config.php';
init_db();
$db = db();

$site_id = (int)($argv[1] ?? 0);
if ($site_id <= 0) {
    fwrite(STDERR, "Usage: php wordpress_worker.php <site_id>\n");
    exit(1);
}

function wp_worker_log($log, $msg) {
    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function wp_worker_run($cmd, $work_dir) {
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $work_dir, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return ['output' => '', 'exit' => 1];
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['output' => trim($stdout . ($stderr ? "\nSTDERR: " . $stderr : '')), 'exit' => $exit];
}

$stmt = $db->prepare("SELECT * FROM wordpress_sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$site) {
    fwrite(STDERR, "Site not found\n");
    exit(1);
}

$user_stmt = $db->prepare("SELECT username, home_dir FROM users WHERE id = ?");
$user_stmt->execute([$site['user_id']]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);
$home_dir = $user['home_dir'] ?? getenv('HOME');

$install_path = rtrim($home_dir, '/') . '/' . ltrim($site['install_path'], '/');
$log = rtrim($home_dir, '/') . '/builds/wp_build_' . $site_id . '.log';

$db->prepare("UPDATE wordpress_sites SET status = 'building' WHERE id = ?")->execute([$site_id]);

$wp_cli_path = $home_dir . '/wp-cli.phar';
if (!is_file($wp_cli_path)) {
    wp_worker_log($log, "ERROR: WP-CLI not found at {$wp_cli_path}");
    $db->prepare("UPDATE wordpress_sites SET status = 'failed' WHERE id = ?")->execute([$site_id]);
    exit(1);
}
$wp_cli_bin = PHP_BINARY . ' ' . escapeshellarg($wp_cli_path);

wp_worker_log($log, 'Building started for ' . $site['domain']);

// Download WordPress, create wp-config.php, then install it. The database
// host is 127.0.0.1 (TCP) because this platform's default mysqli socket path
// differs from the real one.
$steps = [
    'download' => $wp_cli_bin . ' core download --path=' . escapeshellarg($install_path) . ' --allow-root 2>&1',
    'config'   => $wp_cli_bin . ' config create --dbname=' . escapeshellarg($site['db_name']) . ' --dbuser=' . escapeshellarg($site['db_user']) . ' --dbpass=' . escapeshellarg($site['db_pass']) . ' --dbhost=127.0.0.1 --path=' . escapeshellarg($install_path) . ' --skip-check --allow-root 2>&1',
    'install'  => $wp_cli_bin . ' core install --url=' . escapeshellarg($site['site_url']) . ' --title=' . escapeshellarg($site['site_title'] ?: 'My WordPress Site') . ' --admin_user=' . escapeshellarg($site['admin_user']) . ' --admin_password=' . escapeshellarg($site['admin_password']) . ' --admin_email=' . escapeshellarg($site['admin_email']) . ' --path=' . escapeshellarg($install_path) . ' --skip-email --allow-root 2>&1',
];

foreach ($steps as $label => $cmd) {
    wp_worker_log($log, '== ' . strtoupper($label) . ' ==');
    $result = wp_worker_run($cmd, $home_dir);
    wp_worker_log($log, $result['output'] !== '' ? $result['output'] : '(no output)');
    if ($result['exit'] !== 0) {
        wp_worker_log($log, 'FAILED at step ' . strtoupper($label));
        $db->prepare("UPDATE wordpress_sites SET status = 'failed' WHERE id = ?")->execute([$site_id]);
        exit(1);
    }
}

$wp_version = '';
$ver_result = wp_worker_run($wp_cli_bin . ' core version --path=' . escapeshellarg($install_path) . ' --allow-root 2>&1', $home_dir);
if ($ver_result['exit'] === 0) $wp_version = trim($ver_result['output']);

$db->prepare("UPDATE wordpress_sites SET status = 'active', wp_version = ? WHERE id = ?")->execute([$wp_version, $site_id]);
wp_worker_log($log, 'Build complete.');
wp_worker_log($log, 'Site URL: ' . $site['site_url']);
wp_worker_log($log, 'Admin login: ' . $site['site_url'] . '/wp-admin (user: ' . $site['admin_user'] . ' / password: ' . $site['admin_password'] . ')');
