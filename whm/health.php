<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_health');
init_db();
$db = db();

$total_accounts = $db->query("SELECT COUNT(*) FROM users WHERE role = 'cpanel'")->fetchColumn();
$active_accounts = $db->query("SELECT COUNT(*) FROM users WHERE role = 'cpanel' AND status='active'")->fetchColumn();
$suspended_accounts = $db->query("SELECT COUNT(*) FROM users WHERE role = 'cpanel' AND status='suspended'")->fetchColumn();
$total_packages = $db->query("SELECT COUNT(*) FROM packages")->fetchColumn();
$total_bandwidth = 0;
try {
    $total_bandwidth = $db->query("SELECT COALESCE(SUM(bytes_used),0) FROM bandwidth_usage")->fetchColumn();
} catch (PDOException $e) {
    $total_bandwidth = 0;
}

$nav = 'health';
$page_title = 'Server Health';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon green"><i data-lucide="activity" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Server Health</div>
        <div class="hero-desc">Real-time system resource monitoring. Gauges refresh every few seconds from the live health feed.</div>
    </div>
    <div class="hero-actions">
        <span class="live-badge"><span class="live-dot"></span>LIVE</span>
        <span id="refreshTimer" style="font-size:11px;color:var(--text4)">Updated 0s ago</span>
    </div>
</div>

<!-- Quick Stats -->
<div class="stats-grid" style="margin-bottom:24px">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" id="qAccounts"><?= $total_accounts ?></div>
            <div class="stat-label">Total Accounts</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="check-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" id="qActive"><?= $active_accounts ?></div>
            <div class="stat-label">Active</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-3">
        <div class="stat-icon icon-purple"><i data-lucide="gauge" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" id="qProcesses">--</div>
            <div class="stat-label">Processes</div>
        </div>
    </div>
</div>

<!-- Real-Time Gauges -->
<div class="health-grid" id="healthGrid">
    <!-- Disk -->
    <div class="health-card fade-in">
        <div class="card-top">
            <span class="card-title">Disk Storage</span>
            <div class="card-icon blue"><i data-lucide="hard-drive" class="lucide"></i></div>
        </div>
        <div class="gauge-wrap">
            <svg class="gauge-svg" viewBox="0 0 120 120">
                <circle class="gauge-bg" cx="60" cy="60" r="50"/>
                <circle class="gauge-fill" id="diskGauge" cx="60" cy="60" r="50"
                    stroke="#3b82f6" stroke-dasharray="314.16" stroke-dashoffset="314.16"/>
            </svg>
            <div class="gauge-center">
                <div class="gauge-percent" id="diskPercent">--%</div>
                <div class="gauge-label">Disk</div>
            </div>
        </div>
        <div id="diskDetails">
            <div class="health-detail"><span>Used</span><span id="diskUsed">--</span></div>
            <div class="health-detail"><span>Free</span><span id="diskFree">--</span></div>
            <div class="health-detail"><span>Total</span><span id="diskTotal">--</span></div>
        </div>
    </div>

    <!-- Memory -->
    <div class="health-card fade-in-delay-1">
        <div class="card-top">
            <span class="card-title">Memory (RAM)</span>
            <div class="card-icon green"><i data-lucide="cpu" class="lucide"></i></div>
        </div>
        <div class="gauge-wrap">
            <svg class="gauge-svg" viewBox="0 0 120 120">
                <circle class="gauge-bg" cx="60" cy="60" r="50"/>
                <circle class="gauge-fill" id="memGauge" cx="60" cy="60" r="50"
                    stroke="#22c55e" stroke-dasharray="314.16" stroke-dashoffset="314.16"/>
            </svg>
            <div class="gauge-center">
                <div class="gauge-percent" id="memPercent">--%</div>
                <div class="gauge-label">RAM</div>
            </div>
        </div>
        <div id="memDetails">
            <div class="health-detail"><span>Used</span><span id="memUsed">--</span></div>
            <div class="health-detail"><span>Available</span><span id="memFree">--</span></div>
            <div class="health-detail"><span>Cached</span><span id="memCached">--</span></div>
            <div class="health-detail"><span>Buffers</span><span id="memBuffers">--</span></div>
            <div class="health-detail"><span>Total</span><span id="memTotal">--</span></div>
        </div>
    </div>

    <!-- CPU -->
    <div class="health-card fade-in-delay-2">
        <div class="card-top">
            <span class="card-title">CPU Load</span>
            <div class="card-icon amber"><i data-lucide="zap" class="lucide"></i></div>
        </div>
        <div class="gauge-wrap">
            <svg class="gauge-svg" viewBox="0 0 120 120">
                <circle class="gauge-bg" cx="60" cy="60" r="50"/>
                <circle class="gauge-fill" id="cpuGauge" cx="60" cy="60" r="50"
                    stroke="#f59e0b" stroke-dasharray="314.16" stroke-dashoffset="314.16"/>
            </svg>
            <div class="gauge-center">
                <div class="gauge-percent" id="cpuPercent">--%</div>
                <div class="gauge-label">CPU</div>
            </div>
        </div>
        <div id="cpuDetails">
            <div class="health-detail"><span>Model</span><span id="cpuModel" style="font-size:11px;text-align:right;max-width:140px;word-break:break-word">--</span></div>
            <div class="health-detail"><span>Cores</span><span id="cpuCores">--</span></div>
            <div class="health-detail"><span>Load 1m</span><span id="cpu1">--</span></div>
            <div class="health-detail"><span>Load 5m</span><span id="cpu5">--</span></div>
            <div class="health-detail"><span>Load 15m</span><span id="cpu15">--</span></div>
            <div class="health-detail"><span>Running / Total</span><span id="cpuTasks">--</span></div>
        </div>
    </div>

    <!-- Swap -->
    <div class="health-card fade-in-delay-3">
        <div class="card-top">
            <span class="card-title">Swap</span>
            <div class="card-icon purple"><i data-lucide="layers" class="lucide"></i></div>
        </div>
        <div class="gauge-wrap">
            <svg class="gauge-svg" viewBox="0 0 120 120">
                <circle class="gauge-bg" cx="60" cy="60" r="50"/>
                <circle class="gauge-fill" id="swapGauge" cx="60" cy="60" r="50"
                    stroke="#a855f7" stroke-dasharray="314.16" stroke-dashoffset="314.16"/>
            </svg>
            <div class="gauge-center">
                <div class="gauge-percent" id="swapPercent">--%</div>
                <div class="gauge-label">Swap</div>
            </div>
        </div>
        <div id="swapDetails">
            <div class="health-detail"><span>Used</span><span id="swapUsed">--</span></div>
            <div class="health-detail"><span>Total</span><span id="swapTotal">--</span></div>
        </div>
    </div>
</div>

<!-- System Info -->
<div class="grid-2" style="margin-top:24px">
    <div class="card fade-in">
        <div class="card-header">
            <h3><i data-lucide="info" class="lucide"></i> System Information</h3>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table">
                <tr>
                    <td style="color:var(--text3);width:40%">Server IP</td>
                    <td style="font-weight:600;font-family:monospace;color:var(--text)"><?= h($_SERVER['SERVER_ADDR'] ?? '127.0.0.1') ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Operating System</td>
                    <td style="font-weight:600;font-size:12px;line-height:1.4;color:var(--text);word-break:break-all"><?= h(php_uname()) ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Architecture</td>
                    <td><span class="badge badge-blue"><?= h(php_uname('m')) ?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">PHP Version</td>
                    <td style="font-weight:600;color:var(--text)"><?= h(phpversion()) ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Server Software</td>
                    <td style="font-weight:600;font-size:12px;color:var(--text)"><?= h($_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name()) ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Uptime</td>
                    <td style="font-weight:600;color:var(--text)" id="sysUptime">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">I/O Read</td>
                    <td style="font-weight:600;font-family:monospace;color:var(--text)" id="ioRead">--</td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">I/O Write</td>
                    <td style="font-weight:600;font-family:monospace;color:var(--text)" id="ioWrite">--</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Account Summary -->
    <div class="card fade-in-delay-1">
        <div class="card-header">
            <h3><i data-lucide="pie-chart" class="lucide"></i> Account Summary</h3>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table">
                <tr>
                    <td style="color:var(--text3)">Total Accounts</td>
                    <td style="text-align:right;font-weight:700;font-family:monospace;color:var(--text)"><?= $total_accounts ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Active Accounts</td>
                    <td style="text-align:right;font-weight:700;font-family:monospace;color:#22c55e"><?= $active_accounts ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Suspended</td>
                    <td style="text-align:right;font-weight:700;font-family:monospace;color:#ef4444"><?= $suspended_accounts ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Packages</td>
                    <td style="text-align:right;font-weight:700;font-family:monospace;color:var(--text)"><?= $total_packages ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text3)">Total Bandwidth</td>
                    <td style="text-align:right;font-weight:700;font-family:monospace;color:var(--text)"><?= format_size($total_bandwidth) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<script>
(function(){
    const CIRC = 314.16;
    let lastUpdate = Date.now();
    let refreshInterval = 5;

    function getGaugeColor(percent) {
        if (percent >= 90) return '#ef4444';
        if (percent >= 70) return '#f59e0b';
        return null;
    }

    function setGauge(id, percent, defaultColor) {
        const el = document.getElementById(id);
        if (!el) return;
        const offset = CIRC - (CIRC * Math.min(percent, 100) / 100);
        el.style.strokeDashoffset = offset;
        const color = getGaugeColor(percent) || defaultColor;
        el.style.stroke = color;
    }

    function updateHealth() {
        fetch('/api/server_health.php')
            .then(r => r.json())
            .then(d => {
                // Disk
                document.getElementById('diskPercent').textContent = d.disk.percent + '%';
                document.getElementById('diskUsed').textContent = d.disk.used_human;
                document.getElementById('diskFree').textContent = d.disk.free_human;
                document.getElementById('diskTotal').textContent = d.disk.total_human;
                setGauge('diskGauge', d.disk.percent, '#3b82f6');

                // Memory
                document.getElementById('memPercent').textContent = d.memory.percent + '%';
                document.getElementById('memUsed').textContent = d.memory.used_human;
                document.getElementById('memFree').textContent = d.memory.free_human;
                document.getElementById('memCached').textContent = d.memory.cached_human;
                document.getElementById('memBuffers').textContent = d.memory.buffers_human;
                document.getElementById('memTotal').textContent = d.memory.total_human;
                setGauge('memGauge', d.memory.percent, '#22c55e');

                // CPU
                document.getElementById('cpuPercent').textContent = d.cpu.percent + '%';
                document.getElementById('cpuModel').textContent = d.cpu.model;
                document.getElementById('cpu1').textContent = d.cpu.load[0];
                document.getElementById('cpu5').textContent = d.cpu.load[1];
                document.getElementById('cpu15').textContent = d.cpu.load[2];
                document.getElementById('cpuCores').textContent = d.cpu.count;
                document.getElementById('cpuTasks').textContent = d.tasks_running + ' / ' + d.tasks_total;
                setGauge('cpuGauge', d.cpu.percent, '#f59e0b');

                // Swap
                document.getElementById('swapPercent').textContent = d.swap.percent + '%';
                document.getElementById('swapUsed').textContent = d.swap.used_human;
                document.getElementById('swapTotal').textContent = d.swap.total_human;
                setGauge('swapGauge', d.swap.percent, '#a855f7');

                // Quick stats
                document.getElementById('qProcesses').textContent = d.processes;

                // System info
                document.getElementById('sysUptime').textContent = d.uptime_human;
                document.getElementById('ioRead').textContent = d.io.read_human;
                document.getElementById('ioWrite').textContent = d.io.write_human;

                lastUpdate = Date.now();
            })
            .catch(e => console.error('Health fetch error:', e));
    }

    function updateTimer() {
        const secs = Math.floor((Date.now() - lastUpdate) / 1000);
        document.getElementById('refreshTimer').textContent = 'Updated ' + secs + 's ago';
    }

    updateHealth();
    setInterval(updateHealth, refreshInterval * 1000);
    setInterval(updateTimer, 1000);
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
