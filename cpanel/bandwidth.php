<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('bandwidth_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, domain, package_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_domain = $user['domain'] ?: SITE_DOMAIN;

$domains_stmt = $db->prepare("SELECT DISTINCT domain COLLATE utf8mb4_general_ci FROM addon_domains WHERE user_id = ? AND status = 'active' UNION SELECT DISTINCT CONCAT(subdomain, '.', domain) COLLATE utf8mb4_general_ci AS domain FROM subdomains WHERE user_id = ? UNION SELECT domain COLLATE utf8mb4_general_ci FROM users WHERE id = ?");
$domains_stmt->execute([$user_id, $user_id, $user_id]);
$user_domains = $domains_stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($user_domains)) $user_domains = [$user_domain];

$bandwidth_limit = 0;
if (!empty($user['package_id'])) {
    $pkg = $db->prepare("SELECT bandwidth FROM packages WHERE id = ?");
    $pkg->execute([(int)$user['package_id']]);
    $pkg = $pkg->fetch(PDO::FETCH_ASSOC);
    if ($pkg) $bandwidth_limit = (int)$pkg['bandwidth'];
}

$current_month = date('Y-m');
$prev_month = date('Y-m', strtotime('-1 month'));
$prev2_month = date('Y-m', strtotime('-2 months'));

$stmt = $db->prepare("CREATE TABLE IF NOT EXISTS bandwidth_usage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    month VARCHAR(7) NOT NULL,
    bytes_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_domain_month (domain, month, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stmt->execute();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset') {
        $domain = $_POST['domain'] ?? '';
        if (in_array($domain, $user_domains)) {
            $db->prepare("DELETE FROM bandwidth_usage WHERE user_id = ? AND domain = ? AND month = ?")
               ->execute([$user_id, $domain, $current_month]);
            flash('success', "Bandwidth counter reset for {$domain} ({$current_month})");
        }
        redirect('/cpanel/bandwidth.php');
    }

    if ($action === 'simulate') {
        foreach ($user_domains as $d) {
            $existing = $db->prepare("SELECT id FROM bandwidth_usage WHERE user_id = ? AND domain = ? AND month = ?");
            $existing->execute([$user_id, $d, $current_month]);
            if (!$existing->fetch()) {
                $bytes = rand(10485760, 5368709120);
                $db->prepare("INSERT INTO bandwidth_usage (user_id, domain, month, bytes_used) VALUES (?, ?, ?, ?)")
                   ->execute([$user_id, $d, $current_month, $bytes]);
            }
            for ($m = 1; $m <= 11; $m++) {
                $past_month = date('Y-m', strtotime("-{$m} months"));
                $exists2 = $db->prepare("SELECT id FROM bandwidth_usage WHERE user_id = ? AND domain = ? AND month = ?");
                $exists2->execute([$user_id, $d, $past_month]);
                if (!$exists2->fetch()) {
                    $bytes = rand(10485760, 5368709120);
                    $db->prepare("INSERT INTO bandwidth_usage (user_id, domain, month, bytes_used) VALUES (?, ?, ?, ?)")
                       ->execute([$user_id, $d, $past_month, $bytes]);
                }
            }
        }
        flash('success', 'Bandwidth data populated for all domains');
        redirect('/cpanel/bandwidth.php');
    }
}

$bandwidth_data = [];
$total_current = 0;
foreach ($user_domains as $d) {
    $row = [
        'domain' => $d,
        'current' => 0,
        'prev' => 0,
        'prev2' => 0,
        'monthly' => [],
    ];

    $stmt = $db->prepare("SELECT month, bytes_used FROM bandwidth_usage WHERE user_id = ? AND domain = ? ORDER BY month DESC LIMIT 12");
    $stmt->execute([$user_id, $d]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($history as $h) {
        $row['monthly'][$h['month']] = (int)$h['bytes_used'];
    }

    $row['current'] = $row['monthly'][$current_month] ?? 0;
    $row['prev'] = $row['monthly'][$prev_month] ?? 0;
    $row['prev2'] = $row['monthly'][$prev2_month] ?? 0;
    $total_current += $row['current'];
    $bandwidth_data[] = $row;
}

$selected_domain = $_GET['domain'] ?? ($user_domains[0] ?? '');
$selected_history = [];
foreach ($bandwidth_data as $bd) {
    if ($bd['domain'] === $selected_domain) {
        $selected_history = $bd['monthly'];
        break;
    }
}
krsort($selected_history);

$usage_pct = $bandwidth_limit > 0 ? min(100, round(($total_current / $bandwidth_limit) * 100, 1)) : 0;

$nav = 'bandwidth';
$page_title = 'Bandwidth';
require_once __DIR__ . '/../templates/header.php';
?>

<?php
$remaining_bytes = $bandwidth_limit > 0 ? max(0, $bandwidth_limit - $total_current) : 0;
$remaining_pct = $bandwidth_limit > 0 ? max(0, 100 - $usage_pct) : 100;
$bw_fill = function($p) {
    if ($p > 90) return 'linear-gradient(90deg,#dc2626,#ef4444)';
    if ($p > 70) return 'linear-gradient(90deg,#d97706,#f59e0b)';
    return 'linear-gradient(90deg,#0073e6,#3397f0)';
};
?>

<div class="page-hero fade-in">
    <div class="hero-icon <?= $usage_pct > 80 ? 'red' : 'blue' ?>"><i data-lucide="gauge" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Bandwidth</div>
        <div class="hero-desc">Monitor traffic across your domains month by month and spot overages before they become a problem.</div>
    </div>
    <div class="hero-actions"><span class="badge <?= $usage_pct > 80 ? 'badge-suspended' : 'badge-active' ?>"><?= format_size($total_current) ?> this month</span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="activity" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= format_size($total_current) ?></div>
            <div class="stat-label">Used This Month</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $usage_pct ?>% of your limit</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="gauge" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $bandwidth_limit > 0 ? format_size($bandwidth_limit) : 'Unlimited' ?></div>
            <div class="stat-label">Monthly Limit</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">assigned by your package</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-2">
        <div class="stat-icon"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $bandwidth_limit > 0 ? format_size($remaining_bytes) : 'Unlimited' ?></div>
            <div class="stat-label">Remaining</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $remaining_pct ?>% still available</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-2">
        <div class="stat-icon"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($user_domains) ?></div>
            <div class="stat-label">Active Domains</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= h($user_domain) ?> primary</div>
        </div>
    </div>
</div>

<div class="tip-card tip-blue fade-in-delay-1">
    <i data-lucide="lightbulb" class="lucide"></i>
    <div class="tip-body"><strong>Monthly tracking.</strong> Bandwidth is measured per calendar month and per domain. Use <strong>Reset</strong> to zero a domain&rsquo;s counter, or <strong>Populate Data</strong> to seed 12 months of sample history for testing.</div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="activity" class="lucide"></i> Bandwidth Overview</h3>
        <span class="badge <?= $usage_pct > 80 ? 'badge-suspended' : 'badge-active' ?>"><?= $usage_pct ?>% used</span>
    </div>
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px">
            <span style="font-size:13px;color:var(--text2);font-weight:600"><?= format_size($total_current) ?> used this month</span>
            <span style="font-size:12px;color:var(--text4)"><?= $bandwidth_limit > 0 ? format_size($bandwidth_limit) . ' limit' : 'No limit set' ?></span>
        </div>
        <div class="progress-bar" style="height:12px">
            <div class="progress-fill" style="width:<?= $usage_pct ?>%;background:<?= $bw_fill($usage_pct) ?>"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text4);margin-top:4px">
            <span><?= $usage_pct ?>% used</span>
            <span><?= $bandwidth_limit > 0 ? format_size($remaining_bytes) . ' remaining' : 'Unlimited bandwidth' ?></span>
        </div>
    </div>
</div>

<?php if ($bandwidth_limit > 0 && $usage_pct > 80): ?>
<div class="tip-card tip-red fade-in-delay-1">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong>Heads up.</strong> You have used <?= $usage_pct ?>% of your monthly bandwidth. Consider upgrading your package before the month ends.</div>
</div>
<?php endif; ?>

<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-header">
        <h3><i data-lucide="globe" class="lucide"></i> Domain Usage (<?= count($bandwidth_data) ?>)</h3>
        <form method="POST" style="margin:0" onsubmit="return confirm('Populate bandwidth data for all domains?')">
            <input type="hidden" name="action" value="simulate">
            <button type="submit" class="btn btn-sm btn-info"><i data-lucide="refresh-cw" class="lucide"></i> Populate Data</button>
        </form>
    </div>
    <?php if (!empty($bandwidth_data)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="bwSearch" placeholder="Search domains..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="bwCount"><?= count($bandwidth_data) ?> domain<?= count($bandwidth_data) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($bandwidth_data) ? '14px' : '16px' ?>">
        <?php if (empty($bandwidth_data)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="activity" class="lucide"></i></div>
                <strong>No bandwidth data available</strong>
                <p>Usage appears here once your domains start receiving traffic.</p>
            </div>
        <?php else: ?>
        <div class="bw-list">
            <?php foreach ($bandwidth_data as $bd):
                $bw_pct = $bandwidth_limit > 0 ? min(100, round(($bd['current'] / $bandwidth_limit) * 100, 1)) : min(100, round(($bd['current'] / max(1, $total_current)) * 100, 1));
                $trend_icon = 'minus';
                $trend_color = 'var(--text4)';
                $trend_label = 'flat';
                if ($bd['current'] > $bd['prev']) { $trend_icon = 'trending-up'; $trend_color = '#ef4444'; $trend_label = 'up'; }
                elseif ($bd['current'] < $bd['prev']) { $trend_icon = 'trending-down'; $trend_color = '#22c55e'; $trend_label = 'down'; }
            ?>
                <div class="bw-row" data-name="<?= h(strtolower($bd['domain'] . ' ' . format_size($bd['current']) . ' ' . format_size($bd['prev']) . ' ' . format_size($bd['prev2']))) ?>">
                    <span class="bw-ic"><i data-lucide="globe" class="lucide"></i></span>
                    <div class="bw-main">
                        <div class="bw-name"><?= h($bd['domain']) ?></div>
                        <div class="bw-meta">
                            <span class="chip-mono"><?= format_size($bd['current']) ?></span>
                            <span>Last month <?= format_size($bd['prev']) ?></span>
                            <span>2 mo ago <?= format_size($bd['prev2']) ?></span>
                            <span style="color:<?= $trend_color ?>;font-weight:600"><i data-lucide="<?= $trend_icon ?>" class="lucide" style="width:13px;height:13px;vertical-align:-2px"></i> <?= $trend_label ?></span>
                        </div>
                    </div>
                    <div class="bw-chart">
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text4);margin-bottom:4px">
                            <span>Share of limit</span>
                            <span style="font-weight:700;color:var(--text2)"><?= $bw_pct ?>%</span>
                        </div>
                        <div class="progress-bar" style="height:6px"><div class="progress-fill" style="width:<?= $bw_pct ?>%;background:<?= $bw_fill($bw_pct) ?>"></div></div>
                    </div>
                    <div class="bw-actions">
                        <a href="/cpanel/bandwidth.php?domain=<?= urlencode($bd['domain']) ?>" class="btn btn-sm btn-info"><i data-lucide="eye" class="lucide"></i> Details</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Reset bandwidth counter for <?= h($bd['domain']) ?> (this month)?')">
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="domain" value="<?= h($bd['domain']) ?>">
                            <button type="submit" class="btn btn-sm btn-warning"><i data-lucide="refresh-cw" class="lucide"></i> Reset</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($selected_domain && !empty($selected_history)): ?>
<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-header"><h3><i data-lucide="bar-chart-3" class="lucide"></i> Monthly Breakdown: <code class="chip-mono" style="font-size:12px"><?= h($selected_domain) ?></code></h3></div>
    <div class="card-body">
        <?php
            $max_bytes = max(array_values($selected_history) ?: [1]);
        ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($selected_history as $month => $bytes): ?>
                <?php
                    $mpct = max(1, ($bytes / $max_bytes) * 100);
                    $rel_pct = $bandwidth_limit > 0 ? min(100, round(($bytes / $bandwidth_limit) * 100, 1)) : min(100, round(($bytes / max(1, $total_current)) * 100, 1));
                ?>
                <div style="display:flex;align-items:center;gap:12px">
                    <span class="chip-mono" style="min-width:64px;text-align:center"><?= h($month) ?></span>
                    <div style="flex:1;height:20px;background:var(--bg4);border-radius:var(--radius-xs);overflow:hidden;position:relative">
                        <div style="width:<?= $mpct ?>%;height:100%;background:<?= $bw_fill($rel_pct) ?>;border-radius:var(--radius-xs)"></div>
                        <?php if ($mpct > 22): ?>
                            <span style="position:absolute;inset:0;display:flex;align-items:center;padding-left:10px;font-size:11px;color:#fff;font-weight:700;text-shadow:0 1px 2px rgba(0,0,0,.2)"><?= format_size($bytes) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($mpct <= 22): ?>
                        <span style="font-size:11px;color:var(--text4);font-weight:700;white-space:nowrap"><?= format_size($bytes) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:11px;color:var(--text4);margin:16px 0 0"><i data-lucide="info" class="lucide" style="width:12px;height:12px;vertical-align:-2px"></i> Bars are scaled against your busiest month in the last 12; color reflects usage versus your monthly limit.</p>
    </div>
</div>
<?php endif; ?>

<style>
.bw-list{display:flex;flex-direction:column;gap:10px}
.bw-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.bw-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.bw-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#b45309,#d97706 55%,#f59e0b);color:#fff;box-shadow:0 4px 10px rgba(217,119,6,.18)}
.bw-ic .lucide{width:19px;height:19px}
.bw-main{min-width:0;flex:1}
.bw-name{font-weight:700;color:var(--text);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bw-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.bw-meta .chip-mono{font-size:11px}
.bw-chart{width:210px;flex-shrink:0;min-width:170px}
.bw-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
@media(max-width:720px){
  .bw-row{gap:12px;flex-wrap:wrap}
  .bw-main{flex-basis:100%}
  .bw-chart{width:100%}
  .bw-actions{width:100%;justify-content:flex-end}
}
</style>

<script>
(function () {
    var q = document.getElementById('bwSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.bw-row'));
        var c = document.getElementById('bwCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' domain' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>