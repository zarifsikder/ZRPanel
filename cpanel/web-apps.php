<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../webapps.php';
require_login();
require_feature('web_apps');
init_db();
$db = db();
$user_id = (int)$_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir, domain FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $app_name = trim($_POST['app_name'] ?? '');
        $slug = wa_slugify($app_name);
        $base_path = wa_base_path($slug);
        $source_type = $_POST['source_type'] ?? 'zip';
        $repo_url = trim($_POST['repo_url'] ?? '');
        $node_version = preg_replace('/[^0-9.]/', '', $_POST['node_version'] ?? '') ?: null;
        $env_vars = trim($_POST['env_vars'] ?? '');

        if ($app_name === '') {
            flash('error', 'App name is required');
            redirect('/cpanel/web-apps.php');
        }
        if (in_array($slug, wa_reserved_slugs(), true)) {
            flash('error', "'{$slug}' is a reserved path — choose a different app name");
            redirect('/cpanel/web-apps.php');
        }
        if (strlen($slug) < 2) {
            flash('error', 'App name is too short');
            redirect('/cpanel/web-apps.php');
        }

        $dup = $db->prepare("SELECT id FROM web_apps WHERE base_path = ?");
        $dup->execute([$base_path]);
        if ($dup->fetch()) {
            flash('error', "A web app at '{$base_path}' already exists");
            redirect('/cpanel/web-apps.php');
        }

        $appDir = wa_app_dir($user, $slug);
        if (is_dir($appDir)) {
            flash('error', "The directory '{$appDir}' already exists — choose another name or remove it first");
            redirect('/cpanel/web-apps.php');
        }
        if (!@mkdir($appDir, 0755, true)) {
            flash('error', 'Could not create app directory');
            redirect('/cpanel/web-apps.php');
        }

        $port = wa_alloc_port($user_id);

        $db->prepare("INSERT INTO web_apps (user_id, app_name, base_path, deploy_type, repo_url, node_version, port, env_vars, build_status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'none')")
           ->execute([$user_id, $slug, $base_path, $source_type, $repo_url, $node_version, $port, $env_vars]);
        $appId = (int)$db->lastInsertId();

        $error = null;
        if ($source_type === 'git') {
            if ($repo_url === '') {
                $error = 'A repository URL is required for Git deployment';
            } else {
                $error = wa_git_clone($repo_url, $appDir);
            }
        } elseif ($source_type === 'zip') {
            if (empty($_FILES['zip_file']['tmp_name']) || !is_uploaded_file($_FILES['zip_file']['tmp_name'])) {
                $error = 'Please choose a ZIP file to upload';
            } else {
                $error = wa_extract_zip($_FILES['zip_file']['tmp_name'], $appDir);
            }
        } else {
            $error = 'Unknown source type';
        }

        if ($error !== null) {
            $db->prepare("DELETE FROM web_apps WHERE id = ?")->execute([$appId]);
            @exec('rm -rf ' . escapeshellarg($appDir) . ' 2>/dev/null');
            flash('error', $error);
            redirect('/cpanel/web-apps.php');
        }

        wa_write_env($appDir, $env_vars);

        $detected = wa_detect($appDir, $base_path);
        if ($detected === null) {
            $db->prepare("DELETE FROM web_apps WHERE id = ?")->execute([$appId]);
            @exec('rm -rf ' . escapeshellarg($appDir) . ' 2>/dev/null');
            flash('error', 'No supported project detected (need package.json or index.html in the upload root)');
            redirect('/cpanel/web-apps.php');
        }

        $db->prepare("UPDATE web_apps SET framework = ?, mode = ?, package_manager = ?, install_command = ?,
                      build_command = ?, start_command = ?, static_dir = ?, node_version = ? WHERE id = ?")
           ->execute([$detected['framework'], $detected['mode'], $detected['package_manager'],
                      $detected['install_command'], $detected['build_command'], $detected['start_command'],
                      $detected['static_dir'], $node_version, $appId]);

        wa_launch_build($appId);
        flash('success', "App '{$slug}' deployed at {$base_path} — building in the background");
    } elseif ($action === 'start') {
        $id = (int)($_POST['id'] ?? 0);
        $app = wa_load_app($id);
        if ($app) {
            wa_write_env(wa_app_dir($app, $app['app_name']), $app['env_vars']);
            $userTmp = ['username' => $app['username'], 'home_dir' => $app['home_dir']];
            $runDir = ($app['mode'] === 'server') ? wa_workspace_dir($userTmp, $app['app_name']) : wa_app_dir($userTmp, $app['app_name']);
            if (!is_dir($runDir)) {
                flash('error', 'No build found — rebuilding this app in the background, then it will start automatically');
                wa_launch_build($id);
            } elseif (wa_pm2_start($app)) {
                flash('success', 'Application started');
            } else {
                flash('error', 'Failed to start application — check the logs');
            }
        }
    } elseif ($action === 'stop') {
        $id = (int)($_POST['id'] ?? 0);
        $app = wa_load_app($id);
        if ($app) { wa_pm2_stop($app); flash('success', 'Application stopped'); }
    } elseif ($action === 'restart') {
        $id = (int)($_POST['id'] ?? 0);
        $app = wa_load_app($id);
        if ($app) {
            if (wa_pm2_restart($app)) flash('success', 'Application restarted');
            else flash('error', 'Restart failed');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $app = wa_load_app($id);
        if ($app) {
            wa_pm2_delete($app);
            $db->prepare("DELETE FROM web_apps WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            @exec('rm -rf ' . escapeshellarg(wa_app_dir($app, $app['app_name'])) . ' 2>/dev/null');
            flash('success', "App '{$app['app_name']}' deleted");
        }
    } elseif ($action === 'rebuild') {
        $id = (int)($_POST['id'] ?? 0);
        $app = wa_load_app($id);
        if ($app) {
            wa_pm2_stop($app);
            wa_launch_build($id);
            flash('success', 'Rebuild queued in the background');
        }
    } elseif ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $app = wa_load_app($id);
        if ($app) {
            $env_vars = trim($_POST['env_vars'] ?? '');
            $install_command = trim($_POST['install_command'] ?? '');
            $build_command = trim($_POST['build_command'] ?? '');
            $start_command = trim($_POST['start_command'] ?? '');
            $static_dir = trim($_POST['static_dir'] ?? '');
            $proxy_strip_base = !empty($_POST['proxy_strip_base']) ? 1 : 0;
            $db->prepare("UPDATE web_apps SET env_vars = ?, install_command = ?, build_command = ?, start_command = ?,
                          static_dir = ?, proxy_strip_base = ? WHERE id = ? AND user_id = ?")
               ->execute([$env_vars, $install_command, $build_command, $start_command, $static_dir, $proxy_strip_base, $id, $user_id]);
            wa_write_env(wa_app_dir($app, $app['app_name']), $env_vars);
            if ($app['mode'] === 'server') {
                $userTmp = ['username' => $app['username'], 'home_dir' => $app['home_dir']];
                $ws = wa_workspace_dir($userTmp, $app['app_name']);
                if (is_dir($ws)) wa_write_env($ws, $env_vars);
            }
            flash('success', 'Settings saved');
        }
    }
    redirect('/cpanel/web-apps.php');
}

function wa_load_app($id) {
    global $user_id, $db;
    $stmt = $db->prepare("SELECT a.*, u.home_dir, u.username FROM web_apps a JOIN users u ON u.id = a.user_id WHERE a.id = ? AND a.user_id = ?");
    $stmt->execute([(int)$id, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$apps = $db->prepare("SELECT a.*, u.home_dir, u.username FROM web_apps a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.created_at DESC");
$apps->execute([$user_id]);
$apps = $apps->fetchAll(PDO::FETCH_ASSOC);

$pm2 = wa_pm2_list();

$log_content = '';
$log_title = '';
if (isset($_GET['logs']) && $_GET['logs'] !== '') {
    $log_id = (int)$_GET['logs'];
    foreach ($apps as $app) {
        if ((int)$app['id'] === $log_id) {
            $appDir = wa_app_dir($app, $app['app_name']);
            $buildLog = $appDir . '/build.log';
            $log_title = $app['app_name'];
            $parts = [];
            if (is_file($buildLog)) {
                $lines = @file($buildLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) $parts[] = "--- build.log ---\n" . implode("\n", array_slice($lines, -300));
            }
            $pm2name = $app['pm2_name'] ?: wa_pm2_name($app['user_id'], $app['app_name']);
            $runtime = wa_pm2_logs($pm2name, 200);
            if ($runtime !== '') $parts[] = $runtime;
            $log_content = h(implode("\n", $parts) ?: 'No log output yet — start a build or run the app.');
            break;
        }
    }
}

$nav = 'webapps';
$page_title = 'Web Applications';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
    .dash-welcome {
        position: relative;
        overflow: hidden;
        border-radius: 20px;
        background: linear-gradient(120deg, #7c3aed 0%, #2563eb 60%, #06b6d4 100%);
        padding: clamp(22px, 4vw, 34px);
        margin-bottom: 22px;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        box-shadow: 0 16px 40px -12px rgba(59, 7, 100, .5);
    }
    .dash-welcome::before {
        content: '';
        position: absolute;
        width: 280px; height: 280px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.18), transparent 70%);
        top: -110px; right: -70px;
    }
    .dash-welcome::after {
        content: '';
        position: absolute;
        width: 200px; height: 200px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.12), transparent 70%);
        bottom: -90px; left: 22%;
    }
    .dash-welcome-left { position: relative; z-index: 1; max-width: 620px; }
    .dash-welcome h2 { margin: 0 0 6px; font-size: clamp(20px, 3vw, 26px); font-weight: 700; letter-spacing: -.3px; }
    .dash-welcome .dash-date { font-size: 13px; opacity: .88; margin-bottom: 14px; }
    .dash-welcome-actions { position: relative; z-index: 1; display: flex; gap: 10px; flex-wrap: wrap; }
    .dash-welcome-actions .btn {
        display: inline-flex; align-items: center; gap: 8px;
        border: none; border-radius: 10px; padding: 10px 16px;
        font-size: 13px; font-weight: 600; cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        text-decoration: none;
    }
    .dash-welcome-actions .btn:hover { transform: translateY(-2px); }
    .btn-welcome-primary { background: #fff; color: #7c3aed; box-shadow: 0 6px 18px rgba(0,0,0,.18); }
    .btn-welcome-primary:hover { background: #f5f3ff; }
    .btn-welcome-ghost { background: rgba(255,255,255,.14); color: #fff; border: 1px solid rgba(255,255,255,.28) !important; backdrop-filter: blur(4px); }
    .btn-welcome-ghost:hover { background: rgba(255,255,255,.24); }
    .dash-pill {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.26);
        color: #fff; font-size: 12px; font-weight: 600;
        padding: 5px 12px; border-radius: 999px; backdrop-filter: blur(4px);
    }
    .dash-pill .lucide { width: 13px; height: 13px; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 22px; }
    .stat-card {
        position: relative;
        display: flex; align-items: center; gap: 16px;
        padding: 20px; border-radius: 16px;
        border: 1px solid var(--border); overflow: hidden;
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; opacity: .9; }
    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px -8px rgba(15,23,42,.15); }
    .stat-card.stat-blue { background: linear-gradient(135deg, rgba(0,115,230,.12), rgba(0,115,230,.02) 70%); border-color: rgba(0,115,230,.14); }
    .stat-card.stat-blue::before { background: linear-gradient(90deg,#0073e6,#38bdf8); }
    .stat-card.stat-green { background: linear-gradient(135deg, rgba(22,163,74,.12), rgba(22,163,74,.02) 70%); border-color: rgba(22,163,74,.14); }
    .stat-card.stat-green::before { background: linear-gradient(90deg,#16a34a,#4ade80); }
    .stat-card.stat-orange { background: linear-gradient(135deg, rgba(217,119,6,.12), rgba(217,119,6,.02) 70%); border-color: rgba(217,119,6,.14); }
    .stat-card.stat-orange::before { background: linear-gradient(90deg,#d97706,#fbbf24); }
    .stat-card.stat-purple { background: linear-gradient(135deg, rgba(124,58,237,.12), rgba(124,58,237,.02) 70%); border-color: rgba(124,58,237,.14); }
    .stat-card.stat-purple::before { background: linear-gradient(90deg,#7c3aed,#c084fc); }
    .stat-card .stat-icon {
        width: 52px; height: 52px; min-width: 52px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 6px 14px -4px rgba(15,23,42,.2);
    }
    .stat-card .stat-icon .lucide { width: 24px; height: 24px; }
    .stat-card .stat-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .stat-card .stat-number { font-size: 24px; font-weight: 800; letter-spacing: -.5px; color: var(--text); line-height: 1; }
    .stat-card .stat-label { font-size: 12px; color: var(--text3); font-weight: 500; }
    .stat-card .stat-sub { font-size: 11px; color: var(--text4); font-weight: 500; }

    /* App cards grid */
    .apps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 18px; }
    .app-card {
        display: flex; flex-direction: column;
        border: 1px solid var(--border); border-radius: 16px;
        background: var(--bg2); overflow: hidden;
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .app-card:hover { transform: translateY(-4px); box-shadow: 0 16px 32px -12px rgba(15,23,42,.18); border-color: rgba(147,51,234,.25); }
    .app-card-top {
        position: relative; padding: 18px 18px 14px;
        display: flex; align-items: flex-start; gap: 14px;
        background: linear-gradient(135deg, rgba(147,51,234,.09), rgba(37,99,235,.05));
    }
    .app-card-top::after {
        content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 3px;
        background: linear-gradient(90deg, rgba(147,51,234,.8), rgba(37,99,235,.8));
        opacity: .8;
    }
    .app-avatar {
        width: 46px; height: 46px; min-width: 46px; border-radius: 13px;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg,#7c3aed,#2563eb); color: #fff;
        box-shadow: 0 8px 18px -6px rgba(124,58,237,.6);
    }
    .app-avatar .lucide { width: 22px; height: 22px; }
    .app-avatar.av-static { background: linear-gradient(135deg,#0d9488,#22c55e); box-shadow: 0 8px 18px -6px rgba(13,148,136,.6); }
    .app-title-block { min-width: 0; flex: 1; }
    .app-name { font-size: 15px; font-weight: 800; color: var(--text); letter-spacing: -.2px; margin: 0 0 3px; word-break: break-all; }
    .app-meta { display: flex; flex-wrap: wrap; gap: 6px; }
    .badge { font-size: 11px; }
    .app-card-body { padding: 14px 18px 16px; display: flex; flex-direction: column; gap: 12px; flex: 1; }
    .app-url {
        display: flex; align-items: center; gap: 8px;
        font-size: 13px; color: var(--accent); font-weight: 600; text-decoration: none;
        background: var(--bg3); border: 1px solid var(--border);
        padding: 8px 12px; border-radius: 10px; word-break: break-all;
        transition: all .15s ease;
    }
    .app-url:hover { border-color: rgba(0,115,230,.35); background: rgba(0,115,230,.06); text-decoration: none; }
    .app-url .lucide { width: 14px; height: 14px; flex-shrink: 0; }
    .app-stats-row { display: flex; gap: 14px; flex-wrap: wrap; font-size: 11px; color: var(--text3); }
    .app-stat { display: inline-flex; align-items: center; gap: 5px; font-weight: 500; }
    .app-stat .lucide { width: 13px; height: 13px; }
    .app-actions {
        display: flex; flex-wrap: wrap; gap: 6px; padding: 12px 18px;
        border-top: 1px solid var(--border); background: var(--bg3);
        margin-top: auto;
    }
    .app-actions form { display: inline; margin: 0; }
    .app-actions .btn { padding: 7px 10px; }
    .empty-state { padding: 40px 20px; }
    .empty-state .lucide { width: 22px; height: 22px; }

    /* ========== Deploy New App ========== */
    @keyframes wa-spin { to { transform: rotate(360deg); } }
    .wa-spin { animation: wa-spin 1s linear infinite !important; }
    .deploy-card { overflow: hidden; }
    .deploy-card-header {
        display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
        padding: 18px clamp(14px,3vw,22px);
        background: linear-gradient(120deg, rgba(124,58,237,.08), rgba(37,99,235,.06) 60%, rgba(6,182,212,.05));
        border-bottom: 1px solid var(--border);
    }
    .deploy-card-header h3 { margin: 0; font-size: 15px; font-weight: 700; letter-spacing: -.2px; }
    .deploy-card-header h3 .lucide { color: #7c3aed; width: 18px; height: 18px; }
    .deploy-card-header p { margin: 4px 0 0; font-size: 12px; color: var(--text3); }
    .deploy-pill {
        display: inline-flex; align-items: center; gap: 6px;
        background: #fff; border: 1px solid var(--border); border-radius: var(--radius-full);
        padding: 6px 12px; font-size: 11px; font-weight: 600; color: var(--text2);
        box-shadow: var(--shadow-xs);
    }
    .deploy-pill .lucide { width: 13px; height: 13px; color: #7c3aed; }

    .deploy-body { padding: 6px clamp(14px,3vw,22px) clamp(16px,3vw,22px); }
    .deploy-section { padding: 18px 0; border-bottom: 1px dashed var(--border); }
    .deploy-section:last-of-type { border-bottom: none; }
    .deploy-section-title { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
    .deploy-section-title strong { font-size: 13px; font-weight: 700; color: var(--text); }
    .deploy-section-hint { font-size: 12px; color: var(--text3); font-weight: 500; }
    .deploy-step-num {
        width: 22px; height: 22px; min-width: 22px; border-radius: var(--radius-full);
        display: inline-flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg,#7c3aed,#2563eb); color: #fff;
        font-size: 11px; font-weight: 700;
        box-shadow: 0 4px 10px -2px rgba(124,58,237,.5);
    }

    .input-with-icon, .select-with-icon { position: relative; }
    .input-with-icon > .lucide, .select-with-icon > .lucide {
        position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
        width: 16px; height: 16px; color: var(--text4); pointer-events: none;
    }
    .input-with-icon input, .select-with-icon select { padding-left: 38px; }
    .field-hint { display: block; margin-top: 6px; font-size: 12px; color: var(--text3); }
    .field-hint code {
        background: var(--bg4); border: 1px solid var(--border); border-radius: var(--radius-xs);
        padding: 1px 6px; font-size: 11px; color: var(--primary); font-weight: 600;
    }

    .source-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .source-card {
        position: relative;
        display: flex; align-items: center; gap: 12px;
        padding: 14px 16px;
        border: 1.5px solid var(--border); border-radius: var(--radius);
        background: var(--bg2); cursor: pointer;
        transition: all .18s ease;
    }
    .source-card:hover { border-color: var(--bg5); box-shadow: var(--shadow-sm); }
    .source-card input { position: absolute; opacity: 0; pointer-events: none; }
    .source-card .source-card-icon {
        width: 40px; height: 40px; min-width: 40px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        background: var(--bg4); color: var(--text3);
        transition: all .18s ease;
    }
    .source-card .source-card-icon .lucide { width: 19px; height: 19px; }
    .source-card-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .source-card-info strong { font-size: 13px; font-weight: 700; color: var(--text); }
    .source-card-info span { font-size: 11px; color: var(--text4); font-weight: 500; }
    .source-card-check {
        width: 20px; height: 20px; min-width: 20px; border-radius: var(--radius-full);
        border: 1.5px solid var(--bg5); display: flex; align-items: center; justify-content: center;
        margin-left: auto; transition: all .18s ease;
    }
    .source-card-check .lucide { width: 12px; height: 12px; color: #fff; opacity: 0; transition: opacity .15s ease; }
    .source-card.active {
        border-color: var(--primary); background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(0,115,230,.06);
    }
    .source-card.active .source-card-icon {
        background: linear-gradient(135deg,#0073e6,#3397f0); color: #fff;
        box-shadow: 0 6px 14px -4px rgba(0,115,230,.5);
    }
    .source-card.active .source-card-check { background: var(--primary); border-color: var(--primary); }
    .source-card.active .source-card-check .lucide { opacity: 1; }

    .zip-row, .git-row { margin-top: 14px; }
    .dropzone {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
        padding: 28px 16px;
        border: 2px dashed var(--bg5); border-radius: var(--radius);
        background: var(--bg3); text-align: center; cursor: pointer;
        transition: all .18s ease;
    }
    .dropzone:hover, .dropzone.dragover {
        border-color: var(--primary); background: var(--primary-light);
    }
    .dropzone .lucide { width: 26px; height: 26px; color: var(--text4); margin-bottom: 4px; transition: color .18s ease; }
    .dropzone:hover .lucide, .dropzone.dragover .lucide { color: var(--primary); }
    .dropzone strong { font-size: 13px; color: var(--text); font-weight: 600; }
    .dropzone span { font-size: 11px; color: var(--text4); font-weight: 500; }
    .dropzone.has-file {
        border-style: solid; border-color: var(--success-border); background: var(--success-light);
    }
    .dropzone.has-file .lucide { color: var(--success); }
    .dropzone.has-file strong { color: var(--success); }

    .deploy-actions {
        display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        padding-top: 18px;
    }
    .deploy-actions .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; font-size: 13px; font-weight: 600; }
    .deploy-actions .btn .lucide { width: 16px; height: 16px; }
    .deploy-actions .deploy-note { font-size: 12px; color: var(--text3); max-width: 420px; line-height: 1.5; }
    .deploy-actions .deploy-note .lucide { width: 13px; height: 13px; vertical-align: -2px; }

    @media (max-width: 480px) {
        .source-cards { grid-template-columns: 1fr; }
        .deploy-section-title { flex-wrap: wrap; }
    }

    @media (max-width: 480px) {
        .apps-grid { grid-template-columns: 1fr; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .stat-card { padding: 14px; gap: 12px; }
        .stat-card .stat-icon { width: 44px; height: 44px; min-width: 44px; }
        .stat-card .stat-number { font-size: 19px; }
        .dash-welcome-actions { width: 100%; }
    }
</style>

<!-- Welcome Banner -->
<div class="dash-welcome fade-in">
    <div class="dash-welcome-left">
        <h2>Deploy &amp; manage your projects &#128640;</h2>
        <div class="dash-date">Upload a ZIP or connect a Git repo — we auto-detect the framework, build it, and serve it at <code style="color:#e2e8f0"><?= h(wa_base_path($username)) ?>&lt;app&gt;</code>.</div>
        <span class="dash-pill"><i data-lucide="sparkles"></i> AI-built apps (Loveable, Bolt, v0, Replit, Cursor)</span>
    </div>
    <div class="dash-welcome-actions">
        <button type="button" class="btn btn-welcome-primary" onclick="document.getElementById('deployCard').scrollIntoView({behavior:'smooth'});"><i data-lucide="rocket"></i> Deploy New App</button>
        <?php if (count($apps)): ?>
            <a href="#appsList" class="btn btn-welcome-ghost"><i data-lucide="layers"></i> View Apps</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="layers"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($apps) ?></div>
            <div class="stat-label">Total Apps</div>
            <div class="stat-sub">deployed projects</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="play-circle"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count(array_filter($apps, fn($a) => ($pm2[$a['pm2_name'] ?: wa_pm2_name($a['user_id'], $a['app_name'])]['status'] ?? '') === 'online')) ?></div>
            <div class="stat-label">Running</div>
            <div class="stat-sub">live right now</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-2">
        <div class="stat-icon icon-orange"><i data-lucide="hammer"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count(array_filter($apps, fn($a) => in_array($a['build_status'], ['installing', 'building']))) ?></div>
            <div class="stat-label">Building</div>
            <div class="stat-sub">in the background</div>
        </div>
    </div>
</div>

<div class="card deploy-card fade-in" id="deployCard" style="margin-bottom:20px">
    <div class="deploy-card-header">
        <div>
            <h3><i data-lucide="rocket" class="lucide"></i> Deploy New App</h3>
            <p>Upload a ZIP or connect a Git repo — we auto-detect the framework, build it, and serve it live.</p>
        </div>
        <span class="deploy-pill"><i data-lucide="zap" class="lucide"></i> Auto-detection on</span>
    </div>
    <div class="deploy-body">
        <form method="POST" enctype="multipart/form-data" id="deployForm">
            <input type="hidden" name="action" value="create">

            <div class="deploy-section">
                <div class="deploy-section-title">
                    <span class="deploy-step-num">1</span>
                    <strong>App details</strong>
                    <span class="deploy-section-hint">Name your app and pick a Node.js runtime</span>
                </div>
                <div class="form-grid" style="grid-template-columns:2fr 1fr;align-items:end">
                    <div class="form-group" style="margin:0">
                        <label for="deployAppName">App Name</label>
                        <div class="input-with-icon">
                            <i data-lucide="type" class="lucide"></i>
                            <input type="text" name="app_name" id="deployAppName" required placeholder="e.g. my-landing-page" pattern="[A-Za-z0-9][A-Za-z0-9 _-]{1,49}" autocomplete="off">
                        </div>
                        <small class="field-hint" id="deployUrlPreview">Will be served at <code>/your-app-name</code></small>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label for="deployNodeVersion">Node.js Version</label>
                        <div class="select-with-icon">
                            <i data-lucide="server" class="lucide"></i>
                            <select name="node_version" id="deployNodeVersion">
                                <?php foreach (['', '18', '20', '22', '24'] as $v): ?>
                                    <option value="<?= $v ?>" <?= $v === '' ? 'selected' : '' ?>><?= $v === '' ? 'Default (current)' : 'Node.js ' . $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="deploy-section">
                <div class="deploy-section-title">
                    <span class="deploy-step-num">2</span>
                    <strong>Source</strong>
                    <span class="deploy-section-hint">Where should we pull the project from?</span>
                </div>
                <div class="source-cards">
                    <label class="source-card active">
                        <input type="radio" name="source_type" value="zip" checked onchange="waToggleSource()">
                        <div class="source-card-icon"><i data-lucide="file-archive" class="lucide"></i></div>
                        <div class="source-card-info">
                            <strong>ZIP Upload</strong>
                            <span>Loveable, Bolt, v0, Replit, Cursor</span>
                        </div>
                        <span class="source-card-check"><i data-lucide="check" class="lucide"></i></span>
                    </label>
                    <label class="source-card">
                        <input type="radio" name="source_type" value="git" onchange="waToggleSource()">
                        <div class="source-card-icon"><i data-lucide="git-branch" class="lucide"></i></div>
                        <div class="source-card-info">
                            <strong>Git Repository</strong>
                            <span>Clone a public GitHub / GitLab repo</span>
                        </div>
                        <span class="source-card-check"><i data-lucide="check" class="lucide"></i></span>
                    </label>
                </div>

                <div class="zip-row" id="zip-row">
                    <label for="zipFileInput" class="dropzone" id="deployDropzone">
                        <input type="file" name="zip_file" id="zipFileInput" accept=".zip,application/zip" hidden onchange="waHandleZip(this)">
                        <i data-lucide="upload-cloud" class="lucide"></i>
                        <strong id="dropzoneText">Click to choose a ZIP file</strong>
                        <span>or drag &amp; drop it here</span>
                    </label>
                </div>

                <div class="git-row" id="git-row" style="display:none">
                    <div class="form-group" style="margin:0">
                        <label for="deployRepoUrl">Repository URL</label>
                        <div class="input-with-icon">
                            <i data-lucide="git-branch" class="lucide"></i>
                            <input type="text" name="repo_url" id="deployRepoUrl" placeholder="https://github.com/user/repo.git" autocomplete="off">
                        </div>
                        <small class="field-hint">We clone it and auto-detect the framework &amp; commands</small>
                    </div>
                </div>
            </div>

            <div class="deploy-section">
                <div class="deploy-section-title">
                    <span class="deploy-step-num">3</span>
                    <strong>Environment</strong>
                    <span class="deploy-section-hint">Optional variables for your build</span>
                </div>
                <div class="form-group" style="margin:0">
                    <label for="deployEnvVars">Environment Variables <span style="color:var(--text3);font-size:12px;font-weight:500">(one per line, KEY=value &mdash; optional)</span></label>
                    <textarea name="env_vars" id="deployEnvVars" rows="4" placeholder="VITE_API_URL=https://api.example.com&#10;DATABASE_URL=postgres://user:pass@host/db" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;background:var(--bg4);color:var(--text);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:12px;resize:vertical;line-height:1.6"></textarea>
                </div>
            </div>

            <div class="deploy-actions">
                <button type="submit" class="btn btn-primary" id="deploySubmitBtn"><i data-lucide="rocket" class="lucide"></i><span>Deploy App</span></button>
                <span class="deploy-note"><i data-lucide="info" class="lucide"></i> Install + build run in the background; server apps start automatically.</span>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    var form = document.getElementById('deployForm');
    if (!form) return;

    var appName = document.getElementById('deployAppName');
    var urlPreview = document.getElementById('deployUrlPreview');
    function waSlugify(s){
        s = (s || '').toLowerCase().replace(/\s+/g, ' ').trim();
        s = s.replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').replace(/-{2,}/g, '-').slice(0, 50);
        return s === '' ? 'app' : s;
    }
    if (appName && urlPreview) {
        appName.addEventListener('input', function(){
            urlPreview.innerHTML = 'Will be served at <code>/' + waSlugify(this.value) + '</code>';
        });
    }

    var sourceRadios = form.querySelectorAll('input[name="source_type"]');
    var zipRow = document.getElementById('zip-row');
    var gitRow = document.getElementById('git-row');
    window.waToggleSource = function(){
        sourceRadios.forEach(function(r){
            r.closest('.source-card').classList.toggle('active', r.checked);
        });
        var isZip = (form.querySelector('input[name="source_type"]:checked') || { value: 'zip' }).value === 'zip';
        if (zipRow) zipRow.style.display = isZip ? '' : 'none';
        if (gitRow) gitRow.style.display = isZip ? 'none' : '';
    };

    var dropzone = document.getElementById('deployDropzone');
    var dropzoneText = document.getElementById('dropzoneText');
    var zipInput = document.getElementById('zipFileInput');
    if (dropzone && zipInput) {
        ['dragenter', 'dragover'].forEach(function(ev){
            dropzone.addEventListener(ev, function(e){ e.preventDefault(); dropzone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function(ev){
            dropzone.addEventListener(ev, function(e){ e.preventDefault(); dropzone.classList.remove('dragover'); });
        });
        dropzone.addEventListener('drop', function(e){
            if (e.dataTransfer && e.dataTransfer.files.length) {
                zipInput.files = e.dataTransfer.files;
                waHandleZip(zipInput);
            }
        });
    }
    window.waHandleZip = function(input){
        var f = input && input.files && input.files[0];
        if (!f || !dropzone) return;
        dropzone.classList.add('has-file');
        var size = f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
        if (dropzoneText) dropzoneText.textContent = f.name + ' (' + size + ')';
    };

    var submitBtn = document.getElementById('deploySubmitBtn');
    form.addEventListener('submit', function(){
        if (!submitBtn) return;
        submitBtn.disabled = true;
        var icon = submitBtn.querySelector('.lucide');
        if (icon) icon.classList.add('wa-spin');
        var label = submitBtn.querySelector('span');
        if (label) label.textContent = 'Deploying\u2026';
    });
})();
</script>

<?php if ($log_content !== ''): ?>
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-header">
        <h3><i data-lucide="scroll-text" class="lucide"></i> Logs: <?= h($log_title) ?></h3>
        <a href="/cpanel/web-apps.php" class="btn btn-sm btn-danger" style="text-decoration:none;margin-left:auto"><i data-lucide="x" class="lucide" style="width:12px;height:12px"></i> Close</a>
    </div>
    <div class="card-body" style="padding:0">
        <div style="background:#0d1117;color:#2ecc71;font-family:monospace;font-size:12px;padding:16px;max-height:480px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;line-height:1.7"><?= $log_content ?></div>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in-delay-2" id="appsList">
    <div class="card-header">
        <h3><i data-lucide="layers" class="lucide"></i> Your Applications (<?= count($apps) ?>)</h3>
        <?php if (count($apps)): ?>
            <span style="font-size:12px;color:var(--text3);margin-left:auto;display:inline-flex;align-items:center;gap:5px"><i data-lucide="info" class="lucide" style="width:12px;height:12px"></i> Click an app URL to open it</span>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($apps)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i data-lucide="server" class="lucide"></i></div>
                <p>No web apps deployed yet</p>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('deployCard').scrollIntoView({behavior:'smooth'})" style="margin-top:6px"><i data-lucide="rocket" class="lucide" style="width:14px;height:14px"></i> Deploy your first app</button>
            </div>
        <?php else: ?>
            <div class="apps-grid" style="padding:20px">
                <?php foreach ($apps as $app):
                    $pm2name = $app['pm2_name'] ?: wa_pm2_name($app['user_id'], $app['app_name']);
                    $live = $pm2[$pm2name] ?? null;
                    $isOnline = ($live['status'] ?? '') === 'online';
                    $isBuilding = in_array($app['build_status'], ['installing', 'building']);
                    $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . $app['base_path'];
                ?>
                <div class="app-card fade-in">
                    <div class="app-card-top">
                        <div class="app-avatar <?= $app['mode'] === 'static' ? 'av-static' : '' ?>"><i data-lucide="<?= $app['mode'] === 'server' ? 'terminal' : 'file-code' ?>"></i></div>
                        <div class="app-title-block">
                            <div class="app-name"><?= h($app['app_name']) ?></div>
                            <div class="app-meta">
                                <span class="badge badge-active" style="text-transform:capitalize"><?= h($app['framework'] ?? '?') ?></span>
                                <?php if ($app['package_manager']): ?><span class="badge" style="background:rgba(100,116,139,.15);color:var(--text2)"><?= h($app['package_manager']) ?></span><?php endif; ?>
                                <?php if ($app['mode'] === 'server'): ?><span class="badge" style="background:rgba(147,51,234,.15);color:#9333ea">port <?= (int)$app['port'] ?></span><?php else: ?><span class="badge" style="background:rgba(52,152,219,.15);color:#3498db">Static</span><?php endif; ?>
                            </div>
                        </div>
                        <?php
                            if ($isOnline) echo '<span class="badge badge-active" style="flex-shrink:0"><i data-lucide="play" class="lucide" style="width:10px;height:10px;vertical-align:middle;margin-right:2px"></i> Online</span>';
                            elseif ($isBuilding) echo '<span class="badge" style="background:rgba(243,156,18,.15);color:#f39c12;flex-shrink:0"><i data-lucide="loader" class="lucide" style="width:10px;height:10px;vertical-align:middle;margin-right:2px"></i> ' . ($app['build_status'] === 'installing' ? 'Installing' : 'Building') . '</span>';
                            elseif ($live !== null) echo '<span class="badge badge-suspended" style="flex-shrink:0">' . h($live['status']) . '</span>';
                            elseif ($app['mode'] === 'static') echo '<span class="badge" style="background:rgba(52,152,219,.15);color:#3498db;flex-shrink:0">Ready</span>';
                            else echo '<span class="badge badge-suspended" style="flex-shrink:0">Stopped</span>';
                        ?>
                    </div>
                    <div class="app-card-body">
                        <a href="<?= h($publicUrl) ?>" target="_blank" rel="noopener" class="app-url">
                            <i data-lucide="external-link"></i> <?= h($app['base_path']) ?>
                        </a>
                        <div class="app-stats-row">
                            <span class="app-stat"><i data-lucide="hammer"></i> Build: <?php
                                $bs = $app['build_status'];
                                if ($bs === 'done') echo 'built';
                                elseif ($bs === 'installing' || $bs === 'building') echo ucfirst($bs) . '&hellip;';
                                elseif ($bs === 'failed') echo 'failed';
                                else echo '&mdash;';
                            ?></span>
                            <?php if ($isOnline && isset($live['cpu'])): ?>
                                <span class="app-stat"><i data-lucide="cpu"></i> <?= round($live['cpu']) ?>% CPU</span>
                                <span class="app-stat"><i data-lucide="memory-stick"></i> <?= format_size((int)$live['memory']) ?></span>
                                <span class="app-stat"><i data-lucide="rotate-ccw"></i> <?= (int)$live['restarts'] ?> restarts</span>
                            <?php endif; ?>
                            <span class="app-stat" style="margin-left:auto"><i data-lucide="clock"></i> <?= date('M d, Y', strtotime($app['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="app-actions">
                        <?php if ($app['mode'] === 'server'): ?>
                            <?php if ($isOnline): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="stop">
                                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" title="Stop"><i data-lucide="square" class="lucide" style="width:12px;height:12px"></i> Stop</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="action" value="restart">
                                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-info" title="Restart"><i data-lucide="rotate-ccw" class="lucide" style="width:12px;height:12px"></i> Restart</button>
                                </form>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="start">
                                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Start"><i data-lucide="play" class="lucide" style="width:12px;height:12px"></i> Start</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="rebuild">
                            <input type="hidden" name="id" value="<?= $app['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-info" title="Rebuild" <?= $isBuilding ? 'disabled' : '' ?>><i data-lucide="hammer" class="lucide" style="width:12px;height:12px"></i> Rebuild</button>
                        </form>
                        <a href="?logs=<?= $app['id'] ?>" class="btn btn-sm btn-info" title="Logs" style="text-decoration:none;display:inline-flex;align-items:center"><i data-lucide="scroll-text" class="lucide" style="width:12px;height:12px"></i> Logs</a>
                        <a href="?edit=<?= $app['id'] ?>" class="btn btn-sm btn-warning" title="Settings" style="text-decoration:none;display:inline-flex;align-items:center"><i data-lucide="settings-2" class="lucide" style="width:12px;height:12px"></i></a>
                        <form method="POST" onsubmit="return confirm('Delete this application permanently? This removes its files too.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $app['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete" style="margin-left:auto"><i data-lucide="trash-2" class="lucide" style="width:12px;height:12px"></i> Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id):
    foreach ($apps as $app):
        if ((int)$app['id'] === $edit_id):
?>
<div class="card fade-in" style="margin-bottom:20px;border:1.5px solid var(--accent)">
    <div class="card-header">
        <h3><i data-lucide="settings-2" class="lucide"></i> Settings: <?= h($app['app_name']) ?></h3>
        <a href="/cpanel/web-apps.php" class="btn btn-sm btn-danger" style="text-decoration:none;margin-left:auto"><i data-lucide="x" class="lucide" style="width:12px;height:12px"></i> Close</a>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $app['id'] ?>">
            <div class="form-group">
                <label>Install Command</label>
                <input type="text" name="install_command" value="<?= h($app['install_command'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Build Command</label>
                <input type="text" name="build_command" value="<?= h($app['build_command'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Start Command <span style="color:var(--text3);font-size:12px">(server apps)</span></label>
                <input type="text" name="start_command" value="<?= h($app['start_command'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Static Output Directory</label>
                <input type="text" name="static_dir" value="<?= h($app['static_dir'] ?? 'dist') ?>">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                    <input type="checkbox" name="proxy_strip_base" value="1" <?= (int)$app['proxy_strip_base'] === 1 ? 'checked' : '' ?>>
                    Strip base path before proxying to the app port (for servers that don't know the sub-path)
                </label>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Environment Variables</label>
                <textarea name="env_vars" rows="5" style="width:100%;font-family:monospace;font-size:13px;background:var(--bg4);color:var(--text);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:12px;resize:vertical"><?= h($app['env_vars'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px"><i data-lucide="save" class="lucide" style="width:16px;height:16px"></i> Save Settings</button>
                <span style="color:var(--text3);font-size:12px;margin-left:12px">Rebuild after changing install/build commands.</span>
            </div>
        </form>
    </div>
</div>
<?php endif; endforeach; endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
