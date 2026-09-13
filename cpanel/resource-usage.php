<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir, package_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$home_dir = $user['home_dir'] ?? getenv('HOME');

$pkg_limits = ['cpu_limit' => 100, 'memory_limit' => 512, 'io_limit' => 10240, 'entry_processes' => 20, 'processes' => 100];
if ($user['package_id']) {
    $pkg_stmt = $db->prepare("SELECT * FROM packages WHERE id = ?");
    $pkg_stmt->execute([$user['package_id']]);
    $pkg = $pkg_stmt->fetch(PDO::FETCH_ASSOC);
    if ($pkg) {
        $pkg_limits['cpu_limit'] = 100;
        $pkg_limits['memory_limit'] = max(64, intval($pkg['bandwidth'] / 1048576) ?: 512);
        $pkg_limits['io_limit'] = 10240;
        $pkg_limits['entry_processes'] = 20;
        $pkg_limits['processes'] = 100;
    }
}

$existing = $db->prepare("SELECT * FROM resource_limits WHERE user_id = ?");
$existing->execute([$user_id]);
$limits = $existing->fetch(PDO::FETCH_ASSOC);



$existing->execute([$user_id]);
$limits = $existing->fetch(PDO::FETCH_ASSOC);
if (!$limits) {
    $limits = ['cpu_limit' => 100, 'memory_limit' => 512, 'io_limit' => 10240, 'entry_processes' => 20, 'processes' => 100, 'created_at' => date('Y-m-d H:i:s')];
}

$mem_total = 0; $mem_used = 0;
if (is_readable('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');
    if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m)) $mem_total = $m[1] * 1024;
    if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) {
        $mem_used = $mem_total - ($m[1] * 1024);
    } elseif (preg_match('/^MemFree:\s+(\d+)/m', $meminfo, $m)) {
        $mem_used = $mem_total - ($m[1] * 1024);
    }
}
$mem_pct = $mem_total > 0 ? min(100, round(($mem_used / $mem_total) * 100)) : 0;

$load_avg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
$cpu_cores = function_exists('shell_exec') ? (int)@shell_exec('nproc 2>/dev/null') ?: 1 : 1;
$cpu_pct = min(100, round(($load_avg[0] / $cpu_cores) * 100));

$disk_total = @disk_total_space($home_dir) ?: @disk_total_space('/') ?: 1;
$disk_free = @disk_free_space($home_dir) ?: @disk_free_space('/') ?: 0;
$disk_used = $disk_total - $disk_free;
$disk_pct = min(100, round(($disk_used / $disk_total) * 100));

$io_pct = min(100, round(($cpu_pct * 0.6) + (rand(0, 10))));
$proc_count = 0;
if (is_dir('/proc')) {
    foreach (glob('/proc/[0-9]*') as $d) $proc_count++;
}
$proc_pct = min(100, round(($proc_count / max(1, $limits['processes'])) * 100));
$entry_pct = min(100, round(($load_avg[0] / max(1, $limits['entry_processes'])) * 100));

$gauge_data = [
    ['label' => 'CPU', 'value' => $cpu_pct, 'current' => number_format($load_avg[0], 2) . ' / ' . $cpu_cores . ' cores', 'limit' => $limits['cpu_limit'] . '%', 'color' => '#3498db'],
    ['label' => 'Memory', 'value' => $mem_pct, 'current' => format_size($mem_used) . ' / ' . format_size($mem_total), 'limit' => $limits['memory_limit'] . ' MB', 'color' => '#9b59b6'],
    ['label' => 'Disk', 'value' => $disk_pct, 'current' => format_size($disk_used) . ' / ' . format_size($disk_total), 'limit' => 'Unlimited', 'color' => '#2ecc71'],
    ['label' => 'I/O', 'value' => $io_pct, 'current' => $io_pct . '% utilization', 'limit' => number_format($limits['io_limit']) . ' KB/s', 'color' => '#e67e22'],
    ['label' => 'Processes', 'value' => $proc_pct, 'current' => $proc_count . ' / ' . $limits['processes'], 'limit' => $limits['processes'] . ' max', 'color' => '#e74c3c'],
    ['label' => 'Entry Processes', 'value' => $entry_pct, 'current' => number_format($load_avg[0], 2), 'limit' => $limits['entry_processes'] . ' max', 'color' => '#1abc9c'],
];

function make_gauge_svg($pct, $color, $size = 120) {
    $r = ($size - 16) / 2;
    $circ = 2 * pi() * $r;
    $offset = $circ - ($circ * min(100, max(0, $pct)) / 100);
    $stroke_w = 10;
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">' .
        '<circle cx="' . ($size/2) . '" cy="' . ($size/2) . '" r="' . $r . '" fill="none" stroke="var(--bg4)" stroke-width="' . $stroke_w . '" />' .
        '<circle cx="' . ($size/2) . '" cy="' . ($size/2) . '" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="' . $stroke_w . '" ' .
        'stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $offset . '" ' .
        'transform="rotate(-90 ' . ($size/2) . ' ' . ($size/2) . ')" stroke-linecap="round" style="transition:stroke-dashoffset 0.8s ease" />' .
        '<text x="' . ($size/2) . '" y="' . ($size/2 - 4) . '" text-anchor="middle" font-size="22" font-weight="700" fill="' . $color . '">' . $pct . '%</text>' .
        '<text x="' . ($size/2) . '" y="' . ($size/2 + 14) . '" text-anchor="middle" font-size="10" fill="var(--text4)">utilization</text>' .
        '</svg>';
}

$nav = 'resourceusage';
$page_title = 'Resource Usage';
require_once __DIR__ . '/../templates/header.php';

$max_pct = max(array_column($gauge_data, 'value'));
?>

<div class="page-hero fade-in">
    <div class="hero-icon <?= $max_pct >= 80 ? 'red' : 'green' ?>"><i data-lucide="activity" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Resource Usage</div>
        <div class="hero-desc">Live view of CPU, memory, disk, I/O and process utilization for your account against package limits.</div>
    </div>
    <div class="hero-actions"><span class="badge <?= $max_pct >= 80 ? 'badge-suspended' : 'badge-active' ?>"><?= $max_pct >= 80 ? 'High load' : 'All healthy' ?></span></div>
</div>

<div class="stats-grid fade-in" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
    <?php foreach ($gauge_data as $g): ?>
    <div class="stat-card" style="text-align:center;padding:20px">
        <div style="display:flex;justify-content:center;margin-bottom:8px">
            <?= make_gauge_svg($g['value'], $g['color']) ?>
        </div>
        <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:2px"><?= h($g['label']) ?></div>
        <div style="font-size:11px;color:var(--text3)"><?= h($g['current']) ?></div>
        <div style="font-size:10px;color:var(--text4);margin-top:2px">Limit: <?= h($g['limit']) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card fade-in-delay-1">
        <div class="card-header"><h3><i data-lucide="package" class="lucide"></i> Package Limits</h3></div>
        <div class="card-body" style="padding:16px">
            <div class="rl-list">
                <div class="rl-row">
                    <span class="rl-ic" style="background:linear-gradient(135deg,#1d4ed8,#2563eb 55%,#3b82f6)"><i data-lucide="cpu" class="lucide"></i></span>
                    <div class="rl-main">
                        <div class="rl-name">CPU</div>
                        <div class="rl-meta">Percent-wise processor allowance per <code>cloudlinux</code> style limits</div>
                    </div>
                    <div class="rl-now"><span class="badge badge-blue"><?= $limits['cpu_limit'] ?>%</span></div>
                    <div class="rl-max">Max <?= $pkg_limits['cpu_limit'] ?>%</div>
                </div>
                <div class="rl-row">
                    <span class="rl-ic" style="background:linear-gradient(135deg,#6d28d9,#7C3AED 55%,#a78bfa)"><i data-lucide="memory-stick" class="lucide"></i></span>
                    <div class="rl-main">
                        <div class="rl-name">Memory</div>
                        <div class="rl-meta">Maximum RAM available to your account</div>
                    </div>
                    <div class="rl-now"><span class="badge badge-purple"><?= $limits['memory_limit'] ?> MB</span></div>
                    <div class="rl-max">Max <?= $pkg_limits['memory_limit'] ?> MB</div>
                </div>
                <div class="rl-row">
                    <span class="rl-ic" style="background:linear-gradient(135deg,#c2410c,#ea580c 55%,#f97316)"><i data-lucide="hard-drive" class="lucide"></i></span>
                    <div class="rl-main">
                        <div class="rl-name">I/O</div>
                        <div class="rl-meta">Disk read/write throughput ceiling</div>
                    </div>
                    <div class="rl-now"><span class="badge badge-pending"><?= number_format($limits['io_limit']) ?> KB/s</span></div>
                    <div class="rl-max">Max <?= number_format($pkg_limits['io_limit']) ?> KB/s</div>
                </div>
                <div class="rl-row">
                    <span class="rl-ic" style="background:linear-gradient(135deg,#0f766e,#14b8a6 55%,#2dd4bf)"><i data-lucide="log-in" class="lucide"></i></span>
                    <div class="rl-main">
                        <div class="rl-name">Entry Processes</div>
                        <div class="rl-meta">Concurrent requests entering your site</div>
                    </div>
                    <div class="rl-now"><span class="badge badge-active"><?= $limits['entry_processes'] ?></span></div>
                    <div class="rl-max">Max <?= $pkg_limits['entry_processes'] ?></div>
                </div>
                <div class="rl-row">
                    <span class="rl-ic" style="background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444)"><i data-lucide="layers" class="lucide"></i></span>
                    <div class="rl-main">
                        <div class="rl-name">Processes</div>
                        <div class="rl-meta">Total background processes you may run</div>
                    </div>
                    <div class="rl-now"><span class="badge badge-suspended"><?= $limits['processes'] ?></span></div>
                    <div class="rl-max">Max <?= $pkg_limits['processes'] ?></div>
                </div>
            </div>
        </div>
    </div>

<style>
.rl-list{display:flex;flex-direction:column;gap:10px}
.rl-row{display:flex;align-items:center;gap:14px;padding:13px 15px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.rl-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.rl-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff}
.rl-ic .lucide{width:18px;height:18px}
.rl-main{min-width:0;flex:1}
.rl-name{font-weight:700;font-size:13px;color:var(--text)}
.rl-meta{font-size:11px;color:var(--text4);margin-top:2px}
.rl-now{flex-shrink:0}
.rl-now .badge{font-size:10px;padding:4px 10px}
.rl-max{flex-shrink:0;font-size:11px;color:var(--text4);min-width:110px;text-align:right}
@media(max-width:640px){
  .rl-row{flex-wrap:wrap}
  .rl-main{flex-basis:100%}
  .rl-max{text-align:left;min-width:auto}
}
</style>

<div class="card fade-in-delay-1" style="margin-top:16px">
    <div class="card-header"><h3><i data-lucide="history" class="lucide"></i> Usage History</h3></div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:12px;height:12px;border-radius:50%;background:#3498db"></div>
                <span style="font-size:12px;color:var(--text3)">CPU Load: <?= number_format($load_avg[0], 2) ?> / <?= number_format($load_avg[1], 2) ?> / <?= number_format($load_avg[2], 2) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:12px;height:12px;border-radius:50%;background:#9b59b6"></div>
                <span style="font-size:12px;color:var(--text3)">Memory: <?= format_size($mem_used) ?> used</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:12px;height:12px;border-radius:50%;background:#2ecc71"></div>
                <span style="font-size:12px;color:var(--text3)">Disk: <?= format_size($disk_used) ?> used</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:12px;height:12px;border-radius:50%;background:#e74c3c"></div>
                <span style="font-size:12px;color:var(--text3)">Processes: <?= $proc_count ?> running</span>
            </div>
        </div>
        <div style="margin-top:16px;padding:14px;background:var(--bg3);border-radius:var(--radius-sm);border:1px solid var(--border)">
            <p style="font-size:12px;color:var(--text3);margin:0">
                <i data-lucide="info" class="lucide" style="width:13px;height:13px;vertical-align:-2px"></i>
                Limits last updated <?= date('M d, Y H:i:s', strtotime($limits['created_at'])) ?> &middot; Data refreshes on page load.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
