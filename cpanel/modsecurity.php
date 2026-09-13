<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('modsecurity');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$predefined_rules = [
    ['rule_id' => '1001', 'description' => 'SQL Injection Attack Detection', 'default_action' => 'on'],
    ['rule_id' => '1002', 'description' => 'Cross-Site Scripting (XSS) Attack Detection', 'default_action' => 'on'],
    ['rule_id' => '1003', 'description' => 'Remote Code Execution Detection', 'default_action' => 'on'],
    ['rule_id' => '1004', 'description' => 'Path Traversal Attack Detection', 'default_action' => 'on'],
    ['rule_id' => '1005', 'description' => 'File Upload Attack Detection', 'default_action' => 'on'],
    ['rule_id' => '1006', 'description' => 'Protocol Attack Detection', 'default_action' => 'on'],
    ['rule_id' => '1007', 'description' => 'Scanner Detection', 'default_action' => 'detectonly'],
    ['rule_id' => '1008', 'description' => 'Bot Detection', 'default_action' => 'detectonly'],
];

$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$username = $stmt->fetchColumn() ?: 'user';

$domains_stmt = $db->prepare("SELECT DISTINCT domain COLLATE utf8mb4_general_ci FROM addon_domains WHERE user_id = ? UNION SELECT domain COLLATE utf8mb4_general_ci FROM users WHERE id = ?");
$domains_stmt->execute([$user_id, $user_id]);
$domains = $domains_stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($domains)) {
    $stmt2 = $db->prepare("SELECT domain FROM users WHERE id = ?");
    $stmt2->execute([$user_id]);
    $d = $stmt2->fetchColumn();
    if ($d) $domains[] = $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_rule') {
        $domain = trim($_POST['domain'] ?? '');
        $rule_id = trim($_POST['rule_id'] ?? '');
        $rule_action = $_POST['rule_action'] ?? 'on';

        if (empty($domain) || empty($rule_id)) {
            flash('error', 'Domain and rule are required.');
            redirect('/cpanel/modsecurity.php');
        }

        $valid_actions = ['on', 'off', 'detectonly'];
        if (!in_array($rule_action, $valid_actions)) {
            $rule_action = 'on';
        }

        $desc = '';
        foreach ($predefined_rules as $pr) {
            if ($pr['rule_id'] === $rule_id) {
                $desc = $pr['description'];
                break;
            }
        }

        $exists = $db->prepare("SELECT id FROM modsecurity_rules WHERE domain = ? AND rule_id = ? AND user_id = ?");
        $exists->execute([$domain, $rule_id, $user_id]);
        $row = $exists->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $db->prepare("UPDATE modsecurity_rules SET action = ?, description = ?, status = 'active' WHERE id = ?")
               ->execute([$rule_action, $desc, $row['id']]);
        } else {
            $db->prepare("INSERT INTO modsecurity_rules (user_id, domain, rule_id, description, action, status) VALUES (?, ?, ?, ?, ?, 'active')")
               ->execute([$user_id, $domain, $rule_id, $desc, $rule_action]);
        }
        flash('success', "Rule {$rule_id} updated for {$domain}");

    } elseif ($action === 'bulk_enable') {
        $domain = trim($_POST['domain'] ?? '');
        if (empty($domain)) {
            flash('error', 'Domain is required.');
            redirect('/cpanel/modsecurity.php');
        }
        foreach ($predefined_rules as $pr) {
            $exists = $db->prepare("SELECT id FROM modsecurity_rules WHERE domain = ? AND rule_id = ? AND user_id = ?");
            $exists->execute([$domain, $pr['rule_id'], $user_id]);
            $row = $exists->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $db->prepare("UPDATE modsecurity_rules SET action = ?, status = 'active' WHERE id = ?")
                   ->execute([$pr['default_action'], $row['id']]);
            } else {
                $db->prepare("INSERT INTO modsecurity_rules (user_id, domain, rule_id, description, action, status) VALUES (?, ?, ?, ?, ?, 'active')")
                   ->execute([$user_id, $pr['rule_id'], $pr['description'], $pr['default_action'], $pr['rule_id']]);
            }
        }
        flash('success', "All rules enabled for {$domain}");

    } elseif ($action === 'bulk_disable') {
        $domain = trim($_POST['domain'] ?? '');
        if (empty($domain)) {
            flash('error', 'Domain is required.');
            redirect('/cpanel/modsecurity.php');
        }
        $db->prepare("UPDATE modsecurity_rules SET action = 'off' WHERE domain = ? AND user_id = ?")
           ->execute([$domain, $user_id]);
        flash('success', "All rules disabled for {$domain}");

    } elseif ($action === 'delete_rule') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM modsecurity_rules WHERE id = ? AND user_id = ?")
           ->execute([$id, $user_id]);
        flash('success', 'Rule deleted');
    }
    redirect('/cpanel/modsecurity.php');
}

$filter_domain = $_GET['domain'] ?? '';
if ($filter_domain) {
    $stmt = $db->prepare("SELECT * FROM modsecurity_rules WHERE user_id = ? AND domain = ? ORDER BY rule_id");
    $stmt->execute([$user_id, $filter_domain]);
} else {
    $stmt = $db->prepare("SELECT * FROM modsecurity_rules WHERE user_id = ? ORDER BY domain, rule_id");
    $stmt->execute([$user_id]);
}
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rule_map = [];
foreach ($rules as $r) {
    $rule_map[$r['domain']][$r['rule_id']] = $r;
}

$nav = 'modsecurity';
$page_title = 'ModSecurity';
require_once __DIR__ . '/../templates/header.php';

$configured_domains = count($rule_map);
?>

<div class="page-hero fade-in">
    <div class="hero-icon red"><i data-lucide="shield-alert" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">ModSecurity Web Application Firewall</div>
        <div class="hero-desc">Filter HTTP traffic in real time and block common attacks like SQL injection, XSS and path traversal before they reach your apps.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><?= $configured_domains ?> domain<?= $configured_domains === 1 ? '' : 's' ?> configured</span></div>
</div>

<?php
$rules_on = 0;
$rules_detect = 0;
$rules_off = 0;
foreach ($rules as $r) {
    if ($r['action'] === 'on') $rules_on++;
    elseif ($r['action'] === 'detectonly') $rules_detect++;
    else $rules_off++;
}
$fd_on = 0;
$fd_detect = 0;
if ($filter_domain) {
    foreach (($rule_map[$filter_domain] ?? []) as $rr) {
        if ($rr['action'] === 'on') $fd_on++;
        elseif ($rr['action'] === 'detectonly') $fd_detect++;
    }
}
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="shield-alert" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($rules) ?></div>
            <div class="stat-label">Rules Configured</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">across <?= $configured_domains ?> domain<?= $configured_domains === 1 ? '' : 's' ?></div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="shield-check" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $rules_on ?></div>
            <div class="stat-label">Enforcing (On)</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">attacks blocked automatically</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="eye" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $rules_detect ?></div>
            <div class="stat-label">Detect Only</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">logged, never blocked</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="shield-off" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $rules_off ?></div>
            <div class="stat-label">Disabled (Off)</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">traffic passes through</div>
        </div>
    </div>
</div>

<div class="tip-card tip-red fade-in-delay-1">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong>Three rule modes.</strong> <strong>On</strong> blocks the matched attack with a 403 page. <strong>Detect Only</strong> writes it to the log without blocking &mdash; ideal for testing before enforcing. <strong>Off</strong> disables the rule entirely. Rules still at their default behavior are labelled <span class="badge badge-blue" style="vertical-align:middle">Default</span>.</div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="settings" class="lucide"></i> Manage Rules by Domain</h3></div>
    <div class="card-body">
        <form method="GET" class="form-grid" style="max-width:600px">
            <div class="form-group">
                <label>Select Domain</label>
                <select name="domain" onchange="this.form.submit()" style="width:100%">
                    <option value="">All Domains</option>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= h($d) ?>" <?= $filter_domain === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><i data-lucide="globe" class="lucide"></i> Choose a domain to tune its rules, or leave it on "All Domains" to manage the full override list.</div>
            </div>
        </form>
    </div>
</div>

<?php if ($filter_domain): ?>
<div class="tip-card fade-in-delay-1 <?= $fd_on ? 'tip-green' : ($fd_detect ? 'tip-orange' : 'tip-red') ?>" style="margin-top:2px;margin-bottom:20px">
    <i data-lucide="<?= $fd_on ? 'shield-check' : ($fd_detect ? 'eye' : 'shield-off') ?>" class="lucide"></i>
    <div class="tip-body">
        <?php if ($fd_on): ?>
            <strong>Protected.</strong> <?= $fd_on ?> rule<?= $fd_on === 1 ? '' : 's' ?> are blocking attacks for <?= h($filter_domain) ?><?= $fd_detect ? ", and {$fd_detect} more run in detect-only mode." : '.' ?>
        <?php elseif ($fd_detect): ?>
            <strong>Warning.</strong> No rule is blocking for <?= h($filter_domain) ?> &mdash; <?= $fd_detect ?> rule<?= $fd_detect === 1 ? '' : 's' ?> only log events. Switch them to On or use "Enable All" below.
        <?php else: ?>
            <strong>WAF is off.</strong> No rule is enforced for <?= h($filter_domain) ?>. Use "Enable All" below to restore protection.
        <?php endif; ?>
    </div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header">
        <h3><i data-lucide="globe" class="lucide"></i> Rules for <?= h($filter_domain) ?></h3>
        <div style="display:flex;gap:8px;margin-left:auto;flex-wrap:wrap">
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="bulk_enable">
                <input type="hidden" name="domain" value="<?= h($filter_domain) ?>">
                <button type="submit" class="btn btn-sm btn-success"><i data-lucide="check-circle" class="lucide"></i> Enable All</button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Disable ALL rules for this domain? Your sites will lose WAF protection until rules are re-enabled.')">
                <input type="hidden" name="action" value="bulk_disable">
                <input type="hidden" name="domain" value="<?= h($filter_domain) ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i data-lucide="x-circle" class="lucide"></i> Disable All</button>
            </form>
        </div>
    </div>
    <div class="card-body" style="padding:16px">
        <div class="ms-list">
            <?php foreach ($predefined_rules as $pr):
                $existing = $rule_map[$filter_domain][$pr['rule_id']] ?? null;
                $current_action = $existing ? $existing['action'] : $pr['default_action'];
                $is_active = $existing ? ($existing['status'] === 'active') : false;
                $mode_badges = ['on' => 'badge-active', 'off' => 'badge-suspended', 'detectonly' => 'badge-blue'];
                $mode_labels = ['on' => 'On', 'off' => 'Off', 'detectonly' => 'Detect Only'];
            ?>
                <div class="ms-row">
                    <span class="ms-ic <?= $current_action === 'off' ? 'is-off' : ($current_action === 'detectonly' ? 'is-detect' : '') ?>"><i data-lucide="shield" class="lucide"></i></span>
                    <div class="ms-main">
                        <div class="ms-name"><?= h($pr['description']) ?></div>
                        <div class="ms-meta">
                            <code class="chip-mono">#<?= h($pr['rule_id']) ?></code>
                            <?php if ($existing): ?>
                                <span class="badge <?= $is_active ? 'badge-active' : 'badge-suspended' ?>"><?= $is_active ? 'Active' : 'Disabled' ?></span>
                            <?php else: ?>
                                <span class="badge badge-blue">Default</span>
                            <?php endif; ?>
                            <span class="badge <?= $mode_badges[$current_action] ?? 'badge-suspended' ?>"><?= $mode_labels[$current_action] ?? h($current_action) ?></span>
                        </div>
                    </div>
                    <form method="POST" class="ms-seg" title="Set rule mode">
                        <input type="hidden" name="action" value="save_rule">
                        <input type="hidden" name="domain" value="<?= h($filter_domain) ?>">
                        <input type="hidden" name="rule_id" value="<?= h($pr['rule_id']) ?>">
                        <button type="submit" name="rule_action" value="on" title="Block detected attacks" class="<?= $current_action === 'on' ? 'active on' : '' ?>">On</button>
                        <button type="submit" name="rule_action" value="detectonly" title="Log only" class="<?= $current_action === 'detectonly' ? 'active detect' : '' ?>">Detect</button>
                        <button type="submit" name="rule_action" value="off" title="Disable rule" class="<?= $current_action === 'off' ? 'active off' : '' ?>">Off</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ms-legend">
            <span><i data-lucide="shield-check" class="lucide"></i> On &mdash; blocks, returns 403</span>
            <span><i data-lucide="eye" class="lucide"></i> Detect &mdash; logs only</span>
            <span><i data-lucide="shield-off" class="lucide"></i> Off &mdash; disabled</span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in<?= $filter_domain ? '-delay-2' : '-delay-1' ?>">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> All Configured Rules (<?= count($rules) ?>)</h3></div>
    <?php if (!empty($rules)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="msSearch" placeholder="Search domains, rules..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="msCount"><?= count($rules) ?> config<?= count($rules) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($rules) ? '14px' : '16px' ?>">
        <?php if (empty($rules)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="shield-check" class="lucide"></i></div>
                <strong>No ModSecurity rules configured</strong>
                <p>Select a domain above, then enable the built-in ruleset with one click.</p>
            </div>
        <?php else: ?>
        <div class="ms-list">
            <?php foreach ($rules as $r):
                $mode_badges = ['on' => 'badge-active', 'off' => 'badge-suspended', 'detectonly' => 'badge-blue'];
                $mode_labels = ['on' => 'On', 'off' => 'Off', 'detectonly' => 'Detect Only'];
            ?>
                <div class="ms-row" data-name="<?= h(strtolower($r['domain'] . ' ' . $r['rule_id'] . ' ' . $r['description'] . ' ' . $r['action'])) ?>">
                    <span class="ms-ic <?= $r['action'] === 'off' ? 'is-off' : ($r['action'] === 'detectonly' ? 'is-detect' : '') ?>"><i data-lucide="shield" class="lucide"></i></span>
                    <div class="ms-main">
                        <div class="ms-name"><?= h($r['description'] ?: 'Rule ' . $r['rule_id']) ?></div>
                        <div class="ms-meta">
                            <code class="chip-mono"><?= h($r['domain']) ?></code>
                            <code class="chip-mono">#<?= h($r['rule_id']) ?></code>
                            <span class="badge <?= $mode_badges[$r['action']] ?? 'badge-suspended' ?>"><?= $mode_labels[$r['action']] ?? h($r['action']) ?></span>
                        </div>
                    </div>
                    <div class="ms-actions">
                        <a href="?domain=<?= urlencode($r['domain']) ?>" class="btn btn-sm btn-info" title="Tune rule for <?= h($r['domain']) ?>"><i data-lucide="pencil" class="lucide"></i></a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this rule override? The rule falls back to its default behavior.')">
                            <input type="hidden" name="action" value="delete_rule">
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
.ms-list{display:flex;flex-direction:column;gap:10px}
.ms-row{display:flex;align-items:center;gap:14px;padding:13px 14px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.ms-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.ms-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444);color:#fff;box-shadow:0 4px 10px rgba(220,38,38,.18)}
.ms-ic.is-detect{background:linear-gradient(135deg,#1d4ed8,#2563eb 55%,#3b82f6);box-shadow:0 4px 10px rgba(59,130,246,.18)}
.ms-ic.is-off{background:linear-gradient(135deg,#57534e,#78716c 55%,#a8a29e);box-shadow:0 4px 10px rgba(120,113,108,.18)}
.ms-ic .lucide{width:19px;height:19px}
.ms-main{min-width:0;flex:1}
.ms-name{font-weight:600;color:var(--text);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ms-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.ms-meta .badge{font-size:10px;padding:3px 8px}
.ms-seg{display:inline-flex;gap:0;border:1px solid var(--border);border-radius:var(--radius-xs);overflow:hidden;background:var(--bg);flex-shrink:0}
.ms-seg button{padding:6px 12px;border:none;background:transparent;color:var(--text4);font-size:11px;font-weight:700;cursor:pointer;font-family:var(--font);transition:all .15s}
.ms-seg button + button{border-left:1px solid var(--border)}
.ms-seg button:hover{color:var(--text)}
.ms-seg button.active.on{background:rgba(5,150,105,.14);color:var(--success)}
.ms-seg button.active.detect{background:rgba(0,115,230,.12);color:var(--primary)}
.ms-seg button.active.off{background:rgba(220,38,38,.12);color:var(--danger)}
.ms-actions{display:flex;gap:6px;flex-shrink:0}
.ms-legend{display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--text4);margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)}
.ms-legend span{display:inline-flex;align-items:center;gap:5px}
.ms-legend .lucide{width:12px;height:12px}
@media(max-width:640px){
  .ms-row{flex-wrap:wrap}
  .ms-main{flex-basis:100%}
  .ms-seg{width:100%;justify-content:stretch}
  .ms-seg button{flex:1}
  .ms-actions{width:100%}
  .ms-actions form,.ms-actions a{flex:1;display:flex}
  .ms-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var q = document.getElementById('msSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.ms-row[data-name]'));
        var c = document.getElementById('msCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' config' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>