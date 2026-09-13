<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_server');
init_db();
$stats = get_server_stats();

$nav = 'server';
$page_title = 'Server Information';
require_once __DIR__ . '/../templates/header.php';

$disk_perc = round(($stats['disk_used'] / $stats['disk_total']) * 100);
$mem_perc = ($stats['memory']['total'] > 0) ? round(($stats['memory']['used'] / $stats['memory']['total']) * 100) : 0;
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="server" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Server Information</div>
        <div class="hero-desc">Detailed technical specifications and real-time resource usage for this machine.</div>
    </div>
    <div class="hero-actions"><span class="live-badge"><span class="live-dot"></span>ONLINE</span></div>
</div>

<div class="stats-grid">
    <div class="stat-card fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="code-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-label" style="margin-bottom:2px">PHP Version</div>
            <div class="stat-number" style="font-size:22px"><?= h($stats['php_version']) ?></div>
        </div>
    </div>
    <div class="stat-card fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-label" style="margin-bottom:2px">Server IP</div>
            <div class="stat-number" style="font-size:16px;font-family:monospace"><?= h($_SERVER['SERVER_ADDR'] ?? '127.0.0.1') ?></div>
        </div>
    </div>
    <div class="stat-card fade-in-delay-2">
        <div class="stat-icon icon-purple"><i data-lucide="timer" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-label" style="margin-bottom:2px">Uptime</div>
            <div class="stat-number" style="font-size:20px;font-family:monospace" id="srvUptime"><?= h(format_uptime($stats['uptime'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="stat-card fade-in-delay-3">
        <div class="stat-icon icon-orange"><i data-lucide="zap" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-label" style="margin-bottom:2px">Load Avg</div>
            <div class="stat-number" style="font-size:20px;font-family:monospace" id="srvLoad"><?= $stats['cpu'][0] ?>%</div>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- System Details -->
    <div class="card fade-in-delay-1">
        <div class="card-header">
            <h3><i data-lucide="monitor" class="lucide"></i> System Specifications</h3>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table">
                <tr>
                    <td style="color:var(--text3);width:40%">Hostname</td>
                    <td style="font-weight:600;color:var(--text)"><code style="font-family:'Fira Code',monaco,consolas,monospace;color:var(--primary2)"><?= h(server_hostname()) ?></code></td>
                </tr>
                <tr>
                    <td style="color:var(--text3);width:40%">Server Software</td>
                    <td style="font-weight:600;color:var(--text)"><?= h($stats['server_software']) ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Operating System</td>
                    <td style="font-weight:600;font-size:13px;line-height:1.4;color:var(--text)"><?= h(php_uname()) ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Architecture</td>
                    <td><span class="badge badge-blue"><?= h(php_uname('m')) ?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Current Load</td>
                    <td style="font-family:monospace;font-weight:700;color:var(--primary2)"><?= implode(' / ', $stats['cpu']) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Health Metrics -->
    <div class="card fade-in-delay-2">
        <div class="card-header">
            <h3><i data-lucide="bar-chart-3" class="lucide"></i> Resource Allocation</h3>
        </div>
        <div class="card-body">
            <div style="margin-bottom:28px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <span style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:var(--text)"><i data-lucide="hard-drive" class="lucide"></i> Disk Storage</span>
                    <span style="font-weight:800;font-size:14px;color:var(--text)"><?= $disk_perc ?>%</span>
                </div>
                <div class="progress-bar" style="height:10px">
                    <div class="progress-fill" style="width:<?= $disk_perc ?>%"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:8px">
                    <small style="color:var(--text4);font-size:12px">Used: <?= format_size($stats['disk_used']) ?></small>
                    <small style="color:var(--text4);font-size:12px">Total: <?= format_size($stats['disk_total']) ?></small>
                </div>
            </div>

            <?php if ($stats['memory']['total'] > 0): ?>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <span style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:var(--text)"><i data-lucide="cpu" class="lucide"></i> Physical Memory</span>
                    <span style="font-weight:800;font-size:14px;color:var(--text)"><?= $mem_perc ?>%</span>
                </div>
                <div class="progress-bar" style="height:10px">
                    <div class="progress-fill progress-green" style="width:<?= $mem_perc ?>%"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:8px">
                    <small style="color:var(--text4);font-size:12px">Used: <?= format_size($stats['memory']['used']) ?></small>
                    <small style="color:var(--text4);font-size:12px">Total: <?= format_size($stats['memory']['total']) ?></small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- PHP Extensions -->
<div class="card fade-in-delay-3" style="margin-top:24px">
    <div class="card-header">
        <h3><i data-lucide="code-2" class="lucide"></i> Loaded PHP Extensions (<?= count(get_loaded_extensions()) ?>)</h3>
    </div>
    <div class="card-body">
        <div class="tag-list">
            <?php foreach (get_loaded_extensions() as $ext): ?>
                <span class="tag"><?= h($ext) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
(function(){
    function update(){
        fetch('/api/server_health.php').then(function(r){return r.json()}).then(function(d){
            var u = document.getElementById('srvUptime');
            if (u) u.textContent = d.uptime_human;
            var l = document.getElementById('srvLoad');
            if (l) l.textContent = d.cpu.load[0] + '%';
        }).catch(function(){});
    }
    update();
    setInterval(update, 2000);
})();
</script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
