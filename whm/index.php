<?php
require_once __DIR__ . '/../config.php';
require_whm();
init_db();
$stats = get_server_stats();
$db = db();

$total_accounts = $db->query("SELECT COUNT(*) FROM users WHERE role = 'cpanel'")->fetchColumn();
$active_accounts = $db->query("SELECT COUNT(*) FROM users WHERE role = 'cpanel' AND status='active'")->fetchColumn();
$total_packages = $db->query("SELECT COUNT(*) FROM packages")->fetchColumn();
$accounts = $db->query("SELECT u.*, p.name as package_name FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.role = 'cpanel' ORDER BY u.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$nav = 'dashboard';
$page_title = 'WHM Dashboard';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
    .dash-welcome {
        position: relative;
        overflow: hidden;
        border-radius: 20px;
        background: linear-gradient(120deg, #0f172a 0%, #1e3a8a 55%, #7c3aed 100%);
        padding: clamp(22px, 4vw, 34px);
        margin-bottom: 22px;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        box-shadow: 0 16px 40px -12px rgba(30, 58, 138, .5);
    }
    .dash-welcome::before {
        content: '';
        position: absolute;
        width: 280px; height: 280px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.16), transparent 70%);
        top: -110px; right: -70px;
    }
    .dash-welcome::after {
        content: '';
        position: absolute;
        width: 200px; height: 200px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.1), transparent 70%);
        bottom: -90px; left: 25%;
    }
    .dash-welcome-left { position: relative; z-index: 1; }
    .dash-welcome h2 { margin: 0 0 6px; font-size: clamp(20px, 3vw, 26px); font-weight: 700; letter-spacing: -.3px; }
    .dash-welcome .dash-date { font-size: 13px; opacity: .85; margin-bottom: 14px; }
    .dash-welcome-actions { position: relative; z-index: 1; display: flex; gap: 10px; flex-wrap: wrap; }
    .dash-welcome-actions .btn {
        display: inline-flex; align-items: center; gap: 8px;
        border: none; border-radius: 10px; padding: 10px 16px;
        font-size: 13px; font-weight: 600; cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        text-decoration: none;
    }
    .dash-welcome-actions .btn:hover { transform: translateY(-2px); }
    .btn-welcome-primary { background: #fff; color: #1e3a8a; box-shadow: 0 6px 18px rgba(0,0,0,.18); }
    .btn-welcome-primary:hover { background: #eef2ff; }
    .btn-welcome-ghost { background: rgba(255,255,255,.14); color: #fff; border: 1px solid rgba(255,255,255,.28) !important; backdrop-filter: blur(4px); }
    .btn-welcome-ghost:hover { background: rgba(255,255,255,.24); }
    .dash-pill {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.26);
        color: #fff; font-size: 12px; font-weight: 600;
        padding: 5px 12px; border-radius: 999px; backdrop-filter: blur(4px);
    }
    .dash-pill .lucide { width: 13px; height: 13px; }
    .qa-tile {
        display: flex; align-items: center; gap: 12px;
        padding: 16px; border-radius: 14px; border: 1px solid var(--border);
        background: var(--bg2); text-decoration: none;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .qa-tile:hover { transform: translateY(-3px); box-shadow: 0 10px 24px -8px rgba(15,23,42,.16); border-color: rgba(0,115,230,.2); text-decoration: none; }
    .qa-tile .qa-icon {
        width: 42px; height: 42px; min-width: 42px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
    }
    .qa-tile .qa-icon .lucide { width: 20px; height: 20px; }
    .qa-tile .qa-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .qa-tile .qa-title { font-size: 13px; font-weight: 700; color: var(--text); }
    .qa-tile .qa-sub { font-size: 11px; color: var(--text4); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>

<!-- Welcome Banner -->
<div class="dash-welcome fade-in">
    <div class="dash-welcome-left">
        <h2>Welcome back, <?= h($_SESSION['username'] ?? 'Admin') ?> &#128075;</h2>
        <div class="dash-date"><?= date('l, F j, Y') ?> &middot; All systems operational</div>
        <span class="dash-pill"><i data-lucide="server"></i> <?= count($stats['cpu']) ?>-core server</span>
    </div>
    <div class="dash-welcome-actions">
        <?php if (feature_flag('whm_accounts')): ?>
        <a href="/whm/accounts.php" class="btn btn-welcome-primary"><i data-lucide="users"></i> Manage Accounts</a>
        <?php endif; ?>
        <?php if (feature_flag('whm_health')): ?>
        <a href="/whm/health.php" class="btn btn-welcome-ghost"><i data-lucide="activity"></i> Full Health Report</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <?php if (feature_flag('whm_accounts')): ?>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_accounts ?></div>
            <div class="stat-label">Total Accounts</div>
            
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="check-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_accounts ?></div>
            <div class="stat-label">Active Accounts</div>
            
        </div>
    </div>
    <?php endif; ?>
    <?php if (feature_flag('whm_packages')): ?>
    <div class="stat-card stat-purple fade-in-delay-2">
        <div class="stat-icon icon-purple"><i data-lucide="package" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_packages ?></div>
            <div class="stat-label">Packages</div>
            
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <!-- Server Info -->
    <div class="card fade-in-delay-1">
        <div class="card-header">
            <h3><i data-lucide="zap" class="lucide"></i> Server Health</h3>
            <?php if (feature_flag('whm_health')): ?>
            <a href="/whm/health.php" class="btn btn-sm btn-ghost">Full Report</a>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table">
                <tr>
                    <td>PHP Version</td>
                    <td style="text-align:right"><span class="badge badge-blue"><?= h($stats['php_version']) ?></span></td>
                </tr>
                <tr>
                    <td>Uptime</td>
                    <td style="text-align:right;font-weight:600;font-family:monospace" id="dashUptime"><?= h(format_uptime($stats['uptime'] ?? 0)) ?></td>
                </tr>
                <tr>
                    <td>Load Avg</td>
                    <td style="text-align:right;font-weight:600;font-family:monospace" id="dashCpuLabel"><?= implode(' / ', $stats['cpu']) ?></td>
                </tr>
                <tr>
                    <td colspan="2">
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px;margin-top:4px">
                            <span class="text-muted" style="font-size:12px;font-weight:500">CPU Usage</span>
                            <span style="font-size:12px;font-weight:700;color:var(--text)" id="dashCpuPct"><?= number_format(min(100, round(($stats['cpu'][0] / 8) * 100))) ?>%</span>
                        </div>
                        <div class="progress-wrapper">
                            <div class="progress-bar">
                                <div class="progress-fill" id="dashCpuBar" style="width: <?= min(100, round(($stats['cpu'][0] / 8) * 100)) ?>%;background:#f59e0b"></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px;margin-top:4px">
                            <span class="text-muted" style="font-size:12px;font-weight:500">Disk Usage</span>
                            <span style="font-size:12px;font-weight:700;color:var(--text)" id="dashDiskLabel"><?= format_size($stats['disk_used']) ?> / <?= format_size($stats['disk_total']) ?></span>
                        </div>
                        <div class="progress-wrapper">
                            <div class="progress-bar">
                                <div class="progress-fill" id="dashDiskBar" style="width: <?= round(($stats['disk_used'] / $stats['disk_total']) * 100) ?>%"></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php if ($stats['memory']['total'] > 0): ?>
                <tr>
                    <td colspan="2">
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px;margin-top:8px">
                            <span class="text-muted" style="font-size:12px;font-weight:500">Memory Usage</span>
                            <span style="font-size:12px;font-weight:700;color:var(--text)" id="dashMemLabel"><?= format_size($stats['memory']['used']) ?> / <?= format_size($stats['memory']['total']) ?></span>
                        </div>
                        <div class="progress-wrapper">
                            <div class="progress-bar">
                                <div class="progress-fill progress-green" id="dashMemBar" style="width: <?= round(($stats['memory']['used'] / $stats['memory']['total']) * 100) ?>%"></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td colspan="2" style="padding:12px 16px">
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;animation:pulse-dot 1.5s infinite"></span>
                            <span style="font-size:11px;font-weight:600;color:#22c55e">LIVE</span>
                            <span style="font-size:11px;color:var(--text4);margin-left:4px" id="dashTimer">Updated 0s ago</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

<script>
(function(){
    var lastUpdate = Date.now();
    function getCpuColor(pct) {
        if (pct >= 90) return '#ef4444';
        if (pct >= 70) return '#f59e0b';
        return '#3b82f6';
    }
    function updateDash(){
        fetch('/api/server_health.php').then(function(r){return r.json()}).then(function(d){
            var cpuPct = d.cpu.percent;
            document.getElementById('dashCpuLabel').textContent = d.cpu.load.join(' / ');
            var uptimeEl = document.getElementById('dashUptime');
            if (uptimeEl) uptimeEl.textContent = d.uptime_human;
            document.getElementById('dashCpuPct').textContent = cpuPct + '%';
            document.getElementById('dashCpuBar').style.width = cpuPct + '%';
            document.getElementById('dashCpuBar').style.background = getCpuColor(cpuPct);
            document.getElementById('dashDiskLabel').textContent = d.disk.used_human + ' / ' + d.disk.total_human;
            document.getElementById('dashDiskBar').style.width = d.disk.percent + '%';
            if(d.memory.total > 0){
                document.getElementById('dashMemLabel').textContent = d.memory.used_human + ' / ' + d.memory.total_human;
                document.getElementById('dashMemBar').style.width = d.memory.percent + '%';
            }
            lastUpdate = Date.now();
        }).catch(function(){});
    }
    function updateTimer(){
        var s=Math.floor((Date.now()-lastUpdate)/1000);
        var el=document.getElementById('dashTimer');
        if(el) el.textContent='Updated '+s+'s ago';
    }
    updateDash();
    setInterval(updateDash, 2000);
    setInterval(updateTimer, 1000);
})();
</script>

    <?php if (feature_flag('whm_accounts')): ?>
    <!-- Recent Accounts -->
    <div class="card fade-in-delay-2">
        <div class="card-header">
            <h3><i data-lucide="users" class="lucide"></i> Recent Accounts</h3>
            <a href="/whm/accounts.php" class="btn btn-sm btn-ghost">Manage All</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($accounts)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i data-lucide="user" class="lucide"></i></div>
                    <p>No accounts found</p>
                    <a href="/whm/accounts.php">Create your first account &rarr;</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Package</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accounts as $a): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;color:var(--text);font-size:13px"><?= h($a['username']) ?></div>
                                <div style="font-size:11px;color:var(--text4);margin-top:1px"><?= date('M d, Y', strtotime($a['created_at'])) ?></div>
                            </td>
                            <td><span style="font-size:13px;color:var(--text2)"><?= h($a['package_name'] ?? 'Custom') ?></span></td>
                            <td><span class="badge badge-<?= $a['status'] ?>"><?= h($a['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Actions -->
<div class="card fade-in-delay-3" style="margin-top:20px">
    <div class="card-header"><h3><i data-lucide="zap" class="lucide"></i> Quick Actions</h3></div>
    <div class="card-body">
        <div class="quick-actions-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px">
            <?php if (feature_flag('whm_accounts')): ?>
            <a href="/whm/accounts.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(0,115,230,.1);color:#0073e6;border:1px solid rgba(0,115,230,.16)"><i data-lucide="user-plus" class="lucide"></i></div>
                <div class="qa-info"><span class="qa-title">Create Account</span><span class="qa-sub">add a new user</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('whm_packages')): ?>
            <a href="/whm/packages.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(124,58,237,.1);color:#7c3aed;border:1px solid rgba(124,58,237,.16)"><i data-lucide="package" class="lucide"></i></div>
                <div class="qa-info"><span class="qa-title">Packages</span><span class="qa-sub">edit hosting plans</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('whm_domains')): ?>
            <a href="/whm/domains.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(37,99,235,.1);color:#2563eb;border:1px solid rgba(37,99,235,.16)"><i data-lucide="globe" class="lucide"></i></div>
                <div class="qa-info"><span class="qa-title">Domains</span><span class="qa-sub">manage domain zones</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('whm_tunnels')): ?>
            <a href="/whm/tunnels.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(217,119,6,.1);color:#d97706;border:1px solid rgba(217,119,6,.16)"><i data-lucide="cloud" class="lucide"></i></div>
                <div class="qa-info"><span class="qa-title">Tunnels</span><span class="qa-sub">remote access</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('whm_api_keys')): ?>
            <a href="/whm/api-keys.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(13,148,136,.1);color:#0d9488;border:1px solid rgba(13,148,136,.16)"><i data-lucide="key-round" class="lucide"></i></div>
                <div class="qa-info"><span class="qa-title">API Keys</span><span class="qa-sub">manage access tokens</span></div>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
