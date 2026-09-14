<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('dns_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $domain = trim($_POST['domain'] ?? '');
        $name = trim($_POST['name'] ?? '@');
        $type = strtoupper(trim($_POST['type'] ?? 'A'));
        $content = trim($_POST['content'] ?? '');
        $ttl = (int)($_POST['ttl'] ?? 3600);
        $priority = (int)($_POST['priority'] ?? 0);

        if (!empty($domain) && !empty($content)) {
            $valid_types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];
            if (!in_array($type, $valid_types)) {
                flash('error', 'Invalid record type');
            } else {
                $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl, priority) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$user_id, $domain, $name ?: '@', $type, $content, $ttl, $priority]);

                $cf_name = ($name === '@' || $name === '') ? $domain : "{$name}.{$domain}";
                $cf_result = cloudflare_create_dns($type, $cf_name, $content, $ttl, false);
                $cf_msg = $cf_result['success'] ? ' (Cloudflare synced)' : ' (Cloudflare: ' . ($cf_result['error'] ?? 'failed') . ')';
                flash('success', "{$type} record added for {$name}.{$domain}{$cf_msg}");
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $ttl = (int)($_POST['ttl'] ?? 3600);
        $priority = (int)($_POST['priority'] ?? 0);
        if ($id > 0 && !empty($content)) {
            $db->prepare("UPDATE dns_records SET content = ?, ttl = ?, priority = ? WHERE id = ? AND user_id = ?")
               ->execute([$content, $ttl, $priority, $id, $user_id]);
            flash('success', 'DNS record updated locally');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $del_stmt = $db->prepare("SELECT domain, name, type FROM dns_records WHERE id = ? AND user_id = ?");
        $del_stmt->execute([$id, $user_id]);
        $del_rec = $del_stmt->fetch(PDO::FETCH_ASSOC);
        $db->prepare("DELETE FROM dns_records WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        if ($del_rec) {
            $cf_name = ($del_rec['name'] === '@') ? $del_rec['domain'] : "{$del_rec['name']}.{$del_rec['domain']}";
            cloudflare_delete_dns_by_name($cf_name);
        }
        flash('success', 'DNS record deleted (local + Cloudflare)');
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM dns_records WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rec) {
            $new = $rec['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE dns_records SET status = ? WHERE id = ?")->execute([$new, $id]);
            flash('success', "Record {$new} locally");
        }
    } elseif ($action === 'quick_a') {
        $domain = trim($_POST['domain'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        if (!empty($domain) && !empty($ip)) {
            $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl) VALUES (?, ?, '@', 'A', ?, 3600)")
               ->execute([$user_id, $domain, $ip]);
            $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl) VALUES (?, ?, 'www', 'CNAME', ?, 3600)")
               ->execute([$user_id, $domain, $domain]);
            cloudflare_create_dns('A', $domain, $ip, 3600, false);
            cloudflare_create_dns('CNAME', "www.{$domain}", $domain, 3600, false);
            flash('success', "Quick setup: A + www CNAME for {$domain} (Cloudflare synced)");
        }
    } elseif ($action === 'quick_mx') {
        $domain = trim($_POST['domain'] ?? '');
        $mail_server = trim($_POST['mail_server'] ?? '');
        if (!empty($domain) && !empty($mail_server)) {
            $mx_priority = (int)($_POST['mx_priority'] ?? 10);
            $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl, priority) VALUES (?, ?, '@', 'MX', ?, 1800, ?)")
               ->execute([$user_id, $domain, $mail_server, $mx_priority]);
            cloudflare_create_dns('MX', $domain, $mail_server, 1800, false);
            flash('success', "MX record added for {$domain} (Cloudflare synced)");
        }
    } elseif ($action === 'sync_cloudflare') {
        $sync_domain = trim($_POST['sync_domain'] ?? '');
        $filter_q = $sync_domain ? " AND domain = ?" : "";
        $filter_p = $sync_domain ? [$user_id, $sync_domain] : [$user_id];
        $sync_stmt = $db->prepare("SELECT * FROM dns_records WHERE user_id = ? {$filter_q} AND status = 'active'");
        $sync_stmt->execute($filter_p);
        $sync_recs = $sync_stmt->fetchAll(PDO::FETCH_ASSOC);

        $synced = 0;
        $failed = 0;
        foreach ($sync_recs as $rec) {
            $cf_name = ($rec['name'] === '@') ? $rec['domain'] : "{$rec['name']}.{$rec['domain']}";
            $result = cloudflare_create_dns($rec['type'], $cf_name, $rec['content'], $rec['ttl'], false);
            if ($result['success']) $synced++;
            else $failed++;
        }
        flash('success', "Cloudflare sync complete: {$synced} synced, {$failed} failed" . ($sync_domain ? " for {$sync_domain}" : ''));
    }
    redirect('/cpanel/dns.php' . (isset($_POST['domain_filter']) ? '?domain=' . urlencode($_POST['domain_filter']) : ''));
}

$filter_domain = $_GET['domain'] ?? '';
$query = "SELECT * FROM dns_records WHERE user_id = ?";
$params = [$user_id];
if ($filter_domain) {
    $query .= " AND domain = ?";
    $params[] = $filter_domain;
}
$query .= " ORDER BY domain, type, name";
$stmt = $db->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$domains_stmt = $db->prepare("SELECT DISTINCT domain FROM dns_records WHERE user_id = ?");
$domains_stmt->execute([$user_id]);
$domains = $domains_stmt->fetchAll(PDO::FETCH_COLUMN);

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_record = null;
if ($edit_id) {
    $stmt2 = $db->prepare("SELECT * FROM dns_records WHERE id = ? AND user_id = ?");
    $stmt2->execute([$edit_id, $user_id]);
    $edit_record = $stmt2->fetch(PDO::FETCH_ASSOC);
}

$nav = 'dns';
$page_title = 'DNS Zone Manager';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="network" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">DNS Zone Editor</div>
        <div class="hero-desc">Manage DNS records for your domains. Changes sync to Cloudflare automatically.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-purple" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="globe" class="lucide"></i> <?= count($domains) ?> zone<?= count($domains) === 1 ? '' : 's' ?></span>
    </div>
</div>

<?php if (!CF_ENABLED): ?>
<div class="dns-cf-warn fade-in">
    <div class="dns-cf-warn-icon"><i data-lucide="alert-triangle" class="lucide"></i></div>
    <div class="dns-cf-warn-text">
        <div class="dns-cf-warn-title">Cloudflare sync is disabled</div>
        <div class="dns-cf-warn-desc">Records are saved locally and served by the built-in authoritative DNS server (<code>scripts/dns_server.php</code>, port <code>5390</code>). To also publish them on Cloudflare, set <code>CF_API_TOKEN</code> and <code>CF_ZONE_ID</code> in <code>config.local.php</code> (or enable Cloudflare in WHM).</div>
    </div>
</div>
<?php endif; ?>

<div class="dns-header fade-in">
    <span class="dns-header-label"><i data-lucide="filter" class="lucide"></i> Filter by zone</span>
    <form method="GET" style="margin-left:auto;width:100%;max-width:240px">
        <select name="domain" onchange="this.form.submit()" class="dns-filter-select" style="width:100%">
            <option value="">All Domains</option>
            <?php foreach ($domains as $d): ?>
                <option value="<?= h($d) ?>" <?= $filter_domain === $d ? 'selected' : '' ?>><?= h($d) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($filter_domain): ?>
        <a href="/cpanel/dns.php" class="dns-btn-back"><i data-lucide="x" class="lucide"></i> Clear filter</a>
    <?php endif; ?>
</div>

<?php $active_count = 0; $disabled_count = 0; ?>
<?php foreach ($records as $r): if ($r['status'] === 'active') $active_count++; else $disabled_count++; endforeach; ?>

<div class="dns-stats fade-in-delay-1">
    <div class="dns-stat">
        <div class="dns-stat-icon" style="background:rgba(0,115,230,.1);color:#0073e6"><i data-lucide="globe" class="lucide"></i></div>
        <div class="dns-stat-body">
            <div class="dns-stat-val"><?= count($domains) ?></div>
            <div class="dns-stat-lbl">Domains</div>
            <div class="dns-stat-sub">zones tracked</div>
        </div>
    </div>
    <div class="dns-stat">
        <div class="dns-stat-icon" style="background:rgba(99,102,241,.1);color:#6366f1"><i data-lucide="list" class="lucide"></i></div>
        <div class="dns-stat-body">
            <div class="dns-stat-val"><?= count($records) ?></div>
            <div class="dns-stat-lbl">Total Records</div>
            <div class="dns-stat-sub">all record types</div>
        </div>
    </div>
    <div class="dns-stat">
        <div class="dns-stat-icon" style="background:rgba(5,150,105,.1);color:#059669"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="dns-stat-body">
            <div class="dns-stat-val"><?= $active_count ?></div>
            <div class="dns-stat-lbl">Active</div>
            <div class="dns-stat-sub">resolving now</div>
        </div>
    </div>
    <div class="dns-stat">
        <div class="dns-stat-icon" style="background:rgba(148,163,184,.15);color:#94a3b8"><i data-lucide="pause-circle" class="lucide"></i></div>
        <div class="dns-stat-body">
            <div class="dns-stat-val"><?= $disabled_count ?></div>
            <div class="dns-stat-lbl">Disabled</div>
            <div class="dns-stat-sub">paused locally</div>
        </div>
    </div>
</div>

<?php if ($edit_record): ?>
<div class="dns-edit-card fade-in">
    <div class="dns-edit-top">
        <div class="dns-edit-title"><i data-lucide="edit-3" class="lucide"></i> Edit <?= h($edit_record['type']) ?> Record</div>
        <a href="/cpanel/dns.php<?= $filter_domain ? '?domain=' . urlencode($filter_domain) : '' ?>" class="dns-btn-back"><i data-lucide="arrow-left" class="lucide"></i> Back</a>
    </div>
    <div class="dns-edit-info">
        <span class="dns-rec-type dns-type-<?= strtolower($edit_record['type']) ?>"><?= h($edit_record['type']) ?></span>
        <span class="dns-rec-name"><?= h($edit_record['name'] . '.' . $edit_record['domain']) ?></span>
    </div>
    <form method="POST" class="dns-edit-form">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
        <input type="hidden" name="domain_filter" value="<?= h($filter_domain) ?>">
        <div class="dns-edit-grid">
            <div class="dns-field">
                <label class="dns-label">Value / Content</label>
                <input type="text" name="content" value="<?= h($edit_record['content']) ?>" required class="dns-inp">
            </div>
            <div class="dns-field">
                <label class="dns-label">TTL</label>
                <select name="ttl" class="dns-inp">
                    <option value="300" <?= $edit_record['ttl']==300 ? 'selected' : '' ?>>5 minutes</option>
                    <option value="600" <?= $edit_record['ttl']==600 ? 'selected' : '' ?>>10 minutes</option>
                    <option value="1800" <?= $edit_record['ttl']==1800 ? 'selected' : '' ?>>30 minutes</option>
                    <option value="3600" <?= $edit_record['ttl']==3600 ? 'selected' : '' ?>>1 hour</option>
                    <option value="7200" <?= $edit_record['ttl']==7200 ? 'selected' : '' ?>>2 hours</option>
                    <option value="86400" <?= $edit_record['ttl']==86400 ? 'selected' : '' ?>>1 day</option>
                </select>
            </div>
            <?php if ($edit_record['type'] === 'MX' || $edit_record['type'] === 'SRV'): ?>
            <div class="dns-field">
                <label class="dns-label">Priority</label>
                <input type="number" name="priority" value="<?= $edit_record['priority'] ?>" min="0" max="65535" class="dns-inp">
            </div>
            <?php endif; ?>
            <div class="dns-field dns-field-btn">
                <button type="submit" class="dns-btn-primary"><i data-lucide="save" class="lucide"></i> Save Changes</button>
            </div>
        </div>
    </form>
</div>
<?php else: ?>

<div class="dns-grid fade-in-delay-1">
    <div class="dns-quick-grid">
        <div class="dns-qcard">
            <div class="dns-qcard-title"><i data-lucide="zap" class="lucide"></i> Quick A Record + www CNAME</div>
            <form method="POST">
                <input type="hidden" name="action" value="quick_a">
                <div class="dns-qfield">
                    <input type="text" name="domain" required placeholder="example.com" class="dns-inp">
                </div>
                <div class="dns-qfield">
                    <input type="text" name="ip" required placeholder="Server IP address" class="dns-inp">
                </div>
                <button type="submit" class="dns-btn-primary" style="width:100%"><i data-lucide="zap" class="lucide"></i> Create A + www CNAME</button>
            </form>
        </div>
        <div class="dns-qcard">
            <div class="dns-qcard-title"><i data-lucide="mail" class="lucide"></i> Add MX Mail Server</div>
            <form method="POST">
                <input type="hidden" name="action" value="quick_mx">
                <div class="dns-qfield">
                    <input type="text" name="domain" required placeholder="example.com" class="dns-inp">
                </div>
                <div class="dns-qfield">
                    <input type="text" name="mail_server" required placeholder="mail.example.com" class="dns-inp">
                </div>
                <div class="dns-qfield">
                    <input type="number" name="mx_priority" value="10" min="1" max="65535" class="dns-inp">
                </div>
                <button type="submit" class="dns-btn-primary" style="width:100%"><i data-lucide="mail" class="lucide"></i> Add MX Record</button>
            </form>
        </div>
    </div>

    <div class="dns-add-card">
        <div class="dns-add-title"><i data-lucide="plus-circle" class="lucide"></i> Add DNS Record</div>
        <form method="POST" id="dnsForm">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="domain_filter" value="<?= h($filter_domain) ?>">
            <div class="dns-field">
                <label class="dns-label">Domain</label>
                <div class="dns-domain-wrap">
                    <input type="text" name="domain" required placeholder="example.com" id="dnsDomain" list="dns-domains" class="dns-inp" <?= $filter_domain ? 'value="' . h($filter_domain) . '"' : '' ?>>
                    <datalist id="dns-domains">
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= h($d) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <div class="dns-field">
                <label class="dns-label">Name</label>
                <input type="text" name="name" value="@" placeholder="@ or mail/www" id="dnsName" class="dns-inp">
            </div>
            <div class="dns-field">
                <label class="dns-label">Type</label>
                <select name="type" id="dnsType" onchange="togglePriority();syncTypeTag()" class="dns-inp">
                    <option value="A">A</option>
                    <option value="AAAA">AAAA</option>
                    <option value="CNAME">CNAME</option>
                    <option value="MX">MX</option>
                    <option value="TXT">TXT</option>
                    <option value="NS">NS</option>
                    <option value="SRV">SRV</option>
                    <option value="CAA">CAA</option>
                </select>
            </div>
            <div class="dns-fqdn-preview" id="dnsFqdnPreview"><i data-lucide="info" class="lucide"></i> Will create <strong id="dnsFqdnText">@.example.com</strong></div>
            <div class="dns-field">
                <label class="dns-label">Value / Content</label>
                <input type="text" name="content" id="dnsContent" required placeholder="e.g. 192.168.1.1" class="dns-inp">
            </div>
            <div class="dns-row2">
                <div class="dns-field">
                    <label class="dns-label">TTL</label>
                    <select name="ttl" class="dns-inp">
                        <option value="300">5 min</option>
                        <option value="600">10 min</option>
                        <option value="1800">30 min</option>
                        <option value="3600" selected>1 hour</option>
                        <option value="7200">2 hours</option>
                        <option value="86400">1 day</option>
                    </select>
                </div>
                <div class="dns-field" id="priorityField" style="display:none">
                    <label class="dns-label">Priority</label>
                    <input type="number" name="priority" value="10" min="0" max="65535" class="dns-inp">
                </div>
            </div>
            <div class="dns-record-types">
                <?php $types = ['A'=>'Maps domain to IPv4','AAAA'=>'Maps domain to IPv6','CNAME'=>'Aliases one domain to another','MX'=>'Mail server','TXT'=>'SPF, DKIM, etc.','NS'=>'Name server','SRV'=>'Service location','CAA'=>'Certificate auth']; ?>
                <?php foreach ($types as $t => $tip): ?>
                    <span class="dns-type-tag" onclick="setDnsType('<?= $t ?>')" title="<?= $tip ?>"><?= $t ?></span>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="dns-btn-primary" style="width:100%;margin-top:12px"><i data-lucide="plus" class="lucide"></i> Add Record &amp; Sync to Cloudflare</button>
        </form>
    </div>
</div>

<div class="dns-cf-card fade-in-delay-1">
    <div class="dns-cf-icon"><i data-lucide="cloud" class="lucide"></i></div>
    <div class="dns-cf-text">
        <div class="dns-cf-title">Cloudflare Sync</div>
        <div class="dns-cf-desc">Push all active DNS records to Cloudflare for a single domain or all domains</div>
    </div>
    <form method="POST" class="dns-cf-form">
        <input type="hidden" name="action" value="sync_cloudflare">
        <select name="sync_domain" class="dns-inp" style="width:auto;min-width:160px">
            <option value="">All Domains</option>
            <?php foreach ($domains as $d): ?>
                <option value="<?= h($d) ?>"><?= h($d) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="dns-btn-primary"><i data-lucide="upload-cloud" class="lucide"></i> Sync Now</button>
    </form>
</div>

<div class="dns-zone-card fade-in-delay-2">
    <div class="dns-zone-header">
        <div class="dns-zone-title"><i data-lucide="list" class="lucide"></i> Zone Records</div>
        <div class="dns-zone-count" id="dnsVisibleCount"><?= count($records) ?> total</div>
    </div>
    <?php if (empty($records)): ?>
        <div class="dns-empty">
            <div class="dns-empty-icon"><i data-lucide="link" class="lucide"></i></div>
            <p>No DNS records yet</p>
            <p class="dns-empty-sub">Add your first record above or use Quick Setup</p>
        </div>
    <?php else: ?>
        <div class="dns-zone-toolbar">
            <div class="dns-search-wrap">
                <i data-lucide="search" class="dns-search-icon"></i>
                <input type="text" id="dnsSearch" placeholder="Search name, content, domain..." class="dns-search-input" autocomplete="off" spellcheck="false">
                <kbd class="dns-search-kbd">/</kbd>
            </div>
            <div class="dns-type-filters" id="dnsTypeFilters">
                <?php
                $type_counts = [];
                foreach ($records as $r) { $t = $r['type']; $type_counts[$t] = ($type_counts[$t] ?? 0) + 1; }
                ksort($type_counts);
                ?>
                <button type="button" class="dns-tf dns-tf-active" data-type="">All</button>
                <?php foreach ($type_counts as $t => $c): ?>
                    <button type="button" class="dns-tf" data-type="<?= h($t) ?>"><?= h($t) ?> <span class="dns-tf-count"><?= $c ?></span></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        $grouped = [];
        foreach ($records as $r) {
            $grouped[$r['domain']][] = $r;
        }
        ?>
        <?php foreach ($grouped as $domain => $recs): ?>
        <div class="dns-domain-section" data-domain="<?= h($domain) ?>">
            <div class="dns-domain-head">
                <div class="dns-domain-name"><i data-lucide="globe" class="lucide"></i> <?= h($domain) ?></div>
                <div class="dns-domain-count"><?= count($recs) ?> records</div>
            </div>
            <div class="dns-records">
                <?php foreach ($recs as $r): ?>
                <?php $fqdn = ($r['name'] === '@' || $r['name'] === '') ? $r['domain'] : $r['name'] . '.' . $r['domain']; ?>
                <div class="dns-record<?= $r['status'] === 'disabled' ? ' off' : '' ?>"
                     data-type="<?= h($r['type']) ?>"
                     data-search="<?= h(strtolower($fqdn . ' ' . $r['domain'] . ' ' . $r['name'] . ' ' . $r['content'] . ' ' . $r['type'])) ?>">
                    <div class="dns-rec-main">
                        <div class="dns-rec-left">
                            <span class="dns-rec-type dns-type-<?= strtolower($r['type']) ?>"><?= h($r['type']) ?></span>
                            <div class="dns-rec-namecol">
                                <span class="dns-rec-name"><?= h($fqdn) ?></span>
                                <span class="dns-rec-sub"><?= h($r['name'] === '@' ? 'root domain' : $r['name']) ?> &middot; <?= $r['ttl'] >= 86400 ? round($r['ttl']/86400).'d' : ($r['ttl'] >= 3600 ? round($r['ttl']/3600).'h' : ($r['ttl']/60).'m') ?> TTL<?= ($r['type'] === 'MX' || $r['type'] === 'SRV') ? ' &middot; priority ' . $r['priority'] : '' ?></span>
                            </div>
                        </div>
                        <div class="dns-rec-contentwrap">
                            <span class="dns-rec-content"><?= h($r['content']) ?></span>
                            <button type="button" class="dns-copy-btn" title="Copy value" data-copy="<?= h($r['content']) ?>"><i data-lucide="copy" class="lucide"></i></button>
                        </div>
                    </div>
                    <div class="dns-rec-meta">
                        <span class="dns-rec-status dns-status-<?= $r['status'] ?>"><?= h($r['status']) ?></span>
                        <label class="dns-toggle">
                            <input type="checkbox" <?= $r['status'] === 'active' ? 'checked' : '' ?> onchange="dnsToggle(<?= $r['id'] ?>)">
                            <span class="dns-toggle-track"><span class="dns-toggle-knob"></span></span>
                        </label>
                        <a href="?edit=<?= $r['id'] ?><?= $filter_domain ? '&domain=' . urlencode($filter_domain) : '' ?>" class="dns-rec-btn dns-rec-edit" title="Edit"><i data-lucide="edit-3" class="lucide"></i></a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this DNS record?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="domain_filter" value="<?= h($filter_domain) ?>">
                            <button type="submit" class="dns-rec-btn dns-rec-del" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<form method="POST" id="dnsToggleForm" style="display:none">
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="id" id="dnsToggleId">
    <input type="hidden" name="domain_filter" value="<?= h($filter_domain) ?>">
</form>

<style>
.dns-header{
    display:flex;align-items:center;gap:16px;
    padding:12px 18px;background:var(--bg2);border:1px solid var(--border);
    border-radius:var(--radius);margin-bottom:16px;flex-wrap:wrap;
}
.dns-cf-warn{
    display:flex;align-items:flex-start;gap:12px;
    padding:12px 16px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.3);
    border-radius:var(--radius);margin-bottom:16px;
}
.dns-cf-warn-icon{
    width:32px;height:32px;border-radius:8px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    background:rgba(245,158,11,.15);color:#f59e0b;
}
.dns-cf-warn-icon .lucide{width:16px;height:16px}
.dns-cf-warn-text{flex:1;min-width:0}
.dns-cf-warn-title{font-size:13px;font-weight:700;color:#f59e0b}
.dns-cf-warn-desc{font-size:11.5px;color:var(--text3);margin-top:2px;line-height:1.5}
.dns-cf-warn-desc code{
    background:var(--bg4);border:1px solid var(--border);border-radius:4px;
    padding:1px 5px;font-size:10.5px;font-family:monospace;color:var(--text2);
}
.dns-header-label{
    display:inline-flex;align-items:center;gap:7px;
    font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap;
}
    display:inline-flex;align-items:center;gap:7px;
    font-size:12px;font-weight:600;color:var(--text3);white-space:nowrap;
}
.dns-header-label .lucide{width:14px;height:14px;color:#6366f1}
.dns-filter-select{
    padding:7px 12px;border:1.5px solid var(--border);border-radius:var(--radius-xs);
    font-size:12px;background:var(--bg);color:var(--text);cursor:pointer;outline:none;
    -webkit-appearance:none;appearance:none;
}
.dns-filter-select:focus{border-color:#6366f1}

.dns-stats{
    display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;
}
@media(max-width:700px){.dns-stats{grid-template-columns:repeat(2,1fr)}}
.dns-stat{
    display:flex;align-items:center;gap:12px;
    padding:14px 16px;background:var(--bg2);border:1px solid var(--border);
    border-radius:var(--radius);transition:border-color .2s,transform .2s,box-shadow .2s;
}
.dns-stat:hover{border-color:var(--text4);transform:translateY(-2px);box-shadow:var(--shadow)}
.dns-stat-icon{
    width:38px;height:38px;border-radius:9px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
}
.dns-stat-icon .lucide{width:18px;height:18px}
.dns-stat-body{display:flex;flex-direction:column;min-width:0}
.dns-stat-val{font-size:18px;font-weight:700;color:var(--text);line-height:1.1}
.dns-stat-lbl{font-size:11px;color:var(--text4);font-weight:500;margin-top:1px}
.dns-stat-sub{font-size:10.5px;color:var(--text4);opacity:.75;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.dns-inp{
    width:100%;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--radius-xs);
    font-size:13px;background:var(--bg);color:var(--text);outline:none;transition:border-color .15s;
    -webkit-appearance:none;appearance:none;box-sizing:border-box;
}
.dns-inp:focus{border-color:#6366f1}
select.dns-inp{cursor:pointer}
.dns-label{display:block;font-size:12px;font-weight:600;color:var(--text3);margin-bottom:4px}
.dns-field{margin-bottom:10px}
.dns-field-btn{display:flex;align-items:flex-end;margin-bottom:0}
.dns-btn-primary{
    display:inline-flex;align-items:center;gap:6px;justify-content:center;
    padding:9px 20px;border:none;border-radius:var(--radius-xs);
    background:#6366f1;color:#fff;font-size:13px;font-weight:600;
    cursor:pointer;transition:opacity .15s;
}
.dns-btn-primary:hover{opacity:.9}
.dns-btn-primary .lucide{width:15px;height:15px}
.dns-row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}

.dns-fqdn-preview{
    display:flex;align-items:center;gap:6px;
    padding:7px 12px;margin-bottom:10px;
    background:rgba(99,102,241,.06);border:1px dashed rgba(99,102,241,.3);
    border-radius:var(--radius-xs);font-size:12px;color:var(--text3);
}
.dns-fqdn-preview .lucide{width:13px;height:13px;color:#6366f1;flex-shrink:0}
.dns-fqdn-preview strong{color:#6366f1;font-family:monospace;font-weight:700;word-break:break-all}

.dns-edit-card{
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);
    padding:20px;margin-bottom:20px;
}
.dns-edit-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.dns-edit-title{font-size:15px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.dns-edit-title .lucide{width:18px;height:18px;color:#6366f1}
.dns-btn-back{
    display:inline-flex;align-items:center;gap:5px;
    padding:6px 14px;border:1.5px solid var(--border);border-radius:var(--radius-xs);
    text-decoration:none;font-size:12px;font-weight:600;color:var(--text3);transition:background .15s;
}
.dns-btn-back:hover{background:var(--bg4)}
.dns-btn-back .lucide{width:14px;height:14px}
.dns-edit-info{
    display:flex;align-items:center;gap:10px;
    padding:10px 14px;background:var(--bg3);border:1px solid var(--border);
    border-radius:var(--radius-xs);margin-bottom:16px;
}
.dns-edit-form .dns-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.dns-edit-form .dns-edit-grid{grid-template-columns:1fr}}
.dns-rec-type{
    font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px;
    font-family:monospace;text-transform:uppercase;flex-shrink:0;line-height:1.4;
}
.dns-type-a{background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.2)}
.dns-type-aaaa{background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.2)}
.dns-type-cname{background:rgba(34,197,94,.1);color:#22c55e;border:1px solid rgba(34,197,94,.2)}
.dns-type-mx{background:rgba(251,146,60,.1);color:#f97316;border:1px solid rgba(251,146,60,.2)}
.dns-type-txt{background:rgba(99,102,241,.1);color:#6366f1;border:1px solid rgba(99,102,241,.2)}
.dns-type-ns{background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
.dns-type-srv{background:rgba(168,85,247,.1);color:#a855f7;border:1px solid rgba(168,85,247,.2)}
.dns-type-caa{background:rgba(236,72,153,.1);color:#ec4899;border:1px solid rgba(236,72,153,.2)}

.dns-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:16px;align-items:start;margin-bottom:16px}
@media(max-width:900px){.dns-grid{grid-template-columns:1fr}}

.dns-quick-grid{display:flex;flex-direction:column;gap:12px}
.dns-qcard{
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);
    padding:16px;transition:border-color .2s,transform .2s,box-shadow .2s;
}
.dns-qcard:hover{border-color:var(--text4);transform:translateY(-2px);box-shadow:var(--shadow)}
.dns-qcard-title{
    font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px;
    display:flex;align-items:center;gap:7px;
}
.dns-qcard-title .lucide{width:16px;height:16px;color:#6366f1}
.dns-qfield{margin-bottom:8px}

.dns-add-card{
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);
    padding:20px;transition:border-color .2s;
}
.dns-add-card:hover{border-color:var(--text4)}
.dns-add-title{
    font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px;
    display:flex;align-items:center;gap:8px;
}
.dns-add-title .lucide{width:18px;height:18px;color:#6366f1}
.dns-domain-wrap{display:flex}
.dns-domain-wrap .dns-inp{border-radius:var(--radius-xs);flex:1}
.dns-record-types{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
.dns-type-tag{
    font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;
    background:var(--bg4);border:1px solid var(--border);color:var(--text3);
    cursor:pointer;transition:border-color .15s,color .15s;font-family:monospace;
}
.dns-type-tag:hover{border-color:#6366f1;color:#6366f1}
.dns-type-tag-active{background:#6366f1;border-color:#6366f1;color:#fff}
.dns-type-tag-active:hover{color:#fff;background:#4f46e5}

.dns-cf-card{
    display:flex;align-items:center;gap:14px;flex-wrap:wrap;
    padding:14px 20px;background:var(--bg2);border:1px solid var(--border);
    border-radius:var(--radius);margin-bottom:16px;
}
.dns-cf-icon{
    width:38px;height:38px;border-radius:8px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    background:rgba(59,130,246,.1);color:#3b82f6;
}
.dns-cf-icon .lucide{width:18px;height:18px}
.dns-cf-text{flex:1;min-width:160px}
.dns-cf-title{font-size:13px;font-weight:700;color:var(--text)}
.dns-cf-desc{font-size:11px;color:var(--text4);margin-top:1px}
.dns-cf-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

.dns-zone-card{
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);
    overflow:hidden;
}
.dns-zone-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:16px 20px;border-bottom:1px solid var(--border);
}
.dns-zone-title{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.dns-zone-title .lucide{width:17px;height:17px;color:#6366f1}
.dns-zone-count{font-size:11px;color:var(--text4);padding:2px 10px;border-radius:99px;background:var(--bg4)}

.dns-zone-toolbar{
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    padding:12px 20px;border-bottom:1px solid var(--border);background:var(--bg3);
}
.dns-search-wrap{position:relative;flex:1;min-width:180px}
.dns-search-icon{
    position:absolute;left:10px;top:50%;transform:translateY(-50%);
    width:14px;height:14px;color:var(--text4);pointer-events:none;
}
.dns-search-input{
    width:100%;padding:7px 12px 7px 32px;
    border:1.5px solid var(--border);border-radius:var(--radius-xs);
    font-size:12px;background:var(--bg2);color:var(--text);outline:none;transition:border-color .15s;
}
.dns-search-input:focus{border-color:#6366f1}
.dns-search-kbd{
    position:absolute;right:10px;top:50%;transform:translateY(-50%);
    font-size:10px;font-family:inherit;color:var(--text4);
    border:1px solid var(--border);border-radius:4px;padding:1px 5px;background:var(--bg);
}
.dns-type-filters{display:flex;flex-wrap:wrap;gap:5px}
.dns-tf{
    padding:4px 10px;border:1px solid var(--border);border-radius:99px;
    background:var(--bg2);color:var(--text3);font-size:11px;font-weight:600;
    cursor:pointer;transition:all .15s;font-family:monospace;
}
.dns-tf:hover{border-color:#6366f1;color:#6366f1}
.dns-tf-active{background:#6366f1;border-color:#6366f1;color:#fff}
.dns-tf-count{opacity:.7;font-size:10px}
.dns-tf-active .dns-tf-count{opacity:.85}

.dns-empty{padding:40px 20px;text-align:center;color:var(--text4)}
.dns-empty-icon{margin-bottom:10px}
.dns-empty-icon .lucide{width:32px;height:32px}
.dns-empty p{margin:0;font-size:14px}
.dns-empty-sub{font-size:12px;margin-top:4px}

.dns-domain-section{border-bottom:1px solid var(--border)}
.dns-domain-section:last-child{border-bottom:none}
.dns-domain-section.hidden{display:none}
.dns-domain-head{
    display:flex;align-items:center;justify-content:space-between;
    padding:10px 20px;background:var(--bg3);
}
.dns-domain-name{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px}
.dns-domain-name .lucide{width:15px;height:15px;color:#6366f1}
.dns-domain-count{font-size:11px;color:var(--text4)}

.dns-records{padding:8px 12px}
.dns-record{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:11px 12px;border:1px solid var(--border);border-radius:var(--radius-xs);
    margin-bottom:6px;background:var(--bg);transition:border-color .15s,box-shadow .15s;
}
.dns-record:hover{border-color:#c7ccd4;box-shadow:var(--shadow-xs)}
.dns-record.off{opacity:.55}
.dns-record.hidden{display:none}
.dns-rec-main{display:flex;align-items:center;gap:14px;flex:1;min-width:0}
.dns-rec-left{display:flex;align-items:center;gap:8px;min-width:0}
.dns-rec-namecol{display:flex;flex-direction:column;min-width:0}
.dns-rec-name{font-size:13px;font-weight:600;color:var(--text);font-family:monospace;word-break:break-all;line-height:1.35}
.dns-rec-sub{font-size:10.5px;color:var(--text4);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dns-rec-contentwrap{
    display:flex;align-items:center;gap:6px;min-width:0;flex:1;
    background:var(--bg2);border:1px solid var(--border2);
    border-radius:6px;padding:4px 6px 4px 10px;
}
.dns-rec-content{font-size:12px;color:var(--text2);font-family:monospace;word-break:break-all;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dns-copy-btn{
    display:inline-flex;align-items:center;justify-content:center;
    width:24px;height:24px;border:none;border-radius:5px;flex-shrink:0;cursor:pointer;
    background:transparent;color:var(--text4);transition:all .15s;
}
.dns-copy-btn .lucide{width:12px;height:12px}
.dns-copy-btn:hover{background:rgba(99,102,241,.1);color:#6366f1}
.dns-copy-btn.copied{background:rgba(34,197,94,.12);color:#22c55e}
.dns-rec-meta{display:flex;align-items:center;gap:8px;flex-shrink:0}
.dns-rec-status{font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;text-transform:uppercase}
.dns-status-active{background:rgba(34,197,94,.1);color:#22c55e;border:1px solid rgba(34,197,94,.2)}
.dns-status-disabled{background:var(--bg4);color:var(--text4);border:1px solid var(--border)}
.dns-rec-btn{
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:7px;cursor:pointer;transition:background .15s;
    text-decoration:none;
}
.dns-rec-btn .lucide{width:14px;height:14px}
.dns-rec-edit{background:rgba(99,102,241,.08);color:#6366f1}
.dns-rec-edit:hover{background:rgba(99,102,241,.18)}
.dns-rec-del{background:rgba(239,68,68,.08);color:#ef4444}
.dns-rec-del:hover{background:rgba(239,68,68,.18)}

.dns-toggle{display:inline-flex;cursor:pointer;flex-shrink:0}
.dns-toggle input{display:none}
.dns-toggle-track{
    width:32px;height:18px;border-radius:99px;background:var(--bg4);border:1.5px solid var(--border);
    position:relative;transition:background .2s,border-color .2s;
}
.dns-toggle input:checked + .dns-toggle-track{background:#6366f1;border-color:#6366f1}
.dns-toggle-knob{
    width:12px;height:12px;border-radius:50%;background:#fff;
    position:absolute;top:2px;left:2px;transition:transform .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.15);
}
.dns-toggle input:checked + .dns-toggle-track .dns-toggle-knob{transform:translateX(14px)}

@media(max-width:720px){
    .dns-record{flex-wrap:wrap}
    .dns-rec-main{flex-wrap:wrap}
    .dns-rec-contentwrap{width:100%;order:3}
    .dns-rec-content{white-space:normal;overflow:visible}
    .dns-rec-meta{width:100%;justify-content:flex-end}
}
</style>

<script>
function syncTypeTag() {
    var sel = document.getElementById('dnsType');
    if (!sel) return;
    var cur = sel.value;
    document.querySelectorAll('.dns-type-tag').forEach(function (t) {
        t.classList.toggle('dns-type-tag-active', t.textContent.trim() === cur);
    });
}
function togglePriority() {
    var type = document.getElementById('dnsType').value;
    var field = document.getElementById('priorityField');
    var input = document.getElementById('dnsContent');
    field.style.display = (type === 'MX' || type === 'SRV') ? '' : 'none';
    var ph = {A:'e.g. 192.168.1.1',AAAA:'e.g. 2001:db8::1',CNAME:'e.g. example.com',MX:'e.g. mail.example.com',TXT:'e.g. v=spf1 include:_spf.google.com ~all',NS:'e.g. ns1.example.com',SRV:'e.g. 10 5060 sip.example.com',CAA:'e.g. 0 issue "letsencrypt.org"'};
    input.placeholder = ph[type] || 'Value';
}
function setDnsType(type) {
    document.getElementById('dnsType').value = type;
    togglePriority();
    syncTypeTag();
}
function dnsToggle(id) {
    document.getElementById('dnsToggleId').value = id;
    document.getElementById('dnsToggleForm').submit();
}
(function () {
    function liveFqdn() {
        var domEl = document.getElementById('dnsDomain');
        var nameEl = document.getElementById('dnsName');
        var outEl = document.getElementById('dnsFqdnText');
        if (!domEl || !nameEl || !outEl) return;
        var domain = domEl.value.trim().toLowerCase();
        var name = (nameEl.value || '@').trim().toLowerCase();
        var fqdn = domain ? ((!name || name === '@') ? domain : name + '.' + domain) : '@.example.com';
        outEl.textContent = fqdn;
    }
    var domEl = document.getElementById('dnsDomain');
    var nameEl = document.getElementById('dnsName');
    if (domEl) domEl.addEventListener('input', liveFqdn);
    if (nameEl) nameEl.addEventListener('input', liveFqdn);
    liveFqdn();

    var records = Array.prototype.slice.call(document.querySelectorAll('.dns-record'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('.dns-domain-section'));
    var searchEl = document.getElementById('dnsSearch');
    var filterBtns = Array.prototype.slice.call(document.querySelectorAll('.dns-type-filters .dns-tf'));
    var visibleCount = document.getElementById('dnsVisibleCount');
    var activeType = '';
    var emptyState = null;

    function showEmptyIfNeeded() {
        var anyVisible = records.some(function (r) { return !r.classList.contains('hidden'); });
        if (!emptyState) {
            emptyState = document.createElement('div');
            emptyState.className = 'dns-empty';
            emptyState.innerHTML = '<div class="dns-empty-icon"><i data-lucide="search-x" class="lucide"></i></div><p>No matching records</p><p class="dns-empty-sub">Try a different search or type filter</p>';
        }
        var zoneCard = document.querySelector('.dns-zone-card');
        var toolbar = document.querySelector('.dns-zone-toolbar');
        if (zoneCard && toolbar && !zoneCard.contains(emptyState)) {
            emptyState.style.display = 'none';
            toolbar.after(emptyState);
        }
        if (emptyState) {
            emptyState.style.display = anyVisible ? 'none' : 'block';
            if (window.lucide && typeof window.lucide.createIcons === 'function' && !anyVisible) {
                window.lucide.createIcons({root: emptyState});
            }
        }
        if (visibleCount) {
            var total = records.length;
            var shown = records.filter(function (r) { return !r.classList.contains('hidden'); }).length;
            visibleCount.textContent = shown + ' of ' + total + ' total';
        }
    }

    function applyFilters() {
        var q = searchEl ? searchEl.value.toLowerCase().trim() : '';
        records.forEach(function (r) {
            var typeOk = !activeType || r.getAttribute('data-type') === activeType;
            var searchOk = !q || (r.getAttribute('data-search') || '').indexOf(q) !== -1;
            r.classList.toggle('hidden', !(typeOk && searchOk));
        });
        sections.forEach(function (s) {
            var vis = s.querySelectorAll('.dns-record:not(.hidden)').length > 0;
            s.classList.toggle('hidden', !vis);
        });
        showEmptyIfNeeded();
    }

    if (searchEl) searchEl.addEventListener('input', applyFilters);
    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
        if (e.key === '/' && searchEl) {
            e.preventDefault();
            searchEl.focus();
        }
    });
    filterBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filterBtns.forEach(function (b) { b.classList.remove('dns-tf-active'); });
            btn.classList.add('dns-tf-active');
            activeType = btn.getAttribute('data-type');
            applyFilters();
        });
    });

    function flashCopy(btn) {
        var icon = btn.querySelector('.lucide');
        var old = icon ? icon.getAttribute('data-lucide') : 'copy';
        if (icon) icon.setAttribute('data-lucide', 'check');
        btn.classList.add('copied');
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons({root: btn});
        }
        setTimeout(function () {
            btn.classList.remove('copied');
            if (icon) icon.setAttribute('data-lucide', old);
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons({root: btn});
            }
        }, 1200);
    }

    document.querySelectorAll('.dns-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-copy') || '';
            var done = function () { flashCopy(btn); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(val).then(done).catch(function () { fallbackCopy(val, btn); });
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

})();
togglePriority();
syncTypeTag();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
