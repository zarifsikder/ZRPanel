<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $dir_path = trim($_POST['directory_path'] ?? '');
        $max_logins = max(1, (int)($_POST['max_logins'] ?? 5));
        $time_window = max(60, (int)($_POST['time_window'] ?? 7200));
        $leech_action = $_POST['leech_action'] ?? 'block';
        $redirect_url = trim($_POST['redirect_url'] ?? '');

        if (empty($dir_path)) {
            flash('error', 'Directory path is required.');
            redirect('/cpanel/leech-protection.php');
        }

        $valid_actions = ['block', 'redirect', 'notify'];
        if (!in_array($leech_action, $valid_actions)) {
            $leech_action = 'block';
        }

        if ($leech_action === 'redirect' && empty($redirect_url)) {
            flash('error', 'Redirect URL is required when using redirect action.');
            redirect('/cpanel/leech-protection.php');
        }

        $dir_path = ltrim($dir_path, '/');

        $exists = $db->prepare("SELECT id FROM leech_protection WHERE directory_path = ? AND user_id = ?");
        $exists->execute([$dir_path, $user_id]);
        if ($exists->fetch()) {
            flash('error', 'Leech protection already exists for this directory.');
            redirect('/cpanel/leech-protection.php');
        }

        $db->prepare("INSERT INTO leech_protection (user_id, directory_path, max_logins, time_window, action, redirect_url, status) VALUES (?, ?, ?, ?, ?, ?, 'active')")
           ->execute([$user_id, $dir_path, $max_logins, $time_window, $leech_action, $redirect_url ?: null]);
        flash('success', "Leech protection added for /{$dir_path}");

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM leech_protection WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rule) {
            $new_status = $rule['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE leech_protection SET status = ? WHERE id = ?")
               ->execute([$new_status, $id]);
            flash('success', 'Rule ' . ($new_status === 'active' ? 'enabled' : 'disabled'));
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM leech_protection WHERE id = ? AND user_id = ?")
           ->execute([$id, $user_id]);
        flash('success', 'Leech protection rule deleted');
    }
    redirect('/cpanel/leech-protection.php');
}

$rules = $db->prepare("SELECT * FROM leech_protection WHERE user_id = ? ORDER BY created_at DESC");
$rules->execute([$user_id]);
$rules = $rules->fetchAll(PDO::FETCH_ASSOC);

$nav = 'leechprotection';
$page_title = 'Leech Protection';
require_once __DIR__ . '/../templates/header.php';

$active_rules = count(array_filter($rules, function ($r) { return $r['status'] === 'active'; }));
$disabled_rules = count($rules) - $active_rules;
$unique_dirs = count(array_unique(array_column($rules, 'directory_path')));
$block_count = 0;
$redirect_count = 0;
$notify_count = 0;
foreach ($rules as $r) {
    if ($r['action'] === 'block') $block_count++;
    elseif ($r['action'] === 'redirect') $redirect_count++;
    else $notify_count++;
}
?>

<div class="page-hero fade-in">
    <div class="hero-icon orange"><i data-lucide="users-round" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Leech Protection</div>
        <div class="hero-desc">Stop bandwidth theft from hotlinked files and shared download credentials by limiting access attempts per time window.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-active"><?= $active_rules ?> of <?= count($rules) ?> active</span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="users-round" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($rules) ?></div>
            <div class="stat-label">Protection Rules</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $unique_dirs ?> unique folder<?= $unique_dirs === 1 ? '' : 's' ?> locked down</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="shield-check" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_rules ?></div>
            <div class="stat-label">Active</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">enforced right now</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="pause" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $disabled_rules ?></div>
            <div class="stat-label">Disabled</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">limits paused</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="list" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $block_count ?>/<?= $redirect_count ?>/<?= $notify_count ?></div>
            <div class="stat-label">Block / Redirect / Notify</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">action breakdown</div>
        </div>
    </div>
</div>

<div class="tip-card tip-orange fade-in-delay-1">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong>How it works.</strong> When a visitor exceeds the allowed logins inside the window, the chosen action fires against them &mdash; a 403 block, a redirect to the URL you pick, or an email notification.</div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="plus" class="lucide"></i> Add Leech Protection Rule</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid" id="leechForm">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Directory Path <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:0">
                    <span style="display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-right:0;border-radius:var(--radius-sm) 0 0 var(--radius-sm);font-size:12px;color:var(--text3);white-space:nowrap">home/</span>
                    <input type="text" name="directory_path" id="lchDirInput" required placeholder="public_html/downloads" style="border-radius:0 var(--radius-sm) var(--radius-sm) 0">
                </div>
                <div class="lch-chips">
                    <span class="lch-chip-label">Common targets</span>
                    <button type="button" class="lch-chip" data-path="public_html/downloads">public_html/downloads</button>
                    <button type="button" class="lch-chip" data-path="public_html/uploads">public_html/uploads</button>
                    <button type="button" class="lch-chip" data-path="public_html">public_html</button>
                </div>
            </div>
            <div class="form-group">
                <label>Max Logins Per Window</label>
                <input type="number" name="max_logins" value="5" min="1" max="10000" inputmode="numeric">
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Maximum number of downloads allowed per time window</div>
            </div>
            <div class="form-group">
                <label>Time Window (seconds)</label>
                <input type="number" name="time_window" value="7200" min="60" max="86400" inputmode="numeric">
                <div class="form-hint"><i data-lucide="clock" class="lucide"></i> 7200 = 2 hours, 86400 = 24 hours</div>
            </div>
            <div class="form-group">
                <label>Action on Limit</label>
                <select name="leech_action" id="leechAction" onchange="toggleRedirect()">
                    <option value="block">Block (403 Forbidden)</option>
                    <option value="redirect">Redirect to URL</option>
                    <option value="notify">Notify via Email</option>
                </select>
            </div>
            <div class="form-group" id="redirectGroup" style="display:none">
                <label>Redirect URL</label>
                <input type="url" name="redirect_url" id="redirectUrl" placeholder="https://example.com/leech-caught">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><i data-lucide="shield-plus" class="lucide"></i> Add Rule</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> Protection Rules (<?= count($rules) ?>)</h3></div>
    <?php if (!empty($rules)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="lchSearch" placeholder="Search directories, actions..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="lchCount"><?= count($rules) ?> rule<?= count($rules) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($rules) ? '14px' : '16px' ?>">
        <?php if (empty($rules)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="shield" class="lucide"></i></div>
                <strong>No leech protection rules</strong>
                <p>Add a rule above to prevent bandwidth theft from your download directories.</p>
            </div>
        <?php else: ?>
        <div class="lch-list">
            <?php foreach ($rules as $r):
                $action_badges = ['block' => 'badge-suspended', 'redirect' => 'badge-pending', 'notify' => 'badge-blue'];
                $action_labels = ['block' => 'Block', 'redirect' => 'Redirect', 'notify' => 'Notify'];
                $tw = (int)$r['time_window'];
                if ($tw >= 86400) $tw_label = ($tw / 86400) . ' day';
                elseif ($tw >= 3600) $tw_label = ($tw / 3600) . ' hours';
                else $tw_label = ($tw / 60) . ' min';
            ?>
                <div class="lch-row" data-name="<?= h(strtolower($r['directory_path'] . ' ' . $r['action'] . ' ' . $r['status'] . ' ' . ($r['redirect_url'] ?? '') . ' ' . $tw_label)) ?>">
                    <span class="lch-ic <?= $r['status'] !== 'active' ? 'is-off' : '' ?>"><i data-lucide="folder" class="lucide"></i></span>
                    <div class="lch-main">
                        <div class="lch-name">/<?= h($r['directory_path']) ?></div>
                        <div class="lch-meta">
                            <span class="badge badge-blue"><?= (int)$r['max_logins'] ?> / window</span>
                            <span class="chip-mono"><?= $tw_label ?></span>
                            <span class="badge <?= $action_badges[$r['action']] ?? 'badge-blue' ?>"><?= $action_labels[$r['action']] ?? h($r['action']) ?></span>
                            <?php if ($r['action'] === 'redirect' && !empty($r['redirect_url'])): ?>
                                <span style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['redirect_url']) ?>"><?= h($r['redirect_url']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="lch-state">
                        <span class="badge <?= $r['status'] === 'active' ? 'badge-active' : 'badge-suspended' ?>"><?= h(ucfirst($r['status'])) ?></span>
                    </div>
                    <div class="lch-actions">
                        <form method="POST" id="lchTgl<?= $r['id'] ?>" style="display:none">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        </form>
                        <label class="sec-switch" title="<?= $r['status'] === 'active' ? 'Disable rule' : 'Enable rule' ?>">
                            <input type="checkbox" <?= $r['status'] === 'active' ? 'checked' : '' ?> onchange="document.getElementById('lchTgl<?= $r['id'] ?>').submit()">
                            <span class="sec-switch-track"><span class="sec-switch-knob"></span></span>
                        </label>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this leech protection rule? Visitors will no longer be limited in this directory.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
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
.lch-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:9px}
.lch-chip-label{font-size:11px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px}
.lch-chip{padding:4px 10px;border:1px solid var(--border);border-radius:99px;background:var(--bg2);color:var(--text3);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:'Fira Code',monaco,consolas,monospace}
.lch-chip:hover{border-color:var(--primary);color:var(--primary)}
.lch-list{display:flex;flex-direction:column;gap:10px}
.lch-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.lch-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.lch-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#b45309,#d97706 55%,#f59e0b);color:#fff;box-shadow:0 4px 10px rgba(217,119,6,.18)}
.lch-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.lch-ic .lucide{width:19px;height:19px}
.lch-main{min-width:0;flex:1}
.lch-name{font-weight:700;color:var(--text);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lch-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.lch-meta .badge{font-size:10px;padding:3px 8px}
.lch-state{flex-shrink:0}
.lch-actions{display:flex;gap:8px;align-items:center;flex-shrink:0}
.sec-switch{display:inline-flex;cursor:pointer;flex-shrink:0}
.sec-switch input{display:none}
.sec-switch-track{width:34px;height:20px;border-radius:99px;background:var(--bg4);border:1.5px solid var(--border);position:relative;transition:background .2s,border-color .2s;display:inline-block}
.sec-switch-knob{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.sec-switch input:checked + .sec-switch-track{background:#6366f1;border-color:#6366f1}
.sec-switch input:checked + .sec-switch-track .sec-switch-knob{transform:translateX(14px)}
@media(max-width:720px){
  .lch-row{flex-wrap:wrap}
  .lch-main{flex-basis:100%}
  .lch-actions,.lch-state{margin-left:0}
  .lch-actions{width:100%;justify-content:flex-end}
}
</style>

<script>
(function () {
    document.querySelectorAll('.lch-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            var input = document.getElementById('lchDirInput');
            if (input) input.value = c.getAttribute('data-path');
        });
    });
    var q = document.getElementById('lchSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.lch-row'));
        var c = document.getElementById('lchCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' rule' + (rows.length === 1 ? '' : 's');
        });
    }
})();
function toggleRedirect() {
    var sel = document.getElementById('leechAction');
    var grp = document.getElementById('redirectGroup');
    var url = document.getElementById('redirectUrl');
    if (sel.value === 'redirect') {
        grp.style.display = '';
        url.required = true;
    } else {
        grp.style.display = 'none';
        url.required = false;
    }
}
toggleRedirect();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>