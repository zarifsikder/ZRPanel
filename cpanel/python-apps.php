<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('python_apps');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir, domain FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'user';
$home_dir = $user['home_dir'] ?? getenv('HOME');

$domains = [$user['domain'] ?? SITE_DOMAIN];
$addon = $db->prepare("SELECT domain FROM addon_domains WHERE user_id = ?");
$addon->execute([$user_id]);
foreach ($addon->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $domains[] = $row['domain'];
}

function python_is_running($pid_file) {
    if (!file_exists($pid_file)) return false;
    $pid = (int)trim(file_get_contents($pid_file));
    if ($pid <= 0) return false;
    $result = @file_get_contents("/proc/{$pid}/cmdline");
    if ($result !== false && (strpos($result, 'python') !== false || strpos($result, 'gunicorn') !== false)) return true;
    @unlink($pid_file);
    return false;
}

function python_start_app($app, $home_dir) {
    $pid_dir = $home_dir . '/.pythonapps';
    @mkdir($pid_dir, 0755, true);
    $pid_file = $pid_dir . '/' . $app['id'] . '.pid';
    $log_dir = $home_dir . '/logs';
    @mkdir($log_dir, 0755, true);
    $log_file = $log_dir . '/' . $app['app_name'] . '.log';

    $app_dir = rtrim($home_dir, '/') . '/' . ltrim($app['root_dir'], '/');
    if (!is_dir($app_dir)) @mkdir($app_dir, 0755, true);

    $wsgi = $app['wsgi_file'] ?? 'app.py';
    $cmd = "cd " . escapeshellarg($app_dir) . " && PORT=" . (int)$app['port'] . " setsid nohup python3 " . escapeshellarg($wsgi) . " < /dev/null > " . escapeshellarg($log_file) . " 2>&1 & echo $!";
    $pid = trim(shell_exec($cmd));

    if (!empty($pid) && ctype_digit($pid)) {
        file_put_contents($pid_file, $pid);
        return true;
    }
    return false;
}

function python_stop_app($pid_file) {
    if (!file_exists($pid_file)) return true;
    $pid = (int)trim(file_get_contents($pid_file));
    if ($pid > 0) {
        posix_kill($pid, SIGTERM);
        usleep(500000);
        if (file_exists("/proc/{$pid}/cmdline")) posix_kill($pid, SIGKILL);
    }
    @unlink($pid_file);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $app_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['app_name'] ?? '');
        $domain = trim($_POST['domain'] ?? '');
        $port = max(1024, min(65535, (int)($_POST['port'] ?? 8000)));
        $python_version = preg_replace('/[^0-9.]/', '', $_POST['python_version'] ?? '3.11');
        $root_dir = trim($_POST['root_dir'] ?? '');
        $wsgi_file = trim($_POST['wsgi_file'] ?? 'app.py');

        if (empty($app_name) || empty($domain) || empty($root_dir)) {
            flash('error', 'App name, domain, and root directory are required');
            redirect('/cpanel/python-apps.php');
        }

        $exists = $db->prepare("SELECT id FROM python_apps WHERE app_name = ? AND user_id = ?");
        $exists->execute([$app_name, $user_id]);
        if ($exists->fetch()) {
            flash('error', "An app named '{$app_name}' already exists");
            redirect('/cpanel/python-apps.php');
        }

        $port_check = $db->prepare("SELECT id FROM python_apps WHERE port = ? AND user_id = ?");
        $port_check->execute([$port, $user_id]);
        if ($port_check->fetch()) {
            flash('error', "Port {$port} is already in use");
            redirect('/cpanel/python-apps.php');
        }

        $db->prepare("INSERT INTO python_apps (user_id, app_name, domain, port, python_version, root_dir, wsgi_file, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'stopped')")
           ->execute([$user_id, $app_name, $domain, $port, $python_version, $root_dir, $wsgi_file]);
        flash('success', "Python app '{$app_name}' deployed successfully");
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE python_apps SET domain = ?, port = ?, python_version = ?, root_dir = ?, wsgi_file = ? WHERE id = ? AND user_id = ?")
           ->execute([trim($_POST['domain'] ?? ''), max(1024, min(65535, (int)($_POST['port'] ?? 8000))), preg_replace('/[^0-9.]/', '', $_POST['python_version'] ?? '3.11'), trim($_POST['root_dir'] ?? ''), trim($_POST['wsgi_file'] ?? 'app.py'), $id, $user_id]);
        flash('success', 'App settings updated');
    } elseif ($action === 'start') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM python_apps WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($app && python_start_app($app, $home_dir)) {
            $db->prepare("UPDATE python_apps SET status = 'running' WHERE id = ?")->execute([$id]);
            flash('success', 'Application started');
        } else {
            flash('error', 'Failed to start application');
        }
    } elseif ($action === 'stop') {
        $id = (int)($_POST['id'] ?? 0);
        python_stop_app($home_dir . '/.pythonapps/' . $id . '.pid');
        $db->prepare("UPDATE python_apps SET status = 'stopped' WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'Application stopped');
    } elseif ($action === 'restart') {
        $id = (int)($_POST['id'] ?? 0);
        python_stop_app($home_dir . '/.pythonapps/' . $id . '.pid');
        usleep(500000);
        $stmt = $db->prepare("SELECT * FROM python_apps WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($app && python_start_app($app, $home_dir)) {
            $db->prepare("UPDATE python_apps SET status = 'running' WHERE id = ?")->execute([$id]);
            flash('success', 'Application restarted');
        } else {
            flash('error', 'Restart failed');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        python_stop_app($home_dir . '/.pythonapps/' . $id . '.pid');
        $del = $db->prepare("SELECT app_name FROM python_apps WHERE id = ? AND user_id = ?");
        $del->execute([$id, $user_id]);
        $app = $del->fetch(PDO::FETCH_ASSOC);
        if ($app) {
            $db->prepare("DELETE FROM python_apps WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            @unlink($home_dir . '/logs/' . $app['app_name'] . '.log');
            flash('success', "App '{$app['app_name']}' deleted");
        }
    }
    redirect('/cpanel/python-apps.php');
}

$apps = $db->prepare("SELECT * FROM python_apps WHERE user_id = ? ORDER BY created_at DESC");
$apps->execute([$user_id]);
$apps = $apps->fetchAll(PDO::FETCH_ASSOC);

foreach ($apps as &$app) {
    $pid_file = $home_dir . '/.pythonapps/' . $app['id'] . '.pid';
    $running = python_is_running($pid_file);
    $actual_status = $running ? 'running' : 'stopped';
    if ($app['status'] !== $actual_status) {
        $db->prepare("UPDATE python_apps SET status = ? WHERE id = ?")->execute([$actual_status, $app['id']]);
        $app['status'] = $actual_status;
    }
    $app['pid'] = $running ? (int)trim(file_get_contents($pid_file)) : null;
}

$used_ports = array_column($apps, 'port');
$next_port = 8000;
while (in_array($next_port, $used_ports)) $next_port++;

$apps_running = 0;
$apps_stopped = 0;
foreach ($apps as $a) {
    if ($a['status'] === 'running') $apps_running++;
    else $apps_stopped++;
}

$logs_content = '';
$logs_app = '';
if (isset($_GET['logs']) && $_GET['logs'] !== '') {
    $log_id = (int)$_GET['logs'];
    $log_stmt = $db->prepare("SELECT app_name FROM python_apps WHERE id = ? AND user_id = ?");
    $log_stmt->execute([$log_id, $user_id]);
    $log_app = $log_stmt->fetch(PDO::FETCH_ASSOC);
    if ($log_app) {
        $logs_app = h($log_app['app_name']);
        $log_file = $home_dir . '/logs/' . $log_app['app_name'] . '.log';
        if (is_file($log_file)) {
            $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) { $lines = array_slice($lines, -100); $logs_content = h(implode("\n", $lines)); }
        }
    }
}

$edit_app = null;
if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $edit_id = (int)$_GET['edit'];
    $edit_stmt = $db->prepare("SELECT * FROM python_apps WHERE id = ? AND user_id = ?");
    $edit_stmt->execute([$edit_id, $user_id]);
    $edit_app = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}

$nav = 'pythonapps';
$page_title = 'Python Applications';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="code" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Python Applications</div>
        <div class="hero-desc">Deploy Python / WSGI apps (Flask, Django, FastAPI) as background processes with PID tracking and log capture.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><i data-lucide="layers" class="lucide"></i> <?= count($apps) ?> app<?= count($apps) === 1 ? '' : 's' ?></span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="layers" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($apps) ?></div>
            <div class="stat-label">Total Apps</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">deployed under your account</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="play-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $apps_running ?></div>
            <div class="stat-label">Running</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">active background processes</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="pause-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $apps_stopped ?></div>
            <div class="stat-label">Stopped</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">not currently running</div>
        </div>
    </div>
</div>

<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-header"><h3><i data-lucide="plus" class="lucide"></i> Deploy New App</h3></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>App Name</label>
                <input type="text" name="app_name" required placeholder="e.g. my-flask-app" pattern="[a-zA-Z0-9_-]{2,50}">
            </div>
            <div class="form-group">
                <label>Domain</label>
                <select name="domain" required>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= h($d) ?>"><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="port" required min="1024" max="65535" value="<?= $next_port ?>">
            </div>
            <div class="form-group">
                <label>Python Version</label>
                <select name="python_version">
                    <?php foreach (['3.9','3.10','3.11','3.12'] as $v): ?>
                        <option value="<?= $v ?>" <?= $v === '3.11' ? 'selected' : '' ?>>Python <?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Root Directory</label>
                <input type="text" name="root_dir" required value="public_html/">
            </div>
            <div class="form-group">
                <label>WSGI File</label>
                <input type="text" name="wsgi_file" value="app.py" placeholder="app.py">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px"><i data-lucide="rocket" class="lucide" style="width:16px;height:16px"></i> Deploy App</button>
            </div>
        </form>
    </div>
</div>

<?php if ($logs_content !== ''): ?>
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-header">
        <h3><i data-lucide="scroll-text" class="lucide"></i> Logs: <?= $logs_app ?></h3>
        <a href="/cpanel/python-apps.php" class="btn btn-sm btn-danger" style="text-decoration:none;margin-left:auto"><i data-lucide="x" class="lucide" style="width:12px;height:12px"></i> Close</a>
    </div>
    <div class="card-body" style="padding:0">
        <div style="background:#0d1117;color:#3498db;font-family:monospace;font-size:12px;padding:16px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;line-height:1.7"><?= $logs_content ?: '<span style="color:#666">No log output yet</span>' ?></div>
    </div>
</div>
<?php endif; ?>

<?php if ($edit_app): ?>
<div class="card fade-in" style="margin-bottom:20px;border:1.5px solid var(--accent)">
    <div class="card-header">
        <h3><i data-lucide="pencil" class="lucide"></i> Edit: <?= h($edit_app['app_name']) ?></h3>
        <a href="/cpanel/python-apps.php" class="btn btn-sm btn-danger" style="text-decoration:none;margin-left:auto">Cancel</a>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $edit_app['id'] ?>">
            <div class="form-group">
                <label>Domain</label>
                <select name="domain" required>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= h($d) ?>" <?= $d === $edit_app['domain'] ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="port" required min="1024" max="65535" value="<?= $edit_app['port'] ?>">
            </div>
            <div class="form-group">
                <label>Python Version</label>
                <select name="python_version">
                    <?php foreach (['3.9','3.10','3.11','3.12'] as $v): ?>
                        <option value="<?= $v ?>" <?= $v === $edit_app['python_version'] ? 'selected' : '' ?>>Python <?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Root Directory</label>
                <input type="text" name="root_dir" required value="<?= h($edit_app['root_dir']) ?>">
            </div>
            <div class="form-group">
                <label>WSGI File</label>
                <input type="text" name="wsgi_file" value="<?= h($edit_app['wsgi_file']) ?>">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide" style="width:16px;height:16px"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="layers" class="lucide"></i> Your Applications (<?= count($apps) ?>)</h3></div>
    <?php if (!empty($apps)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="appSearch" placeholder="Search apps, domains, ports..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="appCount"><?= count($apps) ?> app<?= count($apps) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($apps) ? '14px' : '16px' ?>">
        <?php if (empty($apps)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="code" class="lucide"></i></div>
                <strong>No Python applications deployed yet</strong>
                <p>Deploy your first app above &mdash; it runs as a background process with its own PID and log file.</p>
            </div>
        <?php else: ?>
        <div class="app-list">
            <?php foreach ($apps as $app): ?>
                <div class="app-row" data-name="<?= h(strtolower($app['app_name'] . ' ' . $app['domain'] . ' ' . $app['port'] . ' ' . $app['python_version'] . ' ' . $app['status'])) ?>">
                    <span class="app-ic <?= $app['status'] === 'running' ? '' : 'is-off' ?>"><i data-lucide="code" class="lucide"></i></span>
                    <div class="app-main">
                        <div class="app-name">
                            <strong><?= h($app['app_name']) ?></strong>
                            <?php if ($app['status'] === 'running'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Running</span>
                            <?php else: ?>
                                <span class="badge badge-pending" style="display:inline-flex;align-items:center;gap:5px"><span class="dot amber"></span> Stopped</span>
                            <?php endif; ?>
                        </div>
                        <div class="app-meta">
                            <code class="chip-mono"><?= h($app['domain']) ?>:<?= (int)$app['port'] ?></code>
                            <span class="badge badge-blue" style="font-size:10px"><?= h($app['python_version']) ?></span>
                            <span>PID <?= $app['pid'] ?? '&mdash;' ?></span>
                        </div>
                    </div>
                    <div class="app-actions">
                        <?php if ($app['status'] === 'running'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="stop">
                                <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-warning" title="Stop"><i data-lucide="square" class="lucide"></i></button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="restart">
                                <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-info" title="Restart"><i data-lucide="rotate-ccw" class="lucide"></i></button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="start">
                                <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success" title="Start"><i data-lucide="play" class="lucide"></i></button>
                            </form>
                        <?php endif; ?>
                        <a href="?logs=<?= $app['id'] ?>" class="btn btn-sm btn-info" title="View logs" style="text-decoration:none;display:inline-flex;align-items:center"><i data-lucide="scroll-text" class="lucide"></i></a>
                        <a href="?edit=<?= $app['id'] ?>" class="btn btn-sm btn-warning" title="Edit settings" style="text-decoration:none;display:inline-flex;align-items:center"><i data-lucide="pencil" class="lucide"></i></a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this application permanently?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $app['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.app-list{display:flex;flex-direction:column;gap:10px}
.app-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.app-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.app-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#2563eb,#0073e6 55%,#3b82f6);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.app-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.app-ic .lucide{width:19px;height:19px}
.app-main{min-width:0;flex:1}
.app-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.app-name strong{font-weight:700;font-size:13px;color:var(--text)}
.app-name .badge{font-size:10px;padding:3px 8px}
.app-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.app-meta .chip-mono{font-size:11px}
.app-actions{display:flex;gap:6px;align-items:center;flex-shrink:0;flex-wrap:wrap}
@media(max-width:720px){
  .app-row{flex-wrap:wrap}
  .app-main{flex-basis:100%}
  .app-actions{width:100%;flex-wrap:wrap;justify-content:flex-end}
}
</style>

<script>
(function () {
    var q = document.getElementById('appSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.app-row'));
        var c = document.getElementById('appCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' app' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
