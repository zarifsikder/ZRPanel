<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('subdomain_services');
init_db();
$db = db();

$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_domain = $user['domain'] ?: SITE_DOMAIN;

function enable_subdomain_symlink($subdomain, $domain, $doc_root) {
    $link = __DIR__ . '/../user_data/' . $subdomain;
    if (!is_link($link) && !is_dir($link)) {
        @symlink($doc_root, $link);
    }
}

function disable_subdomain_symlink($subdomain) {
    $link = __DIR__ . '/../user_data/' . $subdomain;
    if (is_link($link)) {
        @unlink($link);
    }
}

function create_subdomain_dns($db, $user_id, $subdomain, $domain, $server_ip) {
    $full_domain = "{$subdomain}.{$domain}";
    $exists = $db->prepare("SELECT id FROM dns_records WHERE user_id = ? AND domain = ? AND name = ? AND type = 'A'");
    $exists->execute([$user_id, $domain, $subdomain]);
    if (!$exists->fetch()) {
        $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl) VALUES (?, ?, ?, 'A', ?, 3600)")
           ->execute([$user_id, $domain, $subdomain, $server_ip]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $subdomain = trim($_POST['subdomain'] ?? '');
        if (!empty($subdomain)) {
            $doc_root = ($user['home_dir'] ?? '') . '/public_html/' . $subdomain;
            @mkdir($doc_root, 0755, true);
            file_put_contents($doc_root . '/index.php', "<!DOCTYPE html>\n<html><head><title>{$subdomain}</title></head><body>\n<h1>Welcome to {$subdomain}.{$user_domain}</h1>\n</body></html>");
            $db->prepare("INSERT INTO subdomains (user_id, subdomain, domain, document_root) VALUES (?, ?, ?, ?)")
               ->execute([$user_id, $subdomain, $user_domain, $doc_root]);

            enable_subdomain_symlink($subdomain, $user_domain, $doc_root);

            $server_ip = $_SERVER['SERVER_ADDR'] ?? @gethostbyname(gethostname());
            if ($server_ip && $server_ip !== '127.0.0.1') {
                create_subdomain_dns($db, $user_id, $subdomain, $user_domain, $server_ip);
            }

            cloudflare_create_dns('CNAME', "{$subdomain}.{$user_domain}", CF_TUNNEL_ID . '.cfargotunnel.com', 3600, true);

            flash('success', "Subdomain '{$subdomain}.{$user_domain}' created and live");
            clear_customer_site_cache();
        }
    } elseif ($action === 'change_domain') {
        $new_domain = trim($_POST['new_domain'] ?? '');
        if (empty($new_domain)) {
            flash('error', 'Domain cannot be empty');
        } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/', $new_domain)) {
            flash('error', 'Invalid domain format');
        } else {
            $existing = $db->prepare("SELECT id FROM users WHERE domain = ? AND id != ?");
            $existing->execute([$new_domain, $user_id]);
            if ($existing->fetch()) {
                flash('error', "Domain '{$new_domain}' is already assigned to another account");
            } else {
                $old_domain = $user['domain'] ?: SITE_DOMAIN;
                $db->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$new_domain, $user_id]);
                $db->prepare("UPDATE subdomains SET domain = ? WHERE user_id = ?")->execute([$new_domain, $user_id]);
                $db->prepare("UPDATE dns_records SET domain = ? WHERE user_id = ? AND domain = ?")->execute([$new_domain, $user_id, $old_domain]);
                clear_customer_site_cache();
                flash('success', "Domain changed from {$old_domain} to {$new_domain}");
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $del = $db->prepare("SELECT * FROM subdomains WHERE id = ? AND user_id = ?");
        $del->execute([$id, $user_id]);
        $del_sub = $del->fetch(PDO::FETCH_ASSOC);
        if ($del_sub) {
            disable_subdomain_symlink($del_sub['subdomain']);
            $full_domain = $del_sub['subdomain'] . '.' . $del_sub['domain'];
            $db->prepare("DELETE FROM dns_records WHERE user_id = ? AND domain = ? AND name = ? AND type = 'A'")
               ->execute([$user_id, $del_sub['domain'], $del_sub['subdomain']]);
            cloudflare_delete_dns_by_name($full_domain);
        }
        $db->prepare("DELETE FROM subdomains WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        clear_customer_site_cache();
        flash('success', 'Subdomain deleted');
    }
    redirect('/cpanel/subdomains.php');
}

$subdomains = $db->prepare("SELECT * FROM subdomains WHERE user_id = ? ORDER BY created_at DESC");
$subdomains->execute([$user_id]);
$subdomains = $subdomains->fetchAll(PDO::FETCH_ASSOC);

$nav = 'subdomains';
$page_title = 'Domain Manager';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="git-branch" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Domain Manager</div>
        <div class="hero-desc">Create and manage subdomains under your domains, with automatic document roots and DNS records.</div>
    </div>
    <div class="hero-actions">
        <a href="//<?= h($user_domain) ?>" target="_blank" rel="noopener" class="badge badge-blue" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="globe" class="lucide"></i> <?= h($user_domain) ?></a>
    </div>
</div>

<?php
$roots_count = 0;
foreach ($subdomains as $s) {
    if (is_dir($s['document_root'])) $roots_count++;
}
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="git-branch" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($subdomains) ?></div>
            <div class="stat-label">Total Subdomains</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">all named under <?= h($user_domain) ?></div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="calendar-plus" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $month_count ?></div>
            <div class="stat-label">Created This Month</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= date('F Y') ?></div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="folder" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $roots_count ?></div>
            <div class="stat-label">Document Roots</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">folders ready on disk</div>
        </div>
    </div>
</div>

<div class="tip-card tip-blue fade-in-delay-1">
    <i data-lucide="lightbulb" class="lucide"></i>
    <div class="tip-body">
        <strong>Tip.</strong> Each subdomain gets its own folder under <code class="chip-mono">~/public_html/</code> and an A record pointing to this server, so it works as soon as it is created.
    </div>
</div>

<div class="grid-2 fade-in-delay-2">
    <div class="card">
        <div class="card-header"><h3><i data-lucide="plus" class="lucide"></i> Create Subdomain</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Subdomain</label>
                    <div style="display:flex;gap:0">
                        <input type="text" name="subdomain" id="newSubInput" required placeholder="e.g. blog" pattern="[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?" title="Letters, numbers and hyphens only" style="border-radius:var(--radius-sm) 0 0 var(--radius-sm)">
                        <span style="display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-left:0;border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-size:13px;color:var(--text3);white-space:nowrap">.<?= h($user_domain) ?></span>
                    </div>
                    <div class="sd-preview">
                        <span style="font-size:11px;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;font-weight:700">Will be live at</span>
                        <code id="subPreview">https://blog.<?= h($user_domain) ?></code>
                    </div>
                </div>
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Letters, numbers and hyphens only.</div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px"><i data-lucide="plus" class="lucide"></i> Create Subdomain</button>
            </form>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px;padding-top:14px;border-top:1px dashed var(--border)">
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3)"><i data-lucide="folder-plus" class="lucide" style="width:14px;height:14px;color:var(--purple)"></i> Creates <code class="chip-mono">~/public_html/&lt;name&gt;/</code> with a starter page</div>
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3)"><i data-lucide="globe" class="lucide" style="width:14px;height:14px;color:var(--purple)"></i> Adds the DNS record automatically</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i data-lucide="globe" class="lucide"></i> Primary Domain</h3></div>
        <div class="card-body">
            <div class="kv-row">
                <span class="kv-key"><i data-lucide="home" class="lucide"></i> Current domain</span>
                <span class="kv-val mono"><?= h($user['domain'] ?: SITE_DOMAIN) ?></span>
            </div>
            <form method="POST" style="margin-top:14px">
                <input type="hidden" name="action" value="change_domain">
                <div class="form-group">
                    <label>New Domain</label>
                    <input type="text" name="new_domain" required placeholder="example.com" pattern="[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+">
                </div>
                <button type="submit" class="btn btn-warning" style="width:100%" onclick="return confirm('Change your primary domain? This will update all related DNS records.')"><i data-lucide="repeat" class="lucide"></i> Change Domain</button>
                <div class="form-hint"><i data-lucide="alert-triangle" class="lucide"></i> Changing the primary domain updates every existing subdomain and its DNS records.</div>
            </form>
        </div>
    </div>
</div>

<div class="card fade-in-delay-3">
    <div class="card-header">
        <h3><i data-lucide="list" class="lucide"></i> My Domains</h3>
    </div>
    <?php if (empty($subdomains)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i data-lucide="git-branch" class="lucide"></i></div>
            <strong>No subdomains yet</strong>
            <p>Create your first subdomain above and it will appear here instantly.</p>
            <div class="empty-state-actions">
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newSubInput').focus();document.querySelector('.grid-2').scrollIntoView({behavior:'smooth'})"><i data-lucide="plus" class="lucide"></i> Create Subdomain</button>
            </div>
        </div>
    <?php else: ?>
        <div class="table-toolbar">
            <div class="toolbar-search">
                <i data-lucide="search" class="lucide"></i>
                <input id="sdq" placeholder="Search subdomains...">
            </div>
            <span class="toolbar-count" id="sdc"><?= count($subdomains) ?> item<?= count($subdomains) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body" style="padding:16px">
            <div class="sd-list">
                <?php foreach ($subdomains as $s):
                    $full_domain = $s['subdomain'] . '.' . $s['domain'];
                    $rel_root = str_replace(($user['home_dir'] ?? ''), '~', $s['document_root']);
                ?>
                    <div class="sd-row" data-name="<?= h(strtolower($s['subdomain'] . ' ' . $full_domain . ' ' . $rel_root)) ?>">
                        <span class="sd-ic"><i data-lucide="git-branch" class="lucide"></i></span>
                        <div class="sd-main">
                            <div class="sd-name"><?= h($s['subdomain']) ?><span class="sd-ext">.<?= h($s['domain']) ?></span></div>
                            <div class="sd-meta">
                                <code class="chip-mono"><?= h($rel_root) ?></code>
                                <span>Created <?= date('M d, Y', strtotime($s['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="sd-actions">
                            <a href="//<?= h($full_domain) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-ghost" title="Visit <?= h($full_domain) ?>"><i data-lucide="external-link" class="lucide"></i> Visit</a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this subdomain? Its document root entry and DNS records will be removed.')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.sd-preview{display:flex;align-items:center;gap:10px;margin-top:10px;background:var(--bg2);border:1px dashed var(--border);border-radius:var(--radius-sm);padding:8px 10px}
.sd-preview code{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sd-list{display:flex;flex-direction:column;gap:10px}
.sd-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.sd-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.sd-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6d28d9,#8b5cf6 55%,#a78bfa);color:#fff;box-shadow:0 4px 10px rgba(139,92,246,.18)}
.sd-ic .lucide{width:19px;height:19px}
.sd-main{min-width:0;flex:1}
.sd-name{font-weight:700;color:var(--text);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sd-ext{color:var(--text3);font-weight:600}
.sd-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.sd-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:640px){
  .sd-row{flex-wrap:wrap}
  .sd-main{flex-basis:100%}
  .sd-actions{width:100%}
  .sd-actions form{flex:1}
  .sd-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var dom = <?= json_encode($user_domain) ?>;
    var subInput = document.getElementById('newSubInput');
    var preview = document.getElementById('subPreview');
    if (subInput && preview) {
        var show = function () {
            var v = (subInput.value || '').trim();
            preview.textContent = 'https://' + (v || 'yourname') + '.' + dom;
        };
        subInput.addEventListener('input', show);
        show();
    }

    var q = document.getElementById('sdq');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.sd-row'));
        var c = document.getElementById('sdc');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var show = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = show ? '' : 'none';
                if (show) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' item' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
