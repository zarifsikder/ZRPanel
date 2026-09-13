<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'user';
$home_dir = $user['home_dir'] ?? ("/storage/emulated/0/Download/hosting/user_data/{$username}");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $dir_path = trim($_POST['directory_path'] ?? '');
        $prot_user = trim($_POST['protect_username'] ?? '');
        $prot_pass = trim($_POST['protect_password'] ?? '');
        $realm = trim($_POST['realm'] ?? 'Restricted Area');

        if (empty($dir_path) || empty($prot_user) || empty($prot_pass)) {
            flash('error', 'Directory path, username, and password are required.');
            redirect('/cpanel/directory-privacy.php');
        }

        $dir_path = ltrim($dir_path, '/');
        $full_dir = rtrim($home_dir, '/') . '/' . $dir_path;

        if (!file_exists($full_dir)) {
            @mkdir($full_dir, 0755, true);
        }

        $exists = $db->prepare("SELECT id FROM directory_protection WHERE directory_path = ? AND user_id = ?");
        $exists->execute([$dir_path, $user_id]);
        if ($exists->fetch()) {
            flash('error', 'This directory already has protection configured.');
            redirect('/cpanel/directory-privacy.php');
        }

        $htpasswd_entry = $prot_user . ':' . password_hash($prot_pass, PASSWORD_BCRYPT);
        $htaccess_content = "AuthType Basic\nAuthName \"{$realm}\"\nAuthUserFile \"{$full_dir}/.htpasswd\"\nRequire valid-user\n";

        file_put_contents($full_dir . '/.htpasswd', $htpasswd_entry . "\n");
        file_put_contents($full_dir . '/.htaccess', $htaccess_content);

        $db->prepare("INSERT INTO directory_protection (user_id, directory_path, protect_username, protect_password, realm, status) VALUES (?, ?, ?, ?, ?, 'active')")
           ->execute([$user_id, $dir_path, $prot_user, password_hash($prot_pass, PASSWORD_BCRYPT), $realm]);
        flash('success', "Password protection added to /{$dir_path}");

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM directory_protection WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $dir = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dir) {
            $new_status = $dir['status'] === 'active' ? 'disabled' : 'active';
            $full_dir = rtrim($home_dir, '/') . '/' . $dir['directory_path'];

            if ($new_status === 'disabled') {
                $disable_htaccess = "AuthType None\nRequire all granted\n";
                @file_put_contents($full_dir . '/.htaccess', $disable_htaccess);
            } else {
                $htaccess_content = "AuthType Basic\nAuthName \"{$dir['realm']}\"\nAuthUserFile \"{$full_dir}/.htpasswd\"\nRequire valid-user\n";
                @file_put_contents($full_dir . '/.htaccess', $htaccess_content);
            }

            $db->prepare("UPDATE directory_protection SET status = ? WHERE id = ?")
               ->execute([$new_status, $id]);
            flash('success', 'Protection ' . ($new_status === 'active' ? 'enabled' : 'disabled'));
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM directory_protection WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $dir = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dir) {
            $full_dir = rtrim($home_dir, '/') . '/' . $dir['directory_path'];
            @unlink($full_dir . '/.htpasswd');
            @unlink($full_dir . '/.htaccess');
            $db->prepare("DELETE FROM directory_protection WHERE id = ? AND user_id = ?")
               ->execute([$id, $user_id]);
            flash('success', 'Directory protection removed');
        }
    }
    redirect('/cpanel/directory-privacy.php');
}

$dirs = $db->prepare("SELECT * FROM directory_protection WHERE user_id = ? ORDER BY created_at DESC");
$dirs->execute([$user_id]);
$dirs = $dirs->fetchAll(PDO::FETCH_ASSOC);

$nav = 'directoryprivacy';
$page_title = 'Directory Privacy';
require_once __DIR__ . '/../templates/header.php';

$active_dirs = count(array_filter($dirs, function ($d) { return $d['status'] === 'active'; }));
$disabled_dirs = count($dirs) - $active_dirs;
$month_count = 0;
foreach ($dirs as $d) {
    if (date('Y-m', strtotime($d['created_at'])) === date('Y-m')) $month_count++;
}
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="folder-lock" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Directory Privacy</div>
        <div class="hero-desc">Restrict access to any folder with a username and password using Apache basic authentication (.htaccess + .htpasswd).</div>
    </div>
    <div class="hero-actions"><span class="badge badge-active"><?= $active_dirs ?> protected</span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="folder-lock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($dirs) ?></div>
            <div class="stat-label">Protected Directories</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">password-gated folders</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="lock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_dirs ?></div>
            <div class="stat-label">Active</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">enforcing basic auth</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="unlock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $disabled_dirs ?></div>
            <div class="stat-label">Disabled</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">access currently open</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="calendar-plus" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $month_count ?></div>
            <div class="stat-label">Added This Month</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= date('F Y') ?></div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="lock" class="lucide"></i> Protect a Directory</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Directory Path <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:0">
                    <span style="display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-right:0;border-radius:var(--radius-sm) 0 0 var(--radius-sm);font-size:12px;color:var(--text3);white-space:nowrap">home/</span>
                    <input type="text" name="directory_path" id="dpDirInput" required placeholder="public_html/admin" style="border-radius:0 var(--radius-sm) var(--radius-sm) 0">
                </div>
                <div class="dp-chips">
                    <span class="dp-chip-label">Common targets</span>
                    <button type="button" class="dp-chip" data-path="public_html/admin">public_html/admin</button>
                    <button type="button" class="dp-chip" data-path="public_html/uploads">public_html/uploads</button>
                    <button type="button" class="dp-chip" data-path="public_html">public_html</button>
                </div>
            </div>
            <div class="form-group">
                <label>Username <span style="color:var(--danger)">*</span></label>
                <input type="text" name="protect_username" required placeholder="admin_user">
            </div>
            <div class="form-group">
                <label>Password <span style="color:var(--danger)">*</span></label>
                <input type="password" name="protect_password" required placeholder="Enter a strong password" minlength="6">
            </div>
            <div class="form-group">
                <label>Realm Name</label>
                <input type="text" name="realm" value="Restricted Area" placeholder="e.g. Admin Panel">
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Shown in the browser login prompt</div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><i data-lucide="shield-plus" class="lucide"></i> Add Protection</button>
            </div>
        </form>
    </div>
</div>

<div class="tip-card tip-red fade-in-delay-1">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong>Stay secure.</strong> Use a different username than your ZRPanel login and a strong password &mdash; the hash is stored in <code class="chip-mono" style="vertical-align:middle">.htpasswd</code> inside the protected folder. Disabling temporarily may leave the folder open to everyone.</div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="folder-lock" class="lucide"></i> Protected Directories (<?= count($dirs) ?>)</h3></div>
    <?php if (!empty($dirs)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="dpSearch" placeholder="Search directories, users..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="dpCount"><?= count($dirs) ?> folder<?= count($dirs) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($dirs) ? '14px' : '16px' ?>">
        <?php if (empty($dirs)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="unlock" class="lucide"></i></div>
                <strong>No protected directories</strong>
                <p>Add password protection above to restrict access to a directory.</p>
            </div>
        <?php else: ?>
        <div class="dp-list">
            <?php foreach ($dirs as $d): ?>
                <div class="dp-row" data-name="<?= h(strtolower($d['directory_path'] . ' ' . $d['protect_username'] . ' ' . $d['realm'] . ' ' . $d['status'])) ?>">
                    <span class="dp-ic <?= $d['status'] !== 'active' ? 'is-off' : '' ?>"><i data-lucide="folder-lock" class="lucide"></i></span>
                    <div class="dp-main">
                        <div class="dp-name"><code>/<?= h($d['directory_path']) ?></code></div>
                        <div class="dp-meta">
                            <span class="dp-user">
                                <code><?= h($d['protect_username']) ?></code>
                                <button type="button" class="dp-copy" data-copy="<?= h($d['protect_username']) ?>" title="Copy username"><i data-lucide="copy" class="lucide"></i></button>
                            </span>
                            <span class="chip-mono"><?= h($d['realm']) ?></span>
                            <span>Created <?= date('M d, Y', strtotime($d['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="dp-state">
                        <span class="badge <?= $d['status'] === 'active' ? 'badge-active' : 'badge-suspended' ?>"><?= h(ucfirst($d['status'])) ?></span>
                    </div>
                    <div class="dp-actions">
                        <form method="POST" id="dpTgl<?= $d['id'] ?>" style="display:none">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                        </form>
                        <label class="sec-switch" title="<?= $d['status'] === 'active' ? 'Disable protection' : 'Enable protection' ?>">
                            <input type="checkbox" <?= $d['status'] === 'active' ? 'checked' : '' ?> onchange="document.getElementById('dpTgl<?= $d['id'] ?>').submit()">
                            <span class="sec-switch-track"><span class="sec-switch-knob"></span></span>
                        </label>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove password protection from this directory? Anyone will be able to view its contents.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card fade-in-delay-3">
    <div class="card-header"><h3><i data-lucide="info" class="lucide"></i> How It Works</h3></div>
    <div class="card-body">
        <div class="dp-info-list">
            <div class="dp-info-item">
                <span class="dp-info-ic icon-purple"><i data-lucide="file-text" class="lucide"></i></span>
                <div>
                    <div class="dp-info-title">.htaccess</div>
                    <div class="dp-info-text">Apache directives that enforce basic authentication on the directory.</div>
                </div>
            </div>
            <div class="dp-info-item">
                <span class="dp-info-ic icon-purple"><i data-lucide="key" class="lucide"></i></span>
                <div>
                    <div class="dp-info-title">.htpasswd</div>
                    <div class="dp-info-text">Stores the username and an encrypted password hash for HTTP basic authentication.</div>
                </div>
            </div>
            <div class="dp-info-item">
                <span class="dp-info-ic icon-orange"><i data-lucide="lock" class="lucide"></i></span>
                <div>
                    <div class="dp-info-title">Disabling keeps files</div>
                    <div class="dp-info-text">Toggling protection only rewrites .htaccess to allow all &mdash; your folder and .htpasswd stay on disk until you delete the rule.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.dp-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:9px}
.dp-chip-label{font-size:11px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px}
.dp-chip{padding:4px 10px;border:1px solid var(--border);border-radius:99px;background:var(--bg2);color:var(--text3);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:'Fira Code',monaco,consolas,monospace}
.dp-chip:hover{border-color:#7C3AED;color:#7C3AED}
.dp-list{display:flex;flex-direction:column;gap:10px}
.dp-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.dp-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.dp-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6d28d9,#7C3AED 55%,#a78bfa);color:#fff;box-shadow:0 4px 10px rgba(124,58,237,.18)}
.dp-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.dp-ic .lucide{width:19px;height:19px}
.dp-main{min-width:0;flex:1}
.dp-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dp-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.dp-meta .badge{font-size:10px;padding:3px 8px}
.dp-user{display:inline-flex;align-items:center;gap:4px}
.dp-user code{font-weight:600;font-size:12px;color:var(--text2);font-family:'Fira Code',monaco,consolas,monospace}
.dp-copy{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.dp-copy .lucide{width:11px;height:11px}
.dp-copy:hover{background:rgba(124,58,237,.1);color:#7C3AED}
.dp-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.dp-state{flex-shrink:0}
.dp-actions{display:flex;gap:8px;align-items:center;flex-shrink:0}
.sec-switch{display:inline-flex;cursor:pointer;flex-shrink:0}
.sec-switch input{display:none}
.sec-switch-track{width:34px;height:20px;border-radius:99px;background:var(--bg4);border:1.5px solid var(--border);position:relative;transition:background .2s,border-color .2s;display:inline-block}
.sec-switch-knob{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.sec-switch input:checked + .sec-switch-track{background:#6366f1;border-color:#6366f1}
.sec-switch input:checked + .sec-switch-track .sec-switch-knob{transform:translateX(14px)}
.dp-info-list{display:grid;gap:14px}
.dp-info-item{display:flex;gap:12px;align-items:flex-start}
.dp-info-ic{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dp-info-ic .lucide{width:17px;height:17px}
.dp-info-title{font-size:13px;font-weight:600;color:var(--text)}
.dp-info-text{font-size:12px;color:var(--text3);margin-top:2px}
@media(max-width:720px){
  .dp-row{flex-wrap:wrap}
  .dp-main{flex-basis:100%}
  .dp-actions,.dp-state{margin-left:0}
  .dp-actions{width:100%;justify-content:flex-end}
}
</style>

<script>
(function () {
    document.querySelectorAll('.dp-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            var input = document.getElementById('dpDirInput');
            if (input) input.value = c.getAttribute('data-path');
        });
    });
    var q = document.getElementById('dpSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.dp-row'));
        var c = document.getElementById('dpCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' folder' + (rows.length === 1 ? '' : 's');
        });
    }
    document.querySelectorAll('.dp-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-copy') || '';
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(val).then(function () { flashCopy(btn); }).catch(function () { fallbackCopy(val, btn); });
            } else {
                fallbackCopy(val, btn);
            }
        });
    });
    function fallbackCopy(val, btn) {
        var ta = document.createElement('textarea');
        ta.value = val;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        flashCopy(btn);
    }
    function flashCopy(btn) {
        var icon = btn.querySelector('.lucide');
        var old = icon ? icon.getAttribute('data-lucide') : 'copy';
        if (icon) icon.setAttribute('data-lucide', 'check');
        btn.classList.add('copied');
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({root: btn});
        setTimeout(function () {
            btn.classList.remove('copied');
            if (icon) icon.setAttribute('data-lucide', old);
            if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({root: btn});
        }, 1200);
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>