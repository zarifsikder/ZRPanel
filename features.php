<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feature_flags.php';

// ============================================================
//  ZRPanel Feature Flags — Secret Admin (user / ZARIF 909090)
// ============================================================

// --- Log out -------------------------------------------------
if (isset($_GET['logout'])) {
    unset($_SESSION['feature_auth'], $_SESSION['feature_auth_ts']);
    session_regenerate_id(true);
    redirect('/features');
}

// --- Login ---------------------------------------------------
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (hash_equals(FEATURES_USERNAME, $u) && password_verify($p, FEATURES_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['feature_auth'] = true;
        $_SESSION['feature_auth_ts'] = time();
        redirect('/features');
    } else {
        $error = 'Invalid username or password';
    }
}

$authed = !empty($_SESSION['feature_auth']);

// --- Toggle / reset (JSON + form) ----------------------------
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    if (!hash_equals($_SESSION['feature_csrf'] ?? '', $token)) {
        http_response_code(403);
        echo '{"ok":false,"error":"invalid token"}';
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'set') {
        $key = (string)($_POST['key'] ?? '');
        $on = !empty($_POST['state']);
        $ok = feature_flag_set($key, $on);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'key' => $key, 'state' => $ok ? feature_flag($key) : null]);
        exit;
    }
    if ($action === 'reset') {
        @unlink(feature_flags_file());
        redirect('/features');
    }
}

// --- Not authenticated: show secret login ---------------------
if (!$authed):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Restricted - <?= SITE_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css?v=20260731b">
    <script defer src="/assets/lucide.min.js"></script>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-icon logo-icon-lg"><i data-lucide="lock-keyhole" class="lucide"></i></div>
                <h1>Restricted</h1>
                <p>Feature Settings</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error"><i data-lucide="alert-circle" class="lucide"></i> <?= h($error) ?></div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus placeholder="Enter username" autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="Enter password" autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Access</button>
            </form>
            <div class="login-footer"><p>Authorized personnel only.</p></div>
        </div>
    </div>
<script>
        (function () {
            function init() {
                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                    return true;
                }
                return false;
            }
            if (!init()) {
                var t = setInterval(function () { if (init()) clearInterval(t); }, 50);
                setTimeout(function () { clearInterval(t); }, 8000);
            }
        })();
    </script>
</body>
</html>
<?php exit; endif; ?>

<?php
$_SESSION['feature_csrf'] = $_SESSION['feature_csrf'] ?? bin2hex(random_bytes(16));
$csrf = $_SESSION['feature_csrf'];
$state = feature_flags_state();
$registry = feature_flags_registry();
$total = count($registry);
$enabledCount = count(array_filter($state));

$page_title = 'Feature Flags';
$nav = 'features';
require_once __DIR__ . '/templates/header.php';
?>
<style>
    .ff-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
    .ff-search-wrap { position:relative; flex:1 1 320px; min-width:240px; }
    .ff-search-wrap .lucide { position:absolute; left:14px; top:50%; transform:translateY(-50%); width:18px; height:18px; color:var(--text4); pointer-events:none; }
    .ff-search { width:100%; padding:12px 14px 12px 42px; border:1px solid var(--border-color); border-radius:12px; font-size:14px; background:#fff; outline:none; transition:border .2s, box-shadow .2s; }
    .ff-search:focus { border-color:var(--primary-color); box-shadow:0 0 0 3px rgba(59,130,246,.15); }
    .ff-count { font-size:13px; color:var(--text3); white-space:nowrap; }
    .ff-count strong { color:var(--primary-color); }
    .ff-group { margin-bottom:22px; }
    .ff-group-title { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text3); margin:0 0 10px; }
    .ff-group-title .lucide { width:14px; height:14px; }
    .ff-card { background:#fff; border:1px solid var(--border-color); border-radius:14px; overflow:hidden; }
    .ff-row { display:flex; align-items:center; gap:14px; padding:14px 18px; border-bottom:1px solid var(--border-color); transition:background .15s; }
    .ff-row:last-child { border-bottom:none; }
    .ff-row:hover { background:#f8fafc; }
    .ff-icon { width:38px; height:38px; flex-shrink:0; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; }
    .ff-icon .lucide { width:18px; height:18px; }
    .ff-info { flex:1 1 auto; min-width:0; }
    .ff-info strong { display:block; font-size:14px; color:var(--text); margin-bottom:2px; }
    .ff-info span { display:block; font-size:12.5px; color:var(--text3); line-height:1.45; }
    .ff-key { font-family:monospace; font-size:11px; color:var(--text4); background:#f1f5f9; padding:1px 7px; border-radius:6px; margin-left:6px; vertical-align:1px; }
    .ff-row.disabled { opacity:.45; }
    .ff-switch { position:relative; width:46px; height:26px; flex-shrink:0; cursor:pointer; }
    .ff-switch input { opacity:0; width:0; height:0; position:absolute; }
    .ff-slider { position:absolute; inset:0; background:#cbd5e1; border-radius:20px; transition:background .2s; }
    .ff-slider:before { content:""; position:absolute; width:20px; height:20px; left:3px; top:3px; background:#fff; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,.25); transition:transform .2s; }
    .ff-switch input:checked + .ff-slider { background:var(--primary-color,#3b82f6); }
    .ff-switch input:checked + .ff-slider:before { transform:translateX(20px); }
    .ff-switch input:focus-visible + .ff-slider { box-shadow:0 0 0 3px rgba(59,130,246,.3); }
    .ff-empty { display:none; text-align:center; padding:40px 20px; color:var(--text3); }
    .ff-empty .lucide { width:28px; height:28px; margin-bottom:10px; color:var(--text4); }
    .ff-actions { display:flex; gap:10px; }
    .ff-chip { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border:1px solid var(--border-color); border-radius:20px; background:#fff; font-size:13px; color:var(--text2); cursor:pointer; }
    .ff-chip:hover { border-color:var(--primary-color); color:var(--primary-color); }
</style>

<div class="dashboard-header" style="margin-bottom:20px">
    <div>
        <h2 style="margin:0 0 4px"><i data-lucide="sliders-horizontal" style="width:22px;height:22px;vertical-align:-3px;color:var(--primary-color)"></i> Feature Flags</h2>
        <p style="margin:0;color:var(--text3);font-size:14px">Enable or disable features of this site. Changes apply immediately.</p>
    </div>
</div>

<div class="ff-toolbar">
    <div class="ff-search-wrap">
        <i data-lucide="search" class="lucide"></i>
        <input type="text" id="ffSearch" class="ff-search" placeholder="Search features…" autocomplete="off" spellcheck="false">
    </div>
    <div class="ff-count"><strong id="ffShown"><?= $total ?></strong> / <?= $total ?> features · <strong style="color:var(--success,#059669)"><?= $enabledCount ?></strong> enabled</div>
</div>

<?php $iconColor = ['General'=>'#6366f1','Access'=>'#dc2626','cPanel'=>'#0d9488','Infrastructure'=>'#d97706','WHM'=>'#7c3aed']; ?>
<?php foreach (feature_flags_categories() as $cat): ?>
<div class="ff-group" data-cat="<?= h($cat) ?>">
    <h4 class="ff-group-title"><i data-lucide="folder" class="lucide"></i> <?= h($cat) ?></h4>
    <div class="ff-card">
        <?php foreach ($registry as $key => $meta): if ($meta['cat'] !== $cat) continue; ?>
        <div class="ff-row<?= $state[$key] ? '' : ' disabled' ?>" data-search="<?= h(strtolower($key.' '.$meta['label'].' '.$meta['cat'].' '.$meta['desc'])) ?>">
            <div class="ff-icon" style="background:<?= $iconColor[$cat] ?? '#64748b' ?>"><i data-lucide="<?= h($meta['icon']) ?>" class="lucide"></i></div>
            <div class="ff-info">
                <strong><?= h($meta['label']) ?><code class="ff-key"><?= h($key) ?></code></strong>
                <span><?= h($meta['desc']) ?></span>
            </div>
            <label class="ff-switch">
                <input type="checkbox" data-flag="<?= h($key) ?>" <?= $state[$key] ? 'checked' : '' ?>>
                <span class="ff-slider"></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="ff-empty" id="ffEmpty">
    <i data-lucide="search-x" class="lucide"></i>
    <strong>No features match</strong><br>
    <span>Try a different keyword.</span>
</div>

<div class="ff-actions" style="margin-top:20px">
    <form method="POST" onsubmit="return confirm('Reset all feature flags to defaults?');">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="token" value="<?= h($csrf) ?>">
        <button type="submit" class="btn" style="border:1px solid var(--border-color)"><i data-lucide="rotate-ccw" class="lucide" style="width:16px;height:16px"></i> Reset to Defaults</button>
    </form>
    <a href="/features?logout=1" class="btn" style="border:1px solid var(--border-color)"><i data-lucide="log-out" class="lucide" style="width:16px;height:16px"></i> Log Out</a>
</div>

<script>
(function(){
    var token = <?= json_encode($csrf) ?>;
    var rows = Array.prototype.slice.call(document.querySelectorAll('.ff-row'));
    var groups = Array.prototype.slice.call(document.querySelectorAll('.ff-group'));
    var empty = document.getElementById('ffEmpty');
    var shown = document.getElementById('ffShown');
    var input = document.getElementById('ffSearch');

    function applyFilter(){
        var q = (input.value || '').toLowerCase().trim();
        var visible = 0;
        rows.forEach(function(row){
            var show = !q || row.getAttribute('data-search').indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        groups.forEach(function(g){
            var any = g.querySelectorAll('.ff-row:not([style*="none"])').length > 0;
            g.style.display = any ? '' : 'none';
        });
        if (shown) shown.textContent = visible;
        if (empty) empty.style.display = visible ? 'none' : 'block';
    }
    if (input) input.addEventListener('input', applyFilter);

    rows.forEach(function(row){
        var cb = row.querySelector('input[data-flag]');
        if (!cb) return;
        cb.addEventListener('change', function(){
            var key = cb.getAttribute('data-flag');
            var state = cb.checked;
            fetch('/features', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=set&token=' + encodeURIComponent(token) + '&key=' + encodeURIComponent(key) + '&state=' + (state ? '1' : '0')
            }).then(function(r){ return r.json(); }).then(function(res){
                if (!res.ok) { cb.checked = !state; alert('Failed to update feature.'); return; }
                cb.checked = !!res.state;
                row.classList.toggle('disabled', !res.state);
                applyFilter();
            }).catch(function(){ cb.checked = !state; alert('Network error.'); });
        });
    });
    if (typeof lucide !== 'undefined') lucide.createIcons();
})();
</script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
