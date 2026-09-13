<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$home_dir = $user['home_dir'] ?? '/home/' . $user['username'];

function scan_dirs($path, $depth = 0, $max = 4) {
    $dirs = [];
    if ($depth >= $max || !is_dir($path)) return $dirs;
    $items = @scandir($path);
    if (!$items) return $dirs;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $path . '/' . $item;
        if (is_dir($full) && substr($item, 0, 1) !== '.') {
            $dirs[] = ['path' => $full, 'name' => str_repeat('  ', $depth) . $item, 'depth' => $depth];
            $dirs = array_merge($dirs, scan_dirs($full, $depth + 1, $max));
        }
    }
    return $dirs;
}

function write_htaccess($dir, $realm, $active) {
    $htaccess = $dir . '/.htaccess';
    if ($active) {
        $content = "AuthType Basic\nAuthName \"{$realm}\"\nAuthUserFile \"{$dir}/.htpasswd\"\nRequire valid-user\n";
        @file_put_contents($htaccess, $content);
    } else {
        if (file_exists($htaccess)) {
            $current = @file_get_contents($htaccess);
            if (strpos($current, 'AuthType Basic') !== false) {
                @unlink($htaccess);
            }
        }
    }
}

function write_htpasswd($dir, $prot_user, $password) {
    $htpasswd = $dir . '/.htpasswd';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    @file_put_contents($htpasswd, $prot_user . ':' . $hash . "\n");
}

function remove_htpasswd($dir) {
    $htpasswd = $dir . '/.htpasswd';
    if (file_exists($htpasswd)) @unlink($htpasswd);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $dir_path = trim($_POST['directory_path'] ?? '');
        $prot_user = trim($_POST['protect_username'] ?? '');
        $prot_pass = $_POST['protect_password'] ?? '';
        $realm = trim($_POST['realm'] ?? 'Restricted Area');

        if (!empty($dir_path) && !empty($prot_user) && !empty($prot_pass)) {
            if (strpos($dir_path, $home_dir) !== 0) {
                flash('error', 'Directory must be within your home directory');
            } elseif (!is_dir($dir_path)) {
                flash('error', 'Directory does not exist');
            } else {
                $check = $db->prepare("SELECT id FROM directory_protection WHERE directory_path = ? AND user_id = ?");
                $check->execute([$dir_path, $user_id]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $db->prepare("UPDATE directory_protection SET protect_username = ?, protect_password = ?, realm = ?, status = 'active' WHERE id = ? AND user_id = ?")
                       ->execute([$prot_user, password_hash($prot_pass, PASSWORD_BCRYPT), $realm, $existing['id'], $user_id]);
                    write_htaccess($dir_path, $realm, true);
                    write_htpasswd($dir_path, $prot_user, $prot_pass);
                    flash('success', 'Protection updated for ' . str_replace($home_dir, '~', $dir_path));
                } else {
                    $db->prepare("INSERT INTO directory_protection (user_id, directory_path, protect_username, protect_password, realm) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$user_id, $dir_path, $prot_user, password_hash($prot_pass, PASSWORD_BCRYPT), $realm]);
                    write_htaccess($dir_path, $realm, true);
                    write_htpasswd($dir_path, $prot_user, $prot_pass);
                    flash('success', 'Directory protected: ' . str_replace($home_dir, '~', $dir_path));
                }
            }
        } else {
            flash('error', 'All fields are required');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt2 = $db->prepare("SELECT * FROM directory_protection WHERE id = ? AND user_id = ?");
        $stmt2->execute([$id, $user_id]);
        $rec = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($rec) {
            $new_status = $rec['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE directory_protection SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            write_htaccess($rec['directory_path'], $rec['realm'], $new_status === 'active');
            if ($new_status === 'active') {
                $htpasswd_file = $rec['directory_path'] . '/.htpasswd';
                @file_put_contents($htpasswd_file, $rec['protect_username'] . ':' . $rec['protect_password'] . "\n");
            } else {
                remove_htpasswd($rec['directory_path']);
            }
            flash('success', 'Protection ' . $new_status);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt2 = $db->prepare("SELECT directory_path FROM directory_protection WHERE id = ? AND user_id = ?");
        $stmt2->execute([$id, $user_id]);
        $rec = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($rec) {
            write_htaccess($rec['directory_path'], '', false);
            remove_htpasswd($rec['directory_path']);
            $db->prepare("DELETE FROM directory_protection WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            flash('success', 'Protection removed');
        }
    }
    redirect('/cpanel/privacy.php');
}

$protections = $db->prepare("SELECT * FROM directory_protection WHERE user_id = ? ORDER BY created_at DESC");
$protections->execute([$user_id]);
$protections = $protections->fetchAll(PDO::FETCH_ASSOC);

$dirs = scan_dirs($home_dir);

$nav = 'privacy';
$page_title = 'Directory Privacy';
require_once __DIR__ . '/../templates/header.php';

$active_protections = count(array_filter($protections, function ($p) { return $p['status'] === 'active'; }));
$total_protections = count($protections);
$disabled_protections = $total_protections - $active_protections;
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="lock" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Directory Privacy</div>
        <div class="hero-desc">Password-protect folders on your site with HTTP Basic authentication &mdash; visitors must log in before viewing content.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-purple" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="shield-check" class="lucide"></i> <?= $active_protections ?> protected</span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="folder-lock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_protections ?></div>
            <div class="stat-label">Protected Directories</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">guard rules configured</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="lock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_protections ?></div>
            <div class="stat-label">Active Gates</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">password required to view</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-2">
        <div class="stat-icon icon-orange"><i data-lucide="lock-open" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $disabled_protections ?></div>
            <div class="stat-label">Disabled</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">saved but turned off</div>
        </div>
    </div>
</div>

<div class="tip-card tip-blue fade-in-delay-1">
    <i data-lucide="lightbulb" class="lucide"></i>
    <div class="tip-body">
        <strong>How it works.</strong> WordPress and most web apps respect <code class="chip-mono">.htaccess</code> Basic Auth. Once a directory is protected, the browser shows a login prompt and only visitors with the username and password you set can open any file inside it.
    </div>
</div>

<div class="card fade-in">
    <div class="card-header"><h3><i data-lucide="lock-plus" class="lucide"></i> Password Protect a Directory</h3></div>
    <div class="card-body">
        <?php if (empty($dirs)): ?>
        <div class="tip-card tip-orange" style="margin-bottom:16px">
            <i data-lucide="folder-search" class="lucide"></i>
            <div class="tip-body"><strong>No directories found.</strong> Create a folder inside your home directory first, then come back to protect it.</div>
        </div>
        <?php endif; ?>
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="create">
            <div class="form-group" style="grid-column:1/-1">
                <label>Directory <span class="label-req">*</span></label>
                <select name="directory_path" required>
                    <option value="">-- Select a Directory --</option>
                    <?php foreach ($dirs as $d): ?>
                        <option value="<?= h($d['path']) ?>"><?= h(str_repeat('&nbsp;&nbsp;', $d['depth']) . $d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Only folders within your home directory are listed</div>
            </div>
            <div class="form-group">
                <label>Username <span class="label-req">*</span></label>
                <input type="text" name="protect_username" required placeholder="e.g. admin" pattern="[a-zA-Z0-9_\-]{1,64}" autocomplete="off">
                <div class="form-hint"><i data-lucide="user" class="lucide"></i> Letters, numbers, dashes and underscores only</div>
            </div>
            <div class="form-group">
                <label>Password <span class="label-req">*</span></label>
                <input type="password" name="protect_password" required minlength="6" placeholder="Min 6 characters" autocomplete="new-password">
                <div class="form-hint"><i data-lucide="key-round" class="lucide"></i> Visitors will enter this to view the protected folder</div>
            </div>
            <div class="form-group">
                <label>Realm Name</label>
                <input type="text" name="realm" value="Restricted Area" placeholder="e.g. Members Only">
                <div class="form-hint"><i data-lucide="message-square-text" class="lucide"></i> Shown in the browser login prompt</div>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary" style="width:100%"><i data-lucide="shield-check" class="lucide"></i> Protect Directory</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="folder-lock" class="lucide"></i> Protected Directories (<?= count($protections) ?>)</h3></div>
    <?php if (!empty($protections)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="prvSearch" placeholder="Search directories..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="prvCount"><?= count($protections) ?> rule<?= count($protections) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($protections) ? '14px' : '16px' ?>">
        <?php if (empty($protections)): ?>
            <div class="empty-state" style="padding:14px 0 18px">
                <div class="empty-state-icon"><i data-lucide="lock-open" class="lucide"></i></div>
                <strong>No protected directories</strong>
                <p>All your folders are publicly accessible. Protect sensitive areas like /admin or /private above.</p>
            </div>
        <?php else: ?>
        <div class="prv-list">
            <?php foreach ($protections as $p): ?>
                <?php $rel_path = str_replace($home_dir, '~', $p['directory_path']); ?>
                <div class="prv-row" data-name="<?= h(strtolower($rel_path . ' ' . $p['protect_username'] . ' ' . $p['realm'] . ' ' . $p['status'])) ?>">
                    <span class="prv-ic <?= $p['status'] === 'active' ? '' : 'is-off' ?>"><i data-lucide="lock" class="lucide"></i></span>
                    <div class="prv-main">
                        <code class="prv-path"><?= h($rel_path) ?></code>
                        <div class="prv-meta">
                            <?php if ($p['status'] === 'active'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                            <?php else: ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><span class="dot gray"></span> Disabled</span>
                            <?php endif; ?>
                            <span><i data-lucide="user" class="lucide"></i><?= h($p['protect_username']) ?></span>
                            <span title="Realm"><?= h($p['realm']) ?></span>
                            <span>Created <?= date('M d, Y', strtotime($p['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="prv-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <?php if ($p['status'] === 'active'): ?>
                                <button type="submit" class="btn btn-sm btn-warning" title="Disable protection"><i data-lucide="lock-open" class="lucide"></i> Disable</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-success" title="Enable protection"><i data-lucide="lock" class="lucide"></i> Enable</button>
                            <?php endif; ?>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove protection from this directory? Anyone will be able to view its contents.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
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
.prv-list{display:flex;flex-direction:column;gap:10px}
.prv-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.prv-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.prv-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6d28d9,#8b5cf6 55%,#a78bfa);color:#fff;box-shadow:0 4px 10px rgba(139,92,246,.18)}
.prv-ic.is-off{background:linear-gradient(135deg,#64748b,#94a3b8 55%,#cbd5e1);box-shadow:0 4px 10px rgba(148,163,184,.18)}
.prv-ic .lucide{width:19px;height:19px}
.prv-main{min-width:0;flex:1}
.prv-path{font-family:'Fira Code',monaco,consolas,monospace;font-weight:700;font-size:13px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
.prv-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.prv-meta .badge{font-size:10px;padding:3px 8px}
.prv-meta .lucide{width:12px;height:12px;vertical-align:-2px}
.prv-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:640px){
  .prv-row{flex-wrap:wrap}
  .prv-main{flex-basis:100%}
  .prv-actions{width:100%}
  .prv-actions form{flex:1}
  .prv-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var input = document.getElementById('prvSearch');
    var count = document.getElementById('prvCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.prv-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' rule' + (shown === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>