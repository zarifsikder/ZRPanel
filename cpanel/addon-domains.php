<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('addon_domain_services');
init_db();
$db = db();

$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Create addon_domains table
$db->exec("CREATE TABLE IF NOT EXISTS `addon_domains` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `domain` VARCHAR(255) NOT NULL,
    `document_root` VARCHAR(500) NOT NULL,
    `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    UNIQUE KEY `uk_domain_user` (`domain`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function create_addon_dns($db, $user_id, $domain) {
    $exists = $db->prepare("SELECT id FROM dns_records WHERE user_id = ? AND domain = ? AND name = ? AND type = 'A'");
    $exists->execute([$user_id, $domain, $domain]);
    if (!$exists->fetch()) {
        $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl) VALUES (?, ?, ?, 'A', ?, 3600)")
           ->execute([$user_id, $domain, $domain, SERVER_IP]);
    }
}

function remove_addon_dns($db, $user_id, $domain) {
    $db->prepare("DELETE FROM dns_records WHERE user_id = ? AND domain = ? AND name = ? AND type = 'A'")
       ->execute([$user_id, $domain, $domain]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        if (empty($domain)) {
            flash('error', 'Domain cannot be empty');
        } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/', $domain)) {
            flash('error', 'Invalid domain format');
        } else {
            $check = $db->prepare("SELECT id FROM addon_domains WHERE domain = ? AND user_id = ?");
            $check->execute([$domain, $user_id]);
            if ($check->fetch()) {
                flash('error', "Domain '{$domain}' already exists as an addon domain");
            } else {
                $doc_root = ($user['home_dir'] ?? '') . '/addondomains/' . $domain;
                @mkdir($doc_root, 0755, true);
                file_put_contents($doc_root . '/index.php', "<!DOCTYPE html>\n<html><head><title>{$domain}</title></head><body>\n<h1>Welcome to {$domain}</h1>\n</body></html>");
                $db->prepare("INSERT INTO addon_domains (user_id, domain, document_root) VALUES (?, ?, ?)")
                   ->execute([$user_id, $domain, $doc_root]);
                create_addon_dns($db, $user_id, $domain);
                flash('success', "Addon domain '{$domain}' created");
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM addon_domains WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $addon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($addon) {
            $new_status = $addon['status'] === 'active' ? 'suspended' : 'active';
            $db->prepare("UPDATE addon_domains SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            $label = $new_status === 'active' ? 'unsuspended' : 'suspended';
            flash('success', "Addon domain '{$addon['domain']}' {$label}");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM addon_domains WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $addon = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($addon) {
            remove_addon_dns($db, $user_id, $addon['domain']);
            $db->prepare("DELETE FROM addon_domains WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            flash('success', "Addon domain '{$addon['domain']}' deleted");
        }
    }
    redirect('/cpanel/addon-domains.php');
}

$addons = $db->prepare("SELECT * FROM addon_domains WHERE user_id = ? ORDER BY created_at DESC");
$addons->execute([$user_id]);
$addons = $addons->fetchAll(PDO::FETCH_ASSOC);

$nav = 'addondomains';
$page_title = 'Addon Domains';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="plus-circle" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Addon Domains</div>
        <div class="hero-desc">Host additional fully independent domains on this account, each with its own document root and DNS record.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-purple" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="server" class="lucide"></i> A&nbsp;&rarr;&nbsp;<?= h(SERVER_IP) ?></span>
    </div>
</div>

<?php
$active_count = 0;
$suspended_count = 0;
foreach ($addons as $a) {
    if ($a['status'] === 'active') $active_count++;
    else $suspended_count++;
}
$dir_count = 0;
foreach ($addons as $a) {
    if (is_dir($a['document_root'])) $dir_count++;
}
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($addons) ?></div>
            <div class="stat-label">Addon Domains</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">hosted under this account</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_count ?></div>
            <div class="stat-label">Active</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">resolving and serving</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="pause-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $suspended_count ?></div>
            <div class="stat-label">Suspended</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">unreachable to visitors</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="folder" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $dir_count ?></div>
            <div class="stat-label">Doc Roots on Disk</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">created automatically</div>
        </div>
    </div>
</div>

<div class="grid-2 fade-in-delay-2">
    <div class="card">
        <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Create Addon Domain</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Domain Name</label>
                    <input type="text" name="domain" id="newAddonInput" required placeholder="e.g. example.com" pattern="[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+" autocomplete="off">
                    <div class="ad-preview">
                        <span style="font-size:11px;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;font-weight:700">Document root</span>
                        <code id="adPreview">~/addondomains/example.com/</code>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px"><i data-lucide="plus" class="lucide"></i> Create Addon Domain</button>
            </form>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px;padding-top:14px;border-top:1px dashed var(--border)">
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3)"><i data-lucide="folder-plus" class="lucide" style="width:14px;height:14px;color:var(--purple)"></i> Creates <code class="chip-mono">~/addondomains/&lt;domain&gt;/</code> with a starter page</div>
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3)"><i data-lucide="globe" class="lucide" style="width:14px;height:14px;color:var(--purple)"></i> Registers the A record automatically</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i data-lucide="network" class="lucide"></i> Point Your Domain Here</h3></div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--text3);margin:0 0 14px">Create this A record at your domain registrar, then add the domain above:</p>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="kv-row"><span class="kv-key"><i data-lucide="home" class="lucide"></i> Type</span><span class="kv-val mono">A</span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="at-sign" class="lucide"></i> Host</span><span class="kv-val mono">@</span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="server" class="lucide"></i> Value</span><span class="kv-val mono"><?= h(SERVER_IP) ?></span></div>
                <div class="kv-row"><span class="kv-key"><i data-lucide="timer" class="lucide"></i> TTL</span><span class="kv-val mono">3600</span></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);font-size:12px;color:var(--text4)">
                <i data-lucide="info" class="lucide" style="width:14px;height:14px;color:var(--purple);flex-shrink:0"></i>
                <span>The domain becomes live once DNS propagates, usually within a few minutes to 24 hours.</span>
            </div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-3">
    <div class="card-header">
        <h3><i data-lucide="list" class="lucide"></i> Your Addon Domains</h3>
    </div>
    <?php if (empty($addons)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i data-lucide="globe" class="lucide"></i></div>
            <strong>No addon domains yet</strong>
            <p>Add a domain above to host a second website on this account.</p>
            <div class="empty-state-actions">
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newAddonInput').focus();document.querySelector('.grid-2').scrollIntoView({behavior:'smooth'})"><i data-lucide="plus-circle" class="lucide"></i> Add Domain</button>
            </div>
        </div>
    <?php else: ?>
        <div class="table-toolbar">
            <div class="toolbar-search">
                <i data-lucide="search" class="lucide"></i>
                <input id="adq" placeholder="Search domains...">
            </div>
            <span class="toolbar-count" id="adc"><?= count($addons) ?> item<?= count($addons) === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body" style="padding:16px">
            <div class="ad-list">
                <?php foreach ($addons as $a):
                    $rel_root = str_replace(($user['home_dir'] ?? ''), '~', $a['document_root']);
                ?>
                    <div class="ad-row" data-name="<?= h(strtolower($a['domain'] . ' ' . $rel_root . ' ' . $a['status'])) ?>">
                        <span class="ad-ic <?= $a['status'] === 'suspended' ? 'is-off' : '' ?>"><i data-lucide="globe" class="lucide"></i></span>
                        <div class="ad-main">
                            <div class="ad-name"><?= h($a['domain']) ?></div>
                            <div class="ad-meta">
                                <code class="chip-mono"><?= h($rel_root) ?></code>
                                <span>Created <?= date('M d, Y', strtotime($a['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="ad-state">
                            <?php if ($a['status'] === 'active'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                            <?php else: ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><i data-lucide="pause" class="lucide" style="width:11px;height:11px"></i> Suspended</span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-actions">
                            <?php if ($a['status'] === 'active'): ?>
                                <a href="//<?= h($a['domain']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-ghost" title="Visit <?= h($a['domain']) ?>"><i data-lucide="external-link" class="lucide"></i> Visit</a>
                            <?php endif; ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <?php if ($a['status'] === 'active'): ?>
                                    <button type="submit" class="btn btn-sm btn-warning" title="Suspend"><i data-lucide="pause-circle" class="lucide"></i></button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-sm btn-success" title="Unsuspend"><i data-lucide="play-circle" class="lucide"></i></button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete addon domain <?= h($a['domain']) ?>? Its document root entry and DNS records will be removed.')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
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
.ad-preview{display:flex;align-items:center;gap:10px;margin-top:10px;background:var(--bg2);border:1px dashed var(--border);border-radius:var(--radius-sm);padding:8px 10px}
.ad-preview code{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ad-list{display:flex;flex-direction:column;gap:10px}
.ad-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.ad-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.ad-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6d28d9,#8b5cf6 55%,#a78bfa);color:#fff;box-shadow:0 4px 10px rgba(139,92,246,.18)}
.ad-ic.is-off{background:linear-gradient(135deg,#92400e,#d97706 55%,#f59e0b);box-shadow:0 4px 10px rgba(217,119,6,.18)}
.ad-ic .lucide{width:19px;height:19px}
.ad-main{min-width:0;flex:1}
.ad-name{font-weight:700;color:var(--text);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ad-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.ad-state{flex-shrink:0}
.ad-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:640px){
  .ad-row{flex-wrap:wrap}
  .ad-main{flex-basis:100%}
  .ad-actions,.ad-state{margin-left:0}
  .ad-actions form, .ad-actions a{flex:1;display:flex}
  .ad-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var input = document.getElementById('newAddonInput');
    var preview = document.getElementById('adPreview');
    if (input && preview) {
        var show = function () {
            var v = (input.value || '').trim().toLowerCase().replace(/[^a-z0-9.\-]/g, '');
            preview.textContent = '~/addondomains/' + (v || 'example.com') + '/';
        };
        input.addEventListener('input', show);
        show();
    }

    var q = document.getElementById('adq');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.ad-row'));
        var c = document.getElementById('adc');
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