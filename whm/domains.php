<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_domains');
init_db();
$db = db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_domain') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_domain = trim($_POST['new_domain'] ?? '');
        if ($user_id > 0 && $new_domain !== '') {
            $existing = $db->prepare("SELECT id FROM users WHERE domain = ? AND id != ?");
            $existing->execute([$new_domain, $user_id]);
            if ($existing->fetch()) {
                flash('error', "Domain '{$new_domain}' is already taken by another account");
            } else {
                $db->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$new_domain, $user_id]);
                $msg = "Domain changed to '{$new_domain}'";
                flash('success', $msg);
            }
        }
    }

    if ($action === 'update_domain') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $domain = trim($_POST['domain'] ?? '');
        if ($user_id > 0) {
            if ($domain !== '') {
                $existing = $db->prepare("SELECT id FROM users WHERE domain = ? AND id != ?");
                $existing->execute([$domain, $user_id]);
                if ($existing->fetch()) {
                    flash('error', "Domain '{$domain}' is already taken");
                    redirect('/whm/domains.php');
                }
            }
            $db->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$domain, $user_id]);
            flash('success', 'Domain updated');
        }
    }

    redirect('/whm/domains.php');
}

// Ensure config table exists for global domain
$db->exec("CREATE TABLE IF NOT EXISTS `config` (
    key_name VARCHAR(255) PRIMARY KEY,
    value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$gd = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
$global_domain = $gd['value'] ?? SITE_DOMAIN;

$accounts = $db->query("SELECT id, username, domain, email, status FROM users WHERE role = 'cpanel' ORDER BY domain, username")->fetchAll(PDO::FETCH_ASSOC);
$all_domains = $db->query("SELECT DISTINCT domain FROM users WHERE role = 'cpanel' AND domain != '' ORDER BY domain")->fetchAll(PDO::FETCH_COLUMN);

$total_accounts = count($accounts);
$with_domain = count(array_filter($accounts, fn($a) => !empty($a['domain'])));
$unique_domains = count($all_domains);

$nav = 'domains';
$page_title = 'Domain Manager';
require_once __DIR__ . '/../templates/header.php';
?>

<?php if ($flash = flash('success')): ?>
    <div class="alert alert-success"><i data-lucide="check-circle" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>
<?php if ($flash = flash('error')): ?>
    <div class="alert alert-error"><i data-lucide="alert-triangle" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="globe" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Domain Manager</div>
        <div class="hero-desc">Reassign the domain attached to any customer account in one place.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><i data-lucide="globe" class="lucide"></i> <?= $unique_domains ?> domain<?= $unique_domains === 1 ? '' : 's' ?></span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_accounts ?></div>
            <div class="stat-label">Total Accounts</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">cPanel customers</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $with_domain ?></div>
            <div class="stat-label">With Domain</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">accounts assigned a domain</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="layers" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $unique_domains ?></div>
            <div class="stat-label">Unique Domains</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">registered on this server</div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="list" class="lucide"></i> Account Domains (<?= count($accounts) ?>)</h3>
    </div>
    <?php if (!empty($accounts)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="domSearch" placeholder="Search username or domain..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="domCount"><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($accounts) ? '14px' : '16px' ?>">
        <?php if (empty($accounts)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="globe" class="lucide"></i></div>
                <strong>No accounts yet</strong>
                <p>Create a cPanel account and its domain will appear here.</p>
            </div>
        <?php else: ?>
            <div class="dom-list">
            <?php foreach ($accounts as $a): ?>
                <div class="dom-row" data-name="<?= h($a['username'] . ' ' . $a['domain']) ?>">
                    <span class="dom-ic <?= empty($a['domain']) ? 'is-empty' : '' ?>"><i data-lucide="<?= empty($a['domain']) ? 'globe-lock' : 'globe' ?>" class="lucide"></i></span>
                    <div class="dom-main">
                        <div class="dom-name">
                            <?= h($a['username']) ?>
                            <span class="badge badge-<?= $a['status'] ?>"><?= h($a['status']) ?></span>
                        </div>
                        <div class="dom-meta"><?= h($a['email'] ?: '-') ?></div>
                    </div>
                    <div class="dom-current">
                        <div class="dom-now-label">Current Domain</div>
                        <?php if ($a['domain']): ?>
                            <span class="dom-now"><?= h($a['domain']) ?></span>
                        <?php else: ?>
                            <span class="dom-none">No domain set</span>
                        <?php endif; ?>
                    </div>
                    <div class="dom-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="update_domain">
                            <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                            <input type="text" name="domain" value="<?= h($a['domain']) ?>" placeholder="<?= h($global_domain) ?>" class="input-sm dom-input" autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-primary"><i data-lucide="save" class="lucide"></i> Save</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.dom-list{display:flex;flex-direction:column;gap:10px}
.dom-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.dom-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.dom-ic{width:42px;height:42px;min-width:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#2563eb,#3b82f6 55%,#60a5fa);color:#fff;box-shadow:0 4px 10px rgba(59,130,246,.18)}
.dom-ic.is-empty{background:linear-gradient(135deg,#64748b,#94a3b8 55%,#cbd5e1);box-shadow:0 4px 10px rgba(100,116,139,.18)}
.dom-ic .lucide{width:19px;height:19px}
.dom-main{min-width:0;flex:1}
.dom-name{font-weight:700;color:var(--text);font-size:13.5px;display:flex;align-items:center;gap:8px}
.dom-name .badge{font-size:10px;padding:3px 8px}
.dom-meta{font-size:12px;color:var(--text4);margin-top:3px}
.dom-current{display:flex;flex-direction:column;gap:3px;min-width:160px}
.dom-now-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text4)}
.dom-now{font-family:'Fira Code',monaco,consolas,monospace;font-size:13px;color:var(--text);font-weight:600;word-break:break-all}
.dom-none{font-size:12px;color:var(--text4);font-style:italic}
.dom-actions{flex-shrink:0}
.dom-input{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px}
@media(max-width:720px){
  .dom-row{flex-wrap:wrap}
  .dom-main{flex-basis:100%}
  .dom-current{flex-basis:100%}
  .dom-actions{width:100%}
  .dom-actions form{display:flex;gap:6px}
  .dom-actions .dom-input{flex:1}
}
</style>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<script>
(function () {
    var input = document.getElementById('domSearch');
    var count = document.getElementById('domCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.dom-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' account' + (shown === 1 ? '' : 's');
        });
    }
})();
</script>
