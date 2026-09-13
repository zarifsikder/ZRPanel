<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('ftp_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir, domain FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$ftp_host = $user['domain'] ?: SITE_DOMAIN;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim($_POST['ftp_username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!empty($username) && !empty($password)) {
            $full_user = $user['username'] . '_' . $username;
            $exists = $db->prepare("SELECT id FROM ftp_accounts WHERE username = ? AND user_id = ?");
            $exists->execute([$full_user, $user_id]);
            if (!$exists->fetch()) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $home = ($user['home_dir'] ?? '') . '/public_html';
                $db->prepare("INSERT INTO ftp_accounts (user_id, username, password, home_dir) VALUES (?, ?, ?, ?)")
                   ->execute([$user_id, $full_user, $hash, $home]);
                flash('success', "FTP account '{$full_user}' created");
            } else {
                flash('error', 'FTP account already exists');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM ftp_accounts WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'FTP account deleted');
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $new_pass = $_POST['new_password'] ?? '';
        if ($id > 0 && !empty($new_pass)) {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $db->prepare("UPDATE ftp_accounts SET password = ? WHERE id = ? AND user_id = ?")->execute([$hash, $id, $user_id]);
            flash('success', 'Password updated');
        }
    }
    redirect('/cpanel/ftp.php');
}

$accounts = $db->prepare("SELECT * FROM ftp_accounts WHERE user_id = ? ORDER BY created_at DESC");
$accounts->execute([$user_id]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

$nav = 'ftp';
$page_title = 'FTP Accounts';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon green"><i data-lucide="hard-drive-upload" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">FTP Accounts</div>
        <div class="hero-desc">Create FTP logins for uploading and managing your website files with clients like FileZilla.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-green"><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></span></div>
</div>

<?php
$this_month = date('Y-m');
$month_count = 0;
$active_count = 0;
foreach ($accounts as $a) {
    if (date('Y-m', strtotime($a['created_at'])) === $this_month) $month_count++;
    if ($a['status'] === 'active') $active_count++;
}
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($accounts) ?></div>
            <div class="stat-label">FTP Accounts</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">login credentials issued</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_count ?></div>
            <div class="stat-label">Active Logins</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">ready to connect</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="calendar-plus" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $month_count ?></div>
            <div class="stat-label">Created This Month</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= date('F Y') ?></div>
        </div>
    </div>
</div>

<div class="grid-2 fade-in-delay-2">
    <div class="card">
        <div class="card-header"><h3><i data-lucide="user-plus" class="lucide"></i> Create FTP Account</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Username</label>
                    <div style="display:flex;gap:0">
                        <input type="text" name="ftp_username" id="ftpUser" required placeholder="e.g. files" pattern="[a-zA-Z0-9_]{3,32}" style="border-radius:var(--radius-sm) 0 0 var(--radius-sm)">
                        <span style="display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-left:0;border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-size:13px;color:var(--text3);white-space:nowrap">@<?= h($user['username']) ?></span>
                    </div>
                    <div class="ftp-preview">
                        <span style="font-size:11px;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;font-weight:700">Full login</span>
                        <code id="ftpFullUser"><?= h($user['username']) ?>_files</code>
                    </div>
                    <div class="form-hint" style="margin-top:8px"><i data-lucide="info" class="lucide"></i> 3&ndash;32 letters, numbers or underscores. The panel prefix is added automatically.</div>
                </div>
                <div class="form-group" style="margin-top:14px">
                    <label>Password</label>
                    <div style="display:flex;gap:8px">
                        <input type="password" name="password" id="ftpPass" required minlength="6" placeholder="Min 6 characters" style="flex:1">
                        <button type="button" class="btn btn-ghost ftp-gen" id="ftpGen" title="Generate strong password"><i data-lucide="dice-5" class="lucide"></i></button>
                    </div>
                    <div class="form-hint"><i data-lucide="key-round" class="lucide"></i> Use a strong, unique password &mdash; it can be reset anytime below.</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px"><i data-lucide="user-plus" class="lucide"></i> Create Account</button>
            </form>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px;padding-top:14px;border-top:1px dashed var(--border)">
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3)"><i data-lucide="folder" class="lucide" style="width:14px;height:14px;color:var(--green)"></i> New accounts start in <code class="chip-mono">~/public_html</code></div>
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3)"><i data-lucide="key-round" class="lucide" style="width:14px;height:14px;color:var(--green)"></i> Passwords are stored hashed and never shown again</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i data-lucide="cable" class="lucide"></i> Connection Details</h3></div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--text3);margin:0 0 14px">Use these details in your FTP client:</p>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="kv-row"><span class="kv-key"><i data-lucide="globe" class="lucide"></i> Host</span><span class="kv-val mono"><?= h($ftp_host) ?></span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="plug" class="lucide"></i> Port</span><span class="kv-val mono">21</span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="lock" class="lucide"></i> Encryption</span><span class="kv-val mono">Explicit FTPS</span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="layers" class="lucide"></i> Mode</span><span class="kv-val mono">Passive</span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="user" class="lucide"></i> Username</span><span class="kv-val mono"><?= h($user['username']) ?>_&lt;name&gt;</span></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);font-size:12px;color:var(--text4)">
                <i data-lucide="info" class="lucide" style="width:14px;height:14px;color:var(--green);flex-shrink:0"></i>
                <span>If your client defaults to Passive mode on, "Explicit FTPS" keeps transfers working through firewalls.</span>
            </div>
        </div>
    </div>
</div>

<div class="tip-card tip-green fade-in-delay-2">
    <i data-lucide="lightbulb" class="lucide"></i>
    <div class="tip-body"><strong>Connect.</strong> Use your domain as host, port <strong>21</strong>, and the full username shown below. New accounts start in <code>public_html</code>.</div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="users" class="lucide"></i> FTP Accounts (<?= count($accounts) ?>)</h3></div>
    <?php if (!empty($accounts)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="ftpSearch" placeholder="Search usernames, directories..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="ftpCount"><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($accounts) ? '14px' : '16px' ?>">
        <?php if (empty($accounts)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="hard-drive-upload" class="lucide"></i></div>
                <strong>No FTP accounts yet</strong>
                <p>Create your first FTP account above to upload files with an FTP client.</p>
            </div>
        <?php else: ?>
        <div class="ftp-list">
            <?php foreach ($accounts as $a): ?>
                <?php $rel_home = str_replace(($user['home_dir'] ?? ''), '~', $a['home_dir']); ?>
                <div class="ftp-row" data-name="<?= h(strtolower($a['username'] . ' ' . $rel_home . ' ' . $a['status'])) ?>">
                    <span class="ftp-ic <?= $a['status'] !== 'active' ? 'is-off' : '' ?>"><i data-lucide="user" class="lucide"></i></span>
                    <div class="ftp-main">
                        <div class="ftp-name">
                            <code><?= h($a['username']) ?></code>
                            <button type="button" class="ftp-copy" data-copy="<?= h($a['username']) ?>" title="Copy username"><i data-lucide="copy" class="lucide"></i></button>
                        </div>
                        <div class="ftp-meta">
                            <code class="chip-mono"><?= h($rel_home) ?></code>
                            <?php if ($a['status'] === 'active'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                            <?php else: ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><?= h(ucfirst($a['status'])) ?></span>
                            <?php endif; ?>
                            <span>Created <?= date('M d, Y', strtotime($a['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="ftp-actions">
                        <form method="POST" style="display:inline-flex;gap:6px;align-items:center" onsubmit="return confirm('Reset the password for this account?')">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <input type="password" name="new_password" placeholder="New pass" required minlength="6" class="ftp-pass">
                            <button type="submit" class="btn btn-sm btn-info" title="Reset password"><i data-lucide="key-round" class="lucide"></i> Reset</button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this FTP account? Connections using it will stop working immediately.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
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
.ftp-preview{display:flex;align-items:center;gap:10px;margin-top:10px;background:var(--bg2);border:1px dashed var(--border);border-radius:var(--radius-sm);padding:8px 10px}
.ftp-preview code{display:flex;align-items:center;gap:8px;font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ftp-list{display:flex;flex-direction:column;gap:10px}
.ftp-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.ftp-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.ftp-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#047857,#059669 55%,#10b981);color:#fff;box-shadow:0 4px 10px rgba(5,150,105,.18)}
.ftp-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.ftp-ic .lucide{width:19px;height:19px}
.ftp-main{min-width:0;flex:1}
.ftp-name{display:flex;align-items:center;gap:7px}
.ftp-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ftp-copy{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.ftp-copy .lucide{width:12px;height:12px}
.ftp-copy:hover{background:rgba(5,150,105,.1);color:var(--green)}
.ftp-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.ftp-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.ftp-meta .badge{font-size:10px;padding:3px 8px}
.ftp-actions{display:flex;gap:6px;align-items:center;flex-shrink:0}
.ftp-pass{width:120px;padding:7px 10px;border:1.5px solid var(--border);border-radius:var(--radius-xs);font-size:12px;background:var(--bg);color:var(--text);outline:none;transition:border-color .15s}
.ftp-pass:focus{border-color:var(--primary)}
@media(max-width:720px){
  .ftp-row{flex-wrap:wrap}
  .ftp-main{flex-basis:100%}
  .ftp-actions{width:100%;flex-wrap:wrap;justify-content:flex-end}
  .ftp-pass{flex:1}
}
</style>

<script>
(function () {
    var user = <?= json_encode($user['username']) ?>;
    var uInput = document.getElementById('ftpUser');
    var uPrev = document.getElementById('ftpFullUser');
    if (uInput && uPrev) {
        var show = function () {
            var v = (uInput.value || '').trim();
            uPrev.textContent = user + '_' + (v || 'files');
        };
        uInput.addEventListener('input', show);
        show();
    }
    var gen = document.getElementById('ftpGen');
    var pass = document.getElementById('ftpPass');
    if (gen && pass) {
        gen.addEventListener('click', function () {
            var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%^&*';
            var p = '';
            for (var i = 0; i < 16; i++) p += chars.charAt(Math.floor(Math.random() * chars.length));
            pass.value = p;
            pass.type = 'text';
            pass.focus();
            setTimeout(function () { pass.type = 'password'; }, 4000);
        });
    }
    var q = document.getElementById('ftpSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.ftp-row'));
        var c = document.getElementById('ftpCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' account' + (rows.length === 1 ? '' : 's');
        });
    }
    document.querySelectorAll('.ftp-copy').forEach(function (btn) {
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
