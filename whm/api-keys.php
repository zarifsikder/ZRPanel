<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_api_keys');
init_db();

$pdo = db();
$userId = $_SESSION['user_id'];
$error = '';
$success = '';

// Generate new key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'reveal' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("SELECT key_secret FROM api_keys WHERE id = ? AND created_by = ? AND status = 'active'");
        $stmt->execute([(int)$_POST['id'], $userId]);
        $row = $stmt->fetch();
        $key = ($row && !empty($row['key_secret'])) ? panel_api_decrypt($row['key_secret']) : null;
        header('Content-Type: application/json');
        echo json_encode($key ? ['ok' => true, 'key' => $key] : ['ok' => false, 'error' => 'Key unavailable.']);
        exit;
    }

    if ($_POST['action'] === 'generate') {
        $label = trim($_POST['label'] ?? '');
        $permissions = trim($_POST['permissions'] ?? '*');
        if (empty($label)) {
            $error = 'Please enter a label for this API key.';
        } else {
            $rawKey = 'whm_' . bin2hex(random_bytes(20));
            $hash = hash('sha256', $rawKey);
            $stmt = $pdo->prepare("INSERT INTO api_keys (key_hash, prefix, label, permissions, created_by, key_secret) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$hash, 'whm_', $label, $permissions, $userId, panel_api_encrypt($rawKey)]);
            $newKey = $rawKey;
            $success = 'API key generated successfully. Copy it now — it will not be shown again.';
        }
    }

    if ($_POST['action'] === 'revoke' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE api_keys SET status = 'revoked' WHERE id = ? AND created_by = ?");
        $stmt->execute([(int)$_POST['id'], $userId]);
        $success = 'API key revoked.';
    }

    if ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ? AND created_by = ?");
        $stmt->execute([(int)$_POST['id'], $userId]);
        $success = 'API key deleted.';
    }
}

$keys = $pdo->prepare("SELECT * FROM api_keys WHERE created_by = ? ORDER BY created_at DESC");
$keys->execute([$userId]);
$keys = $keys->fetchAll();

$total_keys = count($keys);
$active_keys = 0;
$revoked_keys = 0;
foreach ($keys as $k) {
    if ($k['status'] === 'active') $active_keys++;
    else $revoked_keys++;
}

$nav = 'apikeys';
$page_title = 'API Keys';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/assets/style.css?v=20260731b">
    <script src="/assets/lucide.min.js"></script>
</head>
<body>
<?php require_once __DIR__ . '/../templates/header.php'; ?>

<div class="apikeys-page">
    <div class="page-hero fade-in">
        <div class="hero-icon purple"><i data-lucide="key-round" class="lucide"></i></div>
        <div class="hero-text">
            <div class="hero-title">API Key Management</div>
            <div class="hero-desc">Generate and manage API keys for external access to your WHM services. Keys are shown only once, so keep them safe.</div>
        </div>
        <div class="hero-actions">
            <span class="badge badge-purple"><i data-lucide="key-round" class="lucide"></i> <?= $total_keys ?> key<?= $total_keys === 1 ? '' : 's' ?></span>
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('generateModal').classList.add('open')">
                <i data-lucide="plus-circle" class="lucide"></i> Generate New Key
            </button>
        </div>
    </div>

    <div class="stats-grid fade-in-delay-1">
        <div class="stat-card stat-purple fade-in">
            <div class="stat-icon icon-purple"><i data-lucide="key-round" class="lucide"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?= $total_keys ?></div>
                <div class="stat-label">Total Keys</div>
                <div style="font-size:11px;color:var(--text4);margin-top:2px">created by this account</div>
            </div>
        </div>
        <div class="stat-card stat-green fade-in">
            <div class="stat-icon icon-green"><i data-lucide="check-circle" class="lucide"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?= $active_keys ?></div>
                <div class="stat-label">Active Keys</div>
                <div style="font-size:11px;color:var(--text4);margin-top:2px">currently valid</div>
            </div>
        </div>
        <div class="stat-card stat-orange fade-in">
            <div class="stat-icon icon-orange"><i data-lucide="shield-off" class="lucide"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?= $revoked_keys ?></div>
                <div class="stat-label">Revoked Keys</div>
                <div style="font-size:11px;color:var(--text4);margin-top:2px">no longer accepted</div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i data-lucide="alert-circle" class="lucide"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><i data-lucide="check-circle" class="lucide"></i> <?= h($success) ?></div>
    <?php endif; ?>

    <?php if (isset($newKey)): ?>
        <div class="alert alert-info" style="background:rgba(0,115,230,.1);border:1px solid rgba(0,115,230,.3);padding:16px;border-radius:8px;margin-bottom:16px">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <i data-lucide="key-round" class="lucide" style="color:#0073e6;flex-shrink:0;margin-top:2px"></i>
                <div style="flex:1">
                    <strong style="color:#0073e6">New API Key Generated</strong>
                    <p style="margin:4px 0;font-size:13px;color:var(--text-muted)">Copy this key now. For security, it will not be shown again.</p>
                    <div style="display:flex;gap:8px;margin-top:8px">
                        <code id="newApiKey" style="flex:1;padding:8px 12px;background:var(--surface);border:1px solid var(--border);border-radius:6px;font-size:13px;word-break:break-all"><?= h($newKey) ?></code>
                        <button class="btn btn-primary" onclick="copyKey()" style="white-space:nowrap">
                            <i data-lucide="copy" class="lucide"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card fade-in-delay-1">
        <div class="card-header"><h3><i data-lucide="key-round" class="lucide"></i> Your API Keys</h3></div>
        <?php if (!empty($keys)): ?>
        <div class="table-toolbar">
            <div class="toolbar-search">
                <i data-lucide="search" class="lucide"></i>
                <input type="text" id="keySearch" placeholder="Search keys..." autocomplete="off">
            </div>
            <span class="toolbar-count" id="keyCount"><?= $total_keys ?> key<?= $total_keys === 1 ? '' : 's' ?></span>
        </div>
        <?php endif; ?>
        <div class="card-body" style="padding:<?= empty($keys) ? '14px' : '16px' ?>">
            <?php if (empty($keys)): ?>
                <div class="empty-state" style="padding:10px 0 18px">
                    <div class="empty-state-icon"><i data-lucide="key-round" class="lucide"></i></div>
                    <strong>No API keys yet</strong>
                    <p>Generate one above to start connecting external services.</p>
                </div>
            <?php else: ?>
                <div class="key-list">
                <?php foreach ($keys as $k): ?>
                    <div class="key-row" data-name="<?= h($k['label'] . ' ' . $k['permissions']) ?>">
                        <span class="key-ic <?= $k['status'] === 'active' ? '' : 'is-revoked' ?>"><i data-lucide="<?= $k['status'] === 'active' ? 'key-round' : 'shield-off' ?>" class="lucide"></i></span>
                        <div class="key-main">
                            <div class="key-name">
                                <?= h($k['label']) ?>
                                <?php if ($k['status'] === 'active'): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-suspended">Revoked</span>
                                <?php endif; ?>
                            </div>
                            <div class="key-meta">
                                <code class="key-fingerprint" id="kfp-<?= (int)$k['id'] ?>"><?= h($k['prefix']) ?>...<?= h(substr($k['key_hash'], -8)) ?></code>
                                <code class="key-perm"><?= h($k['permissions']) ?></code>
                                <span title="Last used"><?= $k['last_used_at'] ? h($k['last_used_at']) : 'Never used' ?></span>
                                <span title="Created">Created <?= h($k['created_at']) ?></span>
                            </div>
                        </div>
                        <div class="key-actions">
                            <?php if ($k['status'] === 'active' && !empty($k['key_secret'])): ?>
                                <button type="button" class="btn btn-sm btn-ghost" onclick="toggleKey(<?= (int)$k['id'] ?>, this)">
                                    <i data-lucide="eye" class="lucide"></i> Show
                                </button>
                            <?php endif; ?>
                            <?php if ($k['status'] === 'active'): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Revoke this API key? This action cannot be undone.')">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i data-lucide="shield-off" class="lucide"></i> Revoke</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this API key permanently?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i data-lucide="trash-2" class="lucide"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
    .key-list{display:flex;flex-direction:column;gap:10px}
    .key-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
    .key-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
    .key-ic{width:42px;height:42px;min-width:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6d28d9,#7c3aed 55%,#a78bfa);color:#fff;box-shadow:0 4px 10px rgba(124,58,237,.18)}
    .key-ic.is-revoked{background:linear-gradient(135deg,#64748b,#94a3b8 55%,#cbd5e1);box-shadow:0 4px 10px rgba(100,116,139,.18)}
    .key-ic .lucide{width:19px;height:19px}
    .key-main{min-width:0;flex:1}
    .key-name{font-weight:700;color:var(--text);font-size:13.5px;display:flex;align-items:center;gap:8px}
    .key-name .badge{font-size:10px;padding:3px 8px}
    .key-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
    .key-fingerprint{font-family:'Fira Code',monaco,consolas,monospace;font-size:11.5px;color:var(--text2);background:var(--bg3);border:1px solid var(--border);padding:3px 8px;border-radius:6px}
    .key-perm{font-family:'Fira Code',monaco,consolas,monospace;font-size:10.5px;color:var(--primary);background:var(--primary-light);border:1px solid rgba(0,115,230,.18);padding:3px 8px;border-radius:6px}
    .key-actions{display:flex;gap:6px;flex-shrink:0}
    @media(max-width:640px){
      .key-row{flex-wrap:wrap}
      .key-main{flex-basis:100%}
      .key-actions{width:100%}
      .key-actions form{flex:1}
    }
    </style>
</div>

<!-- Generate Modal -->
<div class="modal-overlay" id="generateModal">
    <div class="modal-box">
        <div class="modal-top">
            <h3><i data-lucide="key-round" class="lucide"></i> Generate New API Key</h3>
            <button class="modal-x" onclick="document.getElementById('generateModal').classList.remove('open')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="generate">
            <div class="modal-body">
                <div class="form-group">
                    <label for="label">Label</label>
                    <input type="text" id="label" name="label" class="form-control" placeholder="e.g., My External Website" required autofocus>
                </div>
                <div class="form-group">
                    <label for="permissions">Permissions</label>
                    <select id="permissions" name="permissions" class="form-control">
                        <option value="*">Full Access</option>
                        <option value="server:read">Server: Read Only</option>
                        <option value="accounts:read">Accounts: Read Only</option>
                        <option value="accounts:write">Accounts: Read &amp; Write</option>
                    </select>
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('generateModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="key-round" class="lucide"></i> Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('keySearch');
    var count = document.getElementById('keyCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.key-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' key' + (shown === 1 ? '' : 's');
        });
    }
})();
function toggleKey(id, btn) {
    var fp = document.getElementById('kfp-' + id);
    if (!fp) return;
    if (btn.dataset.revealed === '1') {
        fp.textContent = fp.textContent === fp.dataset.full ? fp.dataset.orig : fp.dataset.orig;
        btn.dataset.revealed = '0';
        btn.innerHTML = '<i data-lucide="eye" class="lucide"></i> Show';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }
    var fd = new FormData();
    fd.append('action', 'reveal');
    fd.append('id', id);
    fetch('/whm/api-keys', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.ok) {
                fp.dataset.orig = fp.textContent;
                fp.dataset.full = d.key;
                fp.textContent = d.key;
                btn.dataset.revealed = '1';
                btn.innerHTML = '<i data-lucide="eye-off" class="lucide"></i> Hide';
            } else {
                alert(d && d.error ? d.error : 'Could not reveal key.');
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();
        })
        .catch(function () { alert('Could not reveal key.'); });
}

function copyKey() {
    var el = document.getElementById('newApiKey');
    if (!el) return;
    var text = el.textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            var btn = el.nextElementSibling;
            btn.innerHTML = '<i data-lucide="check" class="lucide"></i> Copied';
            if (typeof lucide !== 'undefined') lucide.createIcons();
            setTimeout(function() { btn.innerHTML = '<i data-lucide="copy" class="lucide"></i> Copy'; if (typeof lucide !== 'undefined') lucide.createIcons(); }, 2000);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        var btn = el.nextElementSibling;
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.innerHTML = '<i data-lucide="copy" class="lucide"></i> Copy'; if (typeof lucide !== 'undefined') lucide.createIcons(); }, 2000);
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
