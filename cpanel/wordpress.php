<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('wordpress_toolkit');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

// Migration: async "Site Building" support (status + persisted build fields).
$wp_mig = $db->query("SELECT value FROM config WHERE key_name = 'wp_async_build_mig'")->fetchColumn();
if (!$wp_mig) {
    $db->exec("ALTER TABLE wordpress_sites MODIFY COLUMN status ENUM('active','inactive','updating','building','failed') NOT NULL DEFAULT 'active'");
    foreach (['site_url' => 'VARCHAR(500)', 'site_title' => 'VARCHAR(255)', 'db_pass' => 'VARCHAR(255)', 'admin_password' => 'VARCHAR(255)'] as $col => $type) {
        $db->exec("ALTER TABLE wordpress_sites ADD COLUMN IF NOT EXISTS `{$col}` {$type} DEFAULT NULL");
    }
    $db->prepare("INSERT INTO config (key_name, value) VALUES ('wp_async_build_mig', '1') ON DUPLICATE KEY UPDATE value = '1'")->execute();
}

$stmt = $db->prepare("SELECT username, home_dir, domain FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'user';
$home_dir = $user['home_dir'] ?? getenv('HOME');

$domain_stmt = $db->prepare("SELECT DISTINCT domain COLLATE utf8mb4_general_ci AS domain FROM addon_domains WHERE user_id = ? AND status = 'active' UNION SELECT DISTINCT CONCAT(subdomain, '.', domain) COLLATE utf8mb4_general_ci AS domain FROM subdomains WHERE user_id = ? UNION SELECT domain COLLATE utf8mb4_general_ci AS domain FROM users WHERE id = ?");
$domain_stmt->execute([$user_id, $user_id, $user_id]);
$domains = $domain_stmt->fetchAll(PDO::FETCH_COLUMN);
$domains = array_values(array_filter($domains, function ($d) { return $d !== null && $d !== ''; }));
$domains = array_unique($domains);
if (empty($domains)) {
    $domains = [$user['domain'] ?? SITE_DOMAIN];
}

$wp_cli_path = $home_dir . '/wp-cli.phar';
// On this platform the phar lives on Android FUSE storage where the exec bit
// cannot be set, so it is always invoked via the PHP interpreter.
$wp_cli_installed = is_file($wp_cli_path);
$wp_cli_bin = PHP_BINARY . ' ' . escapeshellarg($wp_cli_path);

function run_wp_command($cmd, $work_dir = null) {
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

$terminal_output = '';
$terminal_title = '';
$show_terminal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'install_wp_cli') {
        $cmd = 'curl -fsSL -o ' . escapeshellarg($wp_cli_path) . ' https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar 2>&1';
        $result = run_wp_command($cmd, $home_dir);
        if (is_file($wp_cli_path)) {
            $wp_cli_installed = true;
            flash('success', 'WP-CLI installed successfully');
        } else {
            flash('error', 'Failed to install WP-CLI');
        }
        redirect('/cpanel/wordpress.php');
    } elseif ($action === 'install') {
        if (!$wp_cli_installed) {
            flash('error', 'WP-CLI is not installed. Install it first.');
            redirect('/cpanel/wordpress.php');
        }

        $domain = trim($_POST['domain'] ?? '');
        $db_name = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['db_name'] ?? ''));
        $admin_user = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['admin_user'] ?? '');
        $admin_email = filter_var($_POST['admin_email'] ?? '', FILTER_VALIDATE_EMAIL);
        $site_title = trim($_POST['site_title'] ?? 'My WordPress Site');
        $site_id = (int)($_POST['site_id'] ?? 0);

        if (empty($domain) || empty($db_name) || empty($admin_user) || !$admin_email) {
            flash('error', 'All fields are required with valid values');
            redirect('/cpanel/wordpress.php');
        }

        // Determine the docroot for the chosen domain: subdomains and addon
        // domains get WordPress at their own document root, the primary
        // domain keeps the domain-named subdirectory layout.
        $domain_key = strtolower($domain);
        $sub = $db->prepare("SELECT document_root FROM subdomains WHERE user_id = ? AND CONCAT(subdomain, '.', domain) = ?");
        $sub->execute([$user_id, $domain_key]);
        $sub_row = $sub->fetch(PDO::FETCH_ASSOC);
        if ($sub_row) {
            $install_path = rtrim($sub_row['document_root'], '/');
            $site_url = 'http://' . $domain;
        } else {
            $addon = $db->prepare("SELECT document_root FROM addon_domains WHERE user_id = ? AND domain = ? AND status = 'active'");
            $addon->execute([$user_id, $domain_key]);
            $addon_row = $addon->fetch(PDO::FETCH_ASSOC);
            if ($addon_row) {
                $install_path = rtrim($addon_row['document_root'], '/');
                $site_url = 'http://' . $domain;
            } else {
                $subdir = preg_replace('/[^a-zA-Z0-9._-]/', '', $domain);
                $install_path = rtrim($home_dir, '/') . '/public_html/' . $subdir;
                $site_url = 'http://' . $domain . '/' . $subdir;
            }
        }

        // Use a fresh, dedicated DB user so we never clobber the password of an
        // existing application user, and grant it on the chosen database.
        $db_user_name = 'wp_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $db_pass = substr(bin2hex(random_bytes(16)), 0, 16);
        $admin_password = substr(bin2hex(random_bytes(8)), 0, 8);

        try {
            $db->exec("CREATE DATABASE IF NOT EXISTS `" . $db_name . "`");
            $db->exec("CREATE USER IF NOT EXISTS '" . $db_user_name . "'@'localhost' IDENTIFIED BY '" . $db_pass . "'");
            $db->exec("CREATE USER IF NOT EXISTS '" . $db_user_name . "'@'127.0.0.1' IDENTIFIED BY '" . $db_pass . "'");
            $db->exec("GRANT ALL PRIVILEGES ON `" . $db_name . "`.* TO '" . $db_user_name . "'@'localhost'");
            $db->exec("GRANT ALL PRIVILEGES ON `" . $db_name . "`.* TO '" . $db_user_name . "'@'127.0.0.1'");
            $db->exec("FLUSH PRIVILEGES");
        } catch (PDOException $e) {
            flash('error', 'Failed to create database user: ' . $e->getMessage());
            redirect('/cpanel/wordpress.php');
        }

        @mkdir($install_path, 0755, true);
        @mkdir($home_dir . '/builds', 0755, true);

        // Register the site first, then let the background worker run the
        // WP-CLI steps so the user lands on a live "Site Building" screen.
        $rel_path = str_replace($home_dir . '/', '', $install_path);
        if ($site_id > 0) {
            $db->prepare("UPDATE wordpress_sites SET domain = ?, install_path = ?, site_url = ?, site_title = ?, db_name = ?, db_user = ?, db_pass = ?, admin_user = ?, admin_email = ?, admin_password = ?, status = 'building' WHERE id = ? AND user_id = ?")
               ->execute([$domain, $rel_path, $site_url, $site_title, $db_name, $db_user_name, $db_pass, $admin_user, $admin_email, $admin_password, $site_id, $user_id]);
            $new_id = $site_id;
        } else {
            $db->prepare("INSERT INTO wordpress_sites (user_id, domain, install_path, site_url, site_title, db_name, db_user, db_pass, admin_user, admin_email, admin_password, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'building')")
               ->execute([$user_id, $domain, $rel_path, $site_url, $site_title, $db_name, $db_user_name, $db_pass, $admin_user, $admin_email, $admin_password]);
            $new_id = (int)$db->lastInsertId();
        }

        $build_log = $home_dir . '/builds/wp_build_' . $new_id . '.log';
        @file_put_contents($build_log, '[' . date('Y-m-d H:i:s') . "] Site building queued for {$domain}\n");

        $worker = __DIR__ . '/wordpress_worker.php';
        $cmd = 'setsid nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . $new_id . ' >/dev/null 2>&1 &';
        @exec($cmd);

        redirect('/cpanel/wordpress.php?build=' . $new_id);
    } elseif ($action === 'update_wp') {
        $id = (int)($_POST['id'] ?? 0);
        $site = $db->prepare("SELECT * FROM wordpress_sites WHERE id = ? AND user_id = ?");
        $site->execute([$id, $user_id]);
        $site = $site->fetch(PDO::FETCH_ASSOC);
        if ($site && $wp_cli_installed) {
            $full_path = $home_dir . '/' . $site['install_path'];
            $db->prepare("UPDATE wordpress_sites SET status = 'updating' WHERE id = ?")->execute([$id]);
            $cmd = $wp_cli_bin . ' core update --path=' . escapeshellarg($full_path) . ' --allow-root 2>&1';
            $result = run_wp_command($cmd, $home_dir);
            $new_ver = '';
            $ver_result = run_wp_command($wp_cli_bin . ' core version --path=' . escapeshellarg($full_path) . ' --allow-root 2>&1', $home_dir);
            if ($ver_result['exit'] === 0) $new_ver = trim($ver_result['output']);
            $db->prepare("UPDATE wordpress_sites SET status = 'active', wp_version = ? WHERE id = ?")->execute([$new_ver, $id]);
            $show_terminal = true;
            $terminal_title = "Update Output: " . h($site['domain']);
            $terminal_output = $result['output'];
            flash($result['exit'] === 0 ? 'success' : 'error', $result['exit'] === 0 ? 'WordPress updated' : 'Update failed');
        }
    } elseif ($action === 'check_updates') {
        $id = (int)($_POST['id'] ?? 0);
        $site = $db->prepare("SELECT * FROM wordpress_sites WHERE id = ? AND user_id = ?");
        $site->execute([$id, $user_id]);
        $site = $site->fetch(PDO::FETCH_ASSOC);
        if ($site && $wp_cli_installed) {
            $full_path = $home_dir . '/' . $site['install_path'];
            $cmd = $wp_cli_bin . ' core update-list --path=' . escapeshellarg($full_path) . ' --allow-root 2>&1';
            $result = run_wp_command($cmd, $home_dir);
            $show_terminal = true;
            $terminal_title = "Available Updates: " . h($site['domain']);
            $terminal_output = $result['output'] ?: 'Everything is up to date';
        }
    } elseif ($action === 'security_scan') {
        $id = (int)($_POST['id'] ?? 0);
        $site = $db->prepare("SELECT * FROM wordpress_sites WHERE id = ? AND user_id = ?");
        $site->execute([$id, $user_id]);
        $site = $site->fetch(PDO::FETCH_ASSOC);
        if ($site) {
            $full_path = $home_dir . '/' . $site['install_path'];
            $issues = [];
            $checks = [
                ['Debug Mode', $full_path . '/wp-config.php', '/WP_DEBUG.*true/i', 'Debug mode is enabled'],
                ['File Permissions', null, null, ''],
                ['WP-CLI Security', null, null, ''],
                ['Sensitive Files', null, null, ''],
                ['Directory Listing', null, null, ''],
            ];

            $debug_check = @file_get_contents($full_path . '/wp-config.php');
            if ($debug_check && preg_match('/WP_DEBUG.*true/i', $debug_check)) {
                $issues[] = '⚠ WP_DEBUG is enabled in wp-config.php';
            }
            if ($debug_check && preg_match('/WP_DEBUG_LOG.*true/i', $debug_check)) {
                $issues[] = '⚠ WP_DEBUG_LOG is enabled (may expose sensitive data)';
            }
            if ($debug_check && preg_match('/WP_DEBUG_DISPLAY.*true/i', $debug_check)) {
                $issues[] = '⚠ WP_DEBUG_DISPLAY is enabled (errors shown to users)';
            }

            $wp_config_perm = @fileperms($full_path . '/wp-config.php');
            if ($wp_config_perm && ($wp_config_perm & 0x0004)) {
                $issues[] = '⚠ wp-config.php is world-readable (permissions: ' . substr(sprintf('%o', $wp_config_perm), -4) . ')';
            }

            $xmlrpc = $full_path . '/xmlrpc.php';
            if (is_file($xmlrpc)) {
                $issues[] = '⚠ xmlrpc.php exists (potential attack vector)';
            }

            $readme = $full_path . '/readme.html';
            if (is_file($readme)) {
                $issues[] = '⚠ readme.html exists (exposes WordPress version)';
            }

            $htaccess = $full_path . '/.htaccess';
            if (!is_file($htaccess)) {
                $issues[] = '⚠ .htaccess file missing';
            }

            if ($wp_cli_installed) {
                $plugin_result = run_wp_command($wp_cli_bin . ' plugin list --status=active --path=' . escapeshellarg($full_path) . ' --allow-root 2>&1', $home_dir);
                if ($plugin_result['exit'] === 0) {
                    $issues[] = 'ℹ Active plugins: ' . substr_count($plugin_result['output'], "\n") . ' found';
                }
            }

            $ver_result = run_wp_command($wp_cli_bin . ' core version --path=' . escapeshellarg($full_path) . ' --allow-root 2>&1', $home_dir);
            $current_ver = $ver_result['exit'] === 0 ? trim($ver_result['output']) : 'unknown';

            $output = "WordPress Security Scan Report\n";
            $output .= "================================\n";
            $output .= "Domain: " . $site['domain'] . "\n";
            $output .= "Version: " . $current_ver . "\n";
            $output .= "Path: " . $full_path . "\n";
            $output .= "Scan Date: " . date('Y-m-d H:i:s') . "\n\n";
            if (empty($issues)) {
                $output .= "✓ No security issues found";
            } else {
                $output .= "Issues Found (" . count($issues) . "):\n";
                $output .= str_repeat("-", 40) . "\n";
                $output .= implode("\n", $issues) . "\n\n";
                $output .= "Recommendations:\n";
                $output .= "- Disable WP_DEBUG in production\n";
                $output .= "- Set wp-config.php permissions to 400\n";
                $output .= "- Remove xmlrpc.php and readme.html\n";
                $output .= "- Ensure .htaccess exists and is configured\n";
            }

            $db->prepare("UPDATE wordpress_sites SET last_scan = NOW() WHERE id = ?")->execute([$id]);
            $show_terminal = true;
            $terminal_title = "Security Scan: " . h($site['domain']);
            $terminal_output = $output;
        }
    } elseif ($action === 'backup') {
        $id = (int)($_POST['id'] ?? 0);
        $site = $db->prepare("SELECT * FROM wordpress_sites WHERE id = ? AND user_id = ?");
        $site->execute([$id, $user_id]);
        $site = $site->fetch(PDO::FETCH_ASSOC);
        if ($site) {
            $full_path = $home_dir . '/' . $site['install_path'];
            $backup_file = $home_dir . '/backups/' . $site['domain'] . '_wp_backup_' . date('Y-m-d_His') . '.tar.gz';
            @mkdir($home_dir . '/backups', 0755, true);
            $cmd = 'cd ' . escapeshellarg($full_path) . ' && tar czf ' . escapeshellarg($backup_file) . ' wp-content 2>&1';
            $result = run_wp_command($cmd, $home_dir);
            $show_terminal = true;
            $terminal_title = "Backup Output: " . h($site['domain']);
            $terminal_output = $result['exit'] === 0 ? "Backup created: " . basename($backup_file) . "\nLocation: " . $backup_file : $result['output'];
            flash($result['exit'] === 0 ? 'success' : 'error', $result['exit'] === 0 ? 'Backup created' : 'Backup failed');
        }
    } elseif ($action === 'delete_site') {
        $id = (int)($_POST['id'] ?? 0);
        $confirm = trim($_POST['confirm_delete'] ?? '');
        if ($confirm !== 'DELETE') {
            flash('error', 'Type DELETE to confirm');
            redirect('/cpanel/wordpress.php');
        }
        $del = $db->prepare("SELECT domain FROM wordpress_sites WHERE id = ? AND user_id = ?");
        $del->execute([$id, $user_id]);
        $site = $del->fetch(PDO::FETCH_ASSOC);
        if ($site) {
            $db->prepare("DELETE FROM wordpress_sites WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            flash('success', "WordPress site '{$site['domain']}' removed from panel");
        }
        redirect('/cpanel/wordpress.php');
    }
    redirect('/cpanel/wordpress.php');
}

$sites = $db->prepare("SELECT * FROM wordpress_sites WHERE user_id = ? ORDER BY created_at DESC");
$sites->execute([$user_id]);
$sites = $sites->fetchAll(PDO::FETCH_ASSOC);

// JSON poll endpoint used by the "Site Building" screen.
if (isset($_GET['build_poll'])) {
    $bid = (int)$_GET['build_poll'];
    $bs = $db->prepare("SELECT status FROM wordpress_sites WHERE id = ? AND user_id = ?");
    $bs->execute([$bid, $user_id]);
    $status = $bs->fetchColumn();
    $log_text = '';
    if ($bid > 0) {
        $log_file = $home_dir . '/builds/wp_build_' . $bid . '.log';
        if (is_file($log_file)) $log_text = (string)@file_get_contents($log_file);
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => $status ?: 'unknown', 'log' => $log_text]);
    exit;
}

$build_site = null;
if (isset($_GET['build']) && ctype_digit((string)$_GET['build'])) {
    $bid = (int)$_GET['build'];
    foreach ($sites as $s) {
        if ((int)$s['id'] === $bid) { $build_site = $s; break; }
    }
}

$nav = 'wordpress';
$page_title = 'WordPress Toolkit';
require_once __DIR__ . '/../templates/header.php';
?>

<?php if ($build_site): ?>
<style>
@keyframes wp-spin { to { transform: rotate(360deg); } }
.wp-spinner{width:54px;height:54px;border-radius:50%;border:4px solid var(--primary-border);border-top-color:var(--primary);animation:wp-spin 1s linear infinite;margin:0 auto 18px}
.wp-log-box{background:#0d1117;color:#c9d1d9;font-family:monospace;font-size:12px;padding:16px;max-height:420px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;line-height:1.7;border-radius:10px}
.wp-build-kv{max-width:440px;margin:0 auto 18px;text-align:left;background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:14px 18px}
.wp-build-kv .wbr{display:flex;justify-content:space-between;gap:12px;padding:4px 0}
.wp-build-kv .wbk{color:var(--text3);font-size:12px;flex-shrink:0}
.wp-build-kv .wbv{font-size:13px;color:var(--text);word-break:break-all;text-align:right}
.wp-build-kv code.chi{font-size:12px;background:var(--bg4);padding:2px 8px;border-radius:6px}
</style>
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-header"><h3><i data-lucide="rocket" class="lucide"></i> Site Builder</h3></div>
    <div class="card-body" style="padding:28px;text-align:center">
        <?php if ($build_site['status'] === 'building'): ?>
            <div class="wp-spinner"></div>
            <h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:var(--text)">Site Building</h3>
            <p style="margin:0 0 4px;font-size:14px;color:var(--text2)">WordPress is being installed on <strong style="color:var(--text)"><?= h($build_site['domain']) ?></strong></p>
            <p style="margin:0 auto;max-width:560px;font-size:13px;color:var(--text3)">This usually takes a few minutes. You can keep this page open — it updates automatically — or close it and check the status in WordPress Toolkit.</p>
        <?php elseif ($build_site['status'] === 'active'): ?>
            <div style="width:54px;height:54px;margin:0 auto 18px;border-radius:50%;background:var(--success-light);display:flex;align-items:center;justify-content:center"><i data-lucide="check" class="lucide" style="width:26px;height:26px;color:var(--success)"></i></div>
            <h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:var(--text)">WordPress Installed</h3>
            <p style="margin:0 0 16px;font-size:14px;color:var(--text2)">Your site is live on <strong style="color:var(--text)"><?= h($build_site['domain']) ?></strong> <?= $build_site['wp_version'] ? '· v' . h($build_site['wp_version']) : '' ?></p>
            <div class="wp-build-kv">
                <div class="wbr"><span class="wbk">Site URL</span><a href="<?= h($build_site['site_url']) ?>" target="_blank" rel="noopener" class="wbv" style="color:var(--primary);font-weight:600"><?= h($build_site['site_url']) ?></a></div>
                <div class="wbr"><span class="wbk">Admin URL</span><a href="<?= h($build_site['site_url']) ?>/wp-admin" target="_blank" rel="noopener" class="wbv" style="color:var(--primary);font-weight:600"><?= h($build_site['site_url']) ?>/wp-admin</a></div>
                <div class="wbr"><span class="wbk">Username</span><span class="wbv"><?= h($build_site['admin_user']) ?></span></div>
                <div class="wbr"><span class="wbk">Password</span><span class="wbv"><code class="chi"><?= h($build_site['admin_password']) ?></code></span></div>
            </div>
        <?php elseif ($build_site['status'] === 'failed'): ?>
            <div style="width:54px;height:54px;margin:0 auto 18px;border-radius:50%;background:var(--danger-light);display:flex;align-items:center;justify-content:center"><i data-lucide="alert-triangle" class="lucide" style="width:26px;height:26px;color:var(--danger)"></i></div>
            <h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:var(--text)">Build Failed</h3>
            <p style="margin:0 0 16px;font-size:14px;color:var(--text2)">The WordPress install for <strong style="color:var(--text)"><?= h($build_site['domain']) ?></strong> did not complete. Check the build log below for details.</p>
        <?php else: ?>
            <div style="width:54px;height:54px;margin:0 auto 18px;border-radius:50%;background:var(--bg3);display:flex;align-items:center;justify-content:center"><i data-lucide="globe" class="lucide" style="width:26px;height:26px;color:var(--text3)"></i></div>
            <h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:var(--text)"><?= h($build_site['domain']) ?></h3>
            <p style="margin:0 0 16px;font-size:14px;color:var(--text2)">Status: <span class="badge badge-suspended"><?= h($build_site['status']) ?></span></p>
        <?php endif; ?>

        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <?php if ($build_site['status'] === 'active'): ?>
                <a href="<?= h($build_site['site_url']) ?>" target="_blank" rel="noopener" class="btn btn-success"><i data-lucide="external-link" class="lucide"></i> Visit Site</a>
                <a href="<?= h($build_site['site_url']) ?>/wp-admin" target="_blank" rel="noopener" class="btn btn-primary"><i data-lucide="log-in" class="lucide"></i> WP Admin</a>
            <?php endif; ?>
            <a href="/cpanel/wordpress.php?build=<?= (int)$build_site['id'] ?>&log=1" class="btn btn-info"><i data-lucide="scroll-text" class="lucide"></i> Build Log</a>
            <a href="/cpanel/wordpress.php" class="btn btn-ghost"><i data-lucide="arrow-left" class="lucide"></i> Back to WordPress Toolkit</a>
        </div>

        <div id="wpBuildLogWrap" style="margin-top:20px;text-align:left;<?= isset($_GET['log']) ? '' : 'display:none' ?>">
            <div class="card-header" style="border-bottom:1px solid var(--border)"><h3 style="font-size:13px"><i data-lucide="terminal" class="lucide"></i> Build Log</h3></div>
            <pre class="wp-log-box" id="wpBuildLog"><?= h(is_file($home_dir . '/builds/wp_build_' . (int)$build_site['id'] . '.log') ? (string)@file_get_contents($home_dir . '/builds/wp_build_' . (int)$build_site['id'] . '.log') : 'Waiting for build to start…') ?></pre>
        </div>
    </div>
</div>

<script>
(function(){
    var siteId = <?= (int)$build_site['id'] ?>;
    var status = <?= json_encode($build_site['status']) ?>;
    var logBox = document.getElementById('wpBuildLog');
    if (status !== 'building' || !logBox) return;
    var timer = setInterval(function(){
        fetch('/cpanel/wordpress.php?build_poll=' + siteId, {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                logBox.textContent = d.log || logBox.textContent;
                logBox.scrollTop = logBox.scrollHeight;
                if (d.status && d.status !== 'building') {
                    clearInterval(timer);
                    setTimeout(function(){ location.reload(); }, 700);
                }
            })
            .catch(function(){});
    }, 2500);
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; exit; ?>
<?php endif; ?>

<?php
$wp_active = 0;
$wp_building = 0;
$wp_failed = 0;
foreach ($sites as $s) {
    if ($s['status'] === 'active') $wp_active++;
    elseif ($s['status'] === 'building') $wp_building++;
    elseif ($s['status'] === 'failed') $wp_failed++;
}
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="rocket" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">WordPress Toolkit</div>
        <div class="hero-desc">Install, update and manage WordPress sites with WP-CLI — run security scans, create backups and check for updates from one dashboard.</div>
    </div>
    <div class="hero-actions">
        <span class="badge <?= $wp_cli_installed ? 'badge-active' : 'badge-suspended' ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><span class="dot <?= $wp_cli_installed ? 'green' : 'gray' ?>"></span> WP-CLI <?= $wp_cli_installed ? 'ready' : 'missing' ?></span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($sites) ?></div>
            <div class="stat-label">WordPress Sites</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">registered on this account</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $wp_active ?></div>
            <div class="stat-label">Active Sites</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">live and serving content</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-2">
        <div class="stat-icon icon-orange"><i data-lucide="loader" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $wp_building ?></div>
            <div class="stat-label">Building Now</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $wp_failed ?> failed &middot; queued installs</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-3">
        <div class="stat-icon icon-purple"><i data-lucide="terminal" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $wp_cli_installed ? 'Ready' : 'Missing' ?></div>
            <div class="stat-label">WP-CLI Runtime</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">required for installs &amp; updates</div>
        </div>
    </div>
</div>

<div class="tip-card tip-purple fade-in-delay-2">
    <i data-lucide="info" class="lucide"></i>
    <div class="tip-body"><strong>Requirements.</strong> The target domain must exist as your primary domain, a subdomain or an addon domain. WP-CLI and MySQL are used behind the scenes, and the database is created automatically &mdash; the credentials you pick are stored so the panel can manage the site later.</div>
</div>

<?php if (!$wp_cli_installed): ?>
<div class="tip-card tip-red fade-in">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body">
        <strong>WP-CLI is not installed.</strong> It is required to install and manage WordPress sites. Install it once and you will be able to use the toolkit below.
        <form method="POST" style="margin:10px 0 0">
            <input type="hidden" name="action" value="install_wp_cli">
            <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="download" class="lucide"></i> Install WP-CLI</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in">
    <div class="card-header"><h3><i data-lucide="plus" class="lucide"></i> Install WordPress</h3></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="install">
            <div class="form-group">
                <label>Domain</label>
                <select name="domain" required>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= h($d) ?>"><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><i data-lucide="globe" class="lucide"></i> Where WordPress will be installed</div>
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" required pattern="[a-zA-Z0-9_]{3,64}" placeholder="wp_yourdb" value="wp_<?= $username ?>_<?= substr(md5(uniqid('', true)), 0, 6) ?>">
                <div class="form-hint"><i data-lucide="database" class="lucide"></i> Created automatically. Letters, numbers and underscores only.</div>
            </div>
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="admin_user" required placeholder="admin" pattern="[a-zA-Z0-9_]{3,32}">
                <div class="form-hint"><i data-lucide="user" class="lucide"></i> Login for the WordPress dashboard</div>
            </div>
            <div class="form-group">
                <label>Admin Email</label>
                <input type="email" name="admin_email" required placeholder="admin@example.com" value="<?= h($_SESSION['email'] ?? '') ?>">
                <div class="form-hint"><i data-lucide="mail" class="lucide"></i> Receives account notifications</div>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Site Title</label>
                <input type="text" name="site_title" value="My WordPress Site" placeholder="My WordPress Site">
                <div class="form-hint"><i data-lucide="type" class="lucide"></i> Shown in the browser tab and header</div>
            </div>
            <div class="form-group" style="grid-column:1/-1;margin-bottom:0">
                <button type="submit" class="btn btn-primary" style="width:100%" <?= !$wp_cli_installed ? 'disabled title="Install WP-CLI first"' : '' ?>><i data-lucide="rocket" class="lucide"></i> Install WordPress</button>
            </div>
        </form>
    </div>
</div>

<?php if ($show_terminal && $terminal_output !== ''): ?>
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-header">
        <h3><i data-lucide="terminal" class="lucide"></i> <?= $terminal_title ?></h3>
        <a href="/cpanel/wordpress.php" class="btn btn-sm btn-danger"><i data-lucide="x" class="lucide"></i> Close</a>
    </div>
    <div class="card-body" style="padding:0">
        <div style="background:#0d1117;color:#c9d1d9;font-family:monospace;font-size:12px;padding:16px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;line-height:1.7"><?= h($terminal_output) ?></div>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="layout-grid" class="lucide"></i> WordPress Sites (<?= count($sites) ?>)</h3></div>
    <?php if (!empty($sites)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="wpSearch" placeholder="Search sites..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="wpCount"><?= count($sites) ?> site<?= count($sites) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($sites) ? '14px' : '16px' ?>">
        <?php if (empty($sites)): ?>
            <div class="empty-state" style="padding:14px 0 18px">
                <div class="empty-state-icon"><i data-lucide="globe" class="lucide"></i></div>
                <strong>No WordPress sites installed yet</strong>
                <p>Use the installer above to create your first site — the database and files are set up for you automatically.</p>
            </div>
        <?php else: ?>
        <div class="wp-list">
            <?php foreach ($sites as $site): ?>
                <div class="wp-row" data-name="<?= h(strtolower($site['domain'] . ' ' . $site['wp_version'] . ' ' . $site['admin_user'] . ' ' . $site['db_name'] . ' ' . $site['status'])) ?>">
                    <span class="wp-ic <?= $site['status'] === 'failed' ? 'is-red' : ($site['status'] === 'inactive' ? 'is-off' : '') ?>"><i data-lucide="globe" class="lucide"></i></span>
                    <div class="wp-main">
                        <div class="wp-name"><?= h($site['domain']) ?></div>
                        <div class="wp-meta">
                            <?php if ($site['status'] === 'active'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                            <?php elseif ($site['status'] === 'building'): ?>
                                <span class="badge badge-pending" style="display:inline-flex;align-items:center;gap:5px"><i data-lucide="loader" class="lucide" style="width:11px;height:11px"></i> Building</span>
                            <?php elseif ($site['status'] === 'failed'): ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><i data-lucide="x-circle" class="lucide" style="width:11px;height:11px"></i> Failed</span>
                            <?php elseif ($site['status'] === 'updating'): ?>
                                <span class="badge badge-pending" style="display:inline-flex;align-items:center;gap:5px"><i data-lucide="loader" class="lucide" style="width:11px;height:11px"></i> Updating</span>
                            <?php else: ?>
                                <span class="badge badge-suspended">Inactive</span>
                            <?php endif; ?>
                            <span class="chip-mono"><?= $site['wp_version'] ? 'v' . h($site['wp_version']) : '—' ?></span>
                            <span><i data-lucide="user" class="lucide"></i><?= h($site['admin_user']) ?></span>
                            <code class="chip-mono"><?= h($site['db_name']) ?></code>
                            <span>Scan <?= $site['last_scan'] ? date('M d, H:i', strtotime($site['last_scan'])) : 'never' ?></span>
                        </div>
                    </div>
                    <div class="wp-actions">
                        <a href="/cpanel/wordpress.php?build=<?= (int)$site['id'] ?>" class="btn btn-sm btn-info" title="Build Log / Status"><i data-lucide="scroll-text" class="lucide"></i></a>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="update_wp">
                            <input type="hidden" name="id" value="<?= $site['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Update WordPress" <?= !$wp_cli_installed ? 'disabled' : '' ?>><i data-lucide="refresh-cw" class="lucide"></i></button>
                        </form>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="check_updates">
                            <input type="hidden" name="id" value="<?= $site['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-info" title="Check Updates" <?= !$wp_cli_installed ? 'disabled' : '' ?>><i data-lucide="list-checks" class="lucide"></i></button>
                        </form>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="security_scan">
                            <input type="hidden" name="id" value="<?= $site['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-warning" title="Security Scan"><i data-lucide="shield-check" class="lucide"></i></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Create a backup of wp-content for this site?')">
                            <input type="hidden" name="action" value="backup">
                            <input type="hidden" name="id" value="<?= $site['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-primary" title="Backup wp-content"><i data-lucide="archive" class="lucide"></i></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Type DELETE in the next prompt to confirm removal')">
                            <input type="hidden" name="action" value="delete_site">
                            <input type="hidden" name="id" value="<?= $site['id'] ?>">
                            <input type="hidden" name="confirm_delete" value="DELETE">
                            <button type="submit" class="btn btn-sm btn-danger" title="Remove from Panel"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.wp-list{display:flex;flex-direction:column;gap:10px}
.wp-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.wp-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.wp-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0059b3,#0073e6 55%,#1e8ff0);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.wp-ic.is-red{background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444);box-shadow:0 4px 10px rgba(220,38,38,.18)}
.wp-ic.is-off{background:linear-gradient(135deg,#64748b,#94a3b8 55%,#cbd5e1);box-shadow:0 4px 10px rgba(148,163,184,.18)}
.wp-ic .lucide{width:19px;height:19px}
.wp-main{min-width:0;flex:1}
.wp-name{font-weight:700;color:var(--text);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wp-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.wp-meta .badge{font-size:10px;padding:3px 8px}
.wp-meta .lucide{width:12px;height:12px;vertical-align:-2px}
.wp-actions{display:flex;gap:6px;flex-shrink:0}
.wp-actions form{display:inline-flex}
@media(max-width:720px){
  .wp-row{flex-wrap:wrap}
  .wp-main{flex-basis:100%}
  .wp-actions{width:100%;flex-wrap:wrap}
  .wp-actions form{flex:1}
  .wp-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var input = document.getElementById('wpSearch');
    var count = document.getElementById('wpCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.wp-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' site' + (shown === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>