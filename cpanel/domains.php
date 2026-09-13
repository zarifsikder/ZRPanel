<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();

$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_domain = $user['domain'] ?: SITE_DOMAIN;

$subdomains = [];
try {
    $stmt = $db->prepare("SELECT * FROM subdomains WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $subdomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$all_domains = [$user_domain];
foreach ($subdomains as $s) {
    $all_domains[] = $s['subdomain'] . '.' . $s['domain'];
}

// DNS record counts (zone for the main domain, per-subdomain A records otherwise)
$dnsByDomain = [];
try {
    $stmt = $db->prepare("SELECT domain, name, COUNT(*) AS c FROM dns_records WHERE user_id = ? GROUP BY domain, name");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)$row['name']);
        $key = ($name === '' || $name === '@') ? $row['domain'] : $name . '.' . $row['domain'];
        $dnsByDomain[$key] = (int)$row['c'] + ($dnsByDomain[$key] ?? 0);
    }
} catch (Exception $e) {}

// SSL certificates
$sslByDomain = [];
try {
    $stmt = $db->prepare("SELECT domain, expires_at, status FROM ssl_certs WHERE user_id = ?");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $sslByDomain[$c['domain']] = $c;
    }
} catch (Exception $e) {}

$total_dns = array_sum($dnsByDomain);
$total_ssl = count($sslByDomain);

$avatars = [
    ['#0073e6', '#38bdf8'], ['#7c3aed', '#c084fc'], ['#059669', '#4ade80'],
    ['#d97706', '#fbbf24'], ['#dc2626', '#f87171'], ['#0d9488', '#2dd4bf'],
    ['#2563eb', '#818cf8'], ['#db2777', '#f472b6'],
];
function dm_avatar_gradient($str, $palette) {
    $h = 0;
    foreach (str_split($str) as $ch) { $h = ($h * 31 + ord($ch)) & 0x7fffffff; }
    $g = $palette[$h % count($palette)];
    return "linear-gradient(135deg, {$g[0]} 0%, {$g[1]} 100%)";
}

$homeRoot = rtrim($user['home_dir'] ?? '', '/');

$nav = 'domains';
$page_title = 'My Domains';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="globe" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">My Domains</div>
        <div class="hero-desc">Manage your domains, subdomains, and DNS records in one place.</div>
    </div>
    <div class="hero-actions">
        <a href="//<?= h($user_domain) ?>" target="_blank" rel="noopener" class="badge badge-blue" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="globe" class="lucide"></i> <?= h($user_domain) ?></a>
        <?php if (feature_flag('addon_domain_services')): ?>
        <a href="/cpanel/addon-domains.php" class="btn btn-ghost btn-sm"><i data-lucide="plus" class="lucide"></i> Add Domain</a>
        <?php endif; ?>
        <?php if (feature_flag('subdomain_services')): ?>
        <a href="/cpanel/subdomains.php" class="btn btn-ghost btn-sm"><i data-lucide="git-branch" class="lucide"></i> Subdomains</a>
        <?php endif; ?>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($all_domains) ?></div>
            <div class="stat-label">Total Domains</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">primary + subdomains</div>
        </div>
    </div>
    <?php if (feature_flag('subdomain_services')): ?>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon icon-purple"><i data-lucide="git-branch" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($subdomains) ?></div>
            <div class="stat-label">Subdomains</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">under your domains</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (feature_flag('dns_services')): ?>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="network" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_dns ?></div>
            <div class="stat-label">DNS Records</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">across all zones</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (feature_flag('ssl_services')): ?>
    <div class="stat-card stat-orange fade-in-delay-1">
        <div class="stat-icon icon-orange"><i data-lucide="lock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_ssl ?></div>
            <div class="stat-label">SSL Certificates</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">issued for your domains</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="tip-card tip-blue fade-in-delay-1">
    <i data-lucide="lightbulb" class="lucide"></i>
    <div class="tip-body"><strong>Tip.</strong> Your primary domain serves the base of <code class="chip-mono">~/public_html</code>, while each subdomain gets its own document root. Visitors reach a subdomain at <code class="chip-mono">&lt;name&gt;.<?= h($user_domain) ?></code>.</div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> All Domains (<?= count($all_domains) ?>)</h3></div>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="dmq" placeholder="Search domains..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="dmc"><?= count($all_domains) ?> domain<?= count($all_domains) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body" style="padding:16px">
        <div class="dm-list">
            <div class="dm-row" data-name="<?= h(strtolower($user_domain . ' primary ' . ($homeRoot ? $homeRoot . '/public_html' : ''))) ?>">
                <span class="dm-ic" style="background:<?= dm_avatar_gradient($user_domain, $avatars) ?>"><i data-lucide="globe" class="lucide"></i></span>
                <div class="dm-main">
                    <div class="dm-name">
                        <code><?= h($user_domain) ?></code>
                        <button type="button" class="dm-copy" data-copy="<?= h($user_domain) ?>" title="Copy domain"><i data-lucide="copy" class="lucide"></i></button>
                        <span class="badge badge-blue">Primary</span>
                        <?php
                            if (feature_flag('ssl_services') && isset($sslByDomain[$user_domain])) {
                                $exp = strtotime($sslByDomain[$user_domain]['expires_at']);
                                $ok = $exp && $exp > time();
                                echo '<span class="badge ' . ($ok ? 'badge-active' : 'badge-suspended') . '"><i data-lucide="lock" style="width:10px;height:10px"></i> ' . ($ok ? 'SSL' : 'SSL Expired') . '</span>';
                            }
                        ?>
                    </div>
                    <div class="dm-meta">
                        <code class="chip-mono"><?= h($homeRoot ? $homeRoot . '/public_html' : '/public_html') ?></code>
                        <?php if (feature_flag('dns_services')): ?>
                        <span><i data-lucide="network" class="lucide"></i> <?= (int)($dnsByDomain[$user_domain] ?? 0) ?> records</span>
                        <?php endif; ?>
                        <span><?= date('M d, Y', strtotime($user['created_at'])) ?></span>
                    </div>
                </div>
                <div class="dm-actions">
                    <a href="http://<?= h($user_domain) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary" title="View site"><i data-lucide="external-link" class="lucide"></i> View Site</a>
                    <?php if (feature_flag('dns_services')): ?>
                    <a href="/cpanel/dns.php" class="btn btn-sm btn-ghost" title="DNS zone"><i data-lucide="network" class="lucide"></i> DNS Zone</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (feature_flag('subdomain_services')): ?>
                <?php if (empty($subdomains)): ?>
                    <?php $rel_empty = $homeRoot ? str_replace($homeRoot, '~', $homeRoot . '/public_html') : '/public_html'; ?>
                    <div class="dm-empty">
                        <div class="empty-state-icon" style="margin-bottom:12px"><i data-lucide="git-branch" class="lucide"></i></div>
                        <strong>No subdomains yet</strong>
                        <p>Create one to point a subdomain at a folder inside <code class="chip-mono"><?= h($rel_empty) ?></code>.</p>
                        <div class="empty-state-actions">
                            <a href="/cpanel/subdomains.php" class="btn btn-primary btn-sm"><i data-lucide="git-branch" class="lucide"></i> Manage Subdomains</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($subdomains as $s):
                        $full = $s['subdomain'] . '.' . $s['domain'];
                        $rel_root = str_replace($homeRoot, '~', $s['document_root']);
                    ?>
                    <div class="dm-row is-sub" data-name="<?= h(strtolower($full . ' subdomain')) ?>">
                        <span class="dm-ic" style="background:<?= dm_avatar_gradient($full, $avatars) ?>"><i data-lucide="git-branch" class="lucide"></i></span>
                        <div class="dm-main">
                            <div class="dm-name">
                                <code><?= h($full) ?></code>
                                <button type="button" class="dm-copy" data-copy="<?= h($full) ?>" title="Copy subdomain"><i data-lucide="copy" class="lucide"></i></button>
                                <span class="badge badge-purple">Subdomain</span>
                                <?php
                                    if (feature_flag('ssl_services') && isset($sslByDomain[$full])) {
                                        $exp = strtotime($sslByDomain[$full]['expires_at']);
                                        $ok = $exp && $exp > time();
                                        echo '<span class="badge ' . ($ok ? 'badge-active' : 'badge-suspended') . '"><i data-lucide="lock" style="width:10px;height:10px"></i> ' . ($ok ? 'SSL' : 'SSL Expired') . '</span>';
                                    }
                                ?>
                            </div>
                            <div class="dm-meta">
                                <code class="chip-mono"><?= h($rel_root) ?></code>
                                <?php if (feature_flag('dns_services')): ?>
                                <span><i data-lucide="network" class="lucide"></i> <?= (int)($dnsByDomain[$full] ?? 0) ?> records</span>
                                <?php endif; ?>
                                <span><?= date('M d, Y', strtotime($s['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="dm-actions">
                            <a href="http://<?= h($full) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary" title="View site"><i data-lucide="external-link" class="lucide"></i> View Site</a>
                            <?php if (feature_flag('dns_services')): ?>
                            <a href="/cpanel/dns.php?domain=<?= urlencode($s['domain']) ?>" class="btn btn-sm btn-ghost" title="DNS zone"><i data-lucide="network" class="lucide"></i> DNS Zone</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card fade-in-delay-2" style="margin-top:20px">
    <div class="card-header"><h3><i data-lucide="zap" class="lucide"></i> Domain Tools</h3></div>
    <div class="card-body">
        <div class="dm-links">
            <a href="/cpanel/redirects.php" class="dm-link">
                <span class="dm-link-ic" style="background:var(--primary-light);color:var(--primary)"><i data-lucide="corner-up-right" class="lucide"></i></span>
                <span class="dm-link-main"><strong>Redirects</strong><span>Forward domains to another URL</span></span>
                <i data-lucide="chevron-right" class="chev"></i>
            </a>
            <?php if (feature_flag('dns_services')): ?>
            <a href="/cpanel/dns.php" class="dm-link">
                <span class="dm-link-ic" style="background:rgba(13,148,136,.1);color:#0d9488"><i data-lucide="network" class="lucide"></i></span>
                <span class="dm-link-main"><strong>DNS Zone Manager</strong><span>Edit A, CNAME, MX &amp; TXT records</span></span>
                <i data-lucide="chevron-right" class="chev"></i>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('addon_domain_services')): ?>
            <a href="/cpanel/addon-domains.php" class="dm-link">
                <span class="dm-link-ic" style="background:rgba(217,119,6,.1);color:#d97706"><i data-lucide="folder-plus" class="lucide"></i></span>
                <span class="dm-link-main"><strong>Addon Domains</strong><span>Host additional domains</span></span>
                <i data-lucide="chevron-right" class="chev"></i>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('subdomain_services')): ?>
            <a href="/cpanel/subdomains.php" class="dm-link">
                <span class="dm-link-ic" style="background:rgba(124,58,237,.1);color:#7C3AED"><i data-lucide="git-branch" class="lucide"></i></span>
                <span class="dm-link-main"><strong>Subdomains</strong><span>Create and manage subdomains</span></span>
                <i data-lucide="chevron-right" class="chev"></i>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('ssl_services')): ?>
            <a href="/cpanel/ssl.php" class="dm-link">
                <span class="dm-link-ic" style="background:rgba(5,150,105,.1);color:#059669"><i data-lucide="lock" class="lucide"></i></span>
                <span class="dm-link-main"><strong>SSL / TLS</strong><span>Secure your domains with HTTPS</span></span>
                <i data-lucide="chevron-right" class="chev"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.dm-list{display:flex;flex-direction:column;gap:10px}
.dm-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.dm-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.dm-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 4px 10px rgba(13,148,136,.15)}
.dm-ic .lucide{width:19px;height:19px}
.dm-main{min-width:0;flex:1}
.dm-name{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.dm-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dm-name .badge{font-size:10px;padding:3px 8px;display:inline-flex;align-items:center;gap:4px}
.dm-copy{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.dm-copy .lucide{width:12px;height:12px}
.dm-copy:hover{background:rgba(0,115,230,.1);color:var(--primary)}
.dm-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.dm-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.dm-meta .lucide{width:12px;height:12px;vertical-align:-2px}
.dm-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
.dm-empty{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;padding:20px 14px;border:1.5px dashed var(--border);border-radius:var(--radius);color:var(--text3);margin-top:10px}
.dm-empty strong{color:var(--text);font-size:14px}
.dm-empty p{font-size:12.5px;margin:0 0 4px}
.dm-links{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,230px),1fr));gap:10px}
.dm-link{display:flex;align-items:center;gap:12px;padding:13px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg2);text-decoration:none;font-size:13px;color:var(--text2);transition:border-color .15s,box-shadow .15s,transform .15s}
.dm-link:hover{border-color:var(--text4);box-shadow:var(--shadow-sm);transform:translateY(-2px);text-decoration:none}
.dm-link-ic{width:36px;height:36px;min-width:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dm-link-ic .lucide{width:16px;height:16px}
.dm-link-main{flex:1;min-width:0;display:flex;flex-direction:column}
.dm-link-main strong{font-size:13px;font-weight:700;color:var(--text)}
.dm-link-main span{font-size:11px;color:var(--text4)}
.dm-link .chev{width:14px;height:14px;color:var(--text4);flex-shrink:0;transition:transform .15s}
.dm-link:hover .chev{transform:translateX(2px);color:var(--primary)}
@media(max-width:640px){
  .dm-row{flex-wrap:wrap}
  .dm-main{flex-basis:100%}
  .dm-actions{width:100%}
  .dm-actions a{flex:1;justify-content:center}
}
</style>

<script>
(function () {
    var q = document.getElementById('dmq');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.dm-row'));
        var c = document.getElementById('dmc');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var show = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = show ? '' : 'none';
                if (show) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' domain' + (rows.length === 1 ? '' : 's');
        });
    }
    document.querySelectorAll('.dm-copy').forEach(function (btn) {
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