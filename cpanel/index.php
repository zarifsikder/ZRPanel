<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();

$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT u.*, p.name as package_name, p.disk_quota, p.bandwidth, p.max_domains FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user['status'] !== 'active') {
    flash('error', 'Your account has been suspended');
    redirect('/logout.php');
}

$home = $user['home_dir'] ?? '/sdcard/Download/Hosting/public_html';
$dirStats = cached_dir_stats($home);
$total_files = $dirStats['total_files'];
$total_size = $dirStats['total_size'];

$subdomains = $db->prepare("SELECT * FROM subdomains WHERE user_id = ?");
$subdomains->execute([$user_id]);
$subdomains = $subdomains->fetchAll(PDO::FETCH_ASSOC);

$ftp_count = $db->prepare("SELECT COUNT(*) FROM ftp_accounts WHERE user_id = ?");
$ftp_count->execute([$user_id]);
$total_ftp = $ftp_count->fetchColumn();

$cron_count = $db->prepare("SELECT COUNT(*) FROM cron_jobs WHERE user_id = ?");
$cron_count->execute([$user_id]);
$total_cron = $cron_count->fetchColumn();

$ssl_count = $db->prepare("SELECT COUNT(*) FROM ssl_certs WHERE user_id = ?");
$ssl_count->execute([$user_id]);
$total_ssl = $ssl_count->fetchColumn();

$nav = 'dashboard';
$page_title = 'My Dashboard';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
    .dash-welcome {
        position: relative;
        overflow: hidden;
        border-radius: 20px;
        background: linear-gradient(120deg, #1d4ed8 0%, #7c3aed 100%);
        padding: clamp(22px, 4vw, 34px);
        margin-bottom: 22px;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        box-shadow: 0 16px 40px -12px rgba(76, 29, 149, .45);
    }
    .dash-welcome::before {
        content: '';
        position: absolute;
        width: 260px; height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.18), transparent 70%);
        top: -90px; right: -60px;
    }
    .dash-welcome::after {
        content: '';
        position: absolute;
        width: 180px; height: 180px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.12), transparent 70%);
        bottom: -80px; left: 30%;
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
    .btn-welcome-primary { background: #fff; color: #1d4ed8; box-shadow: 0 6px 18px rgba(0,0,0,.18); }
    .btn-welcome-primary:hover { background: #f1f5ff; }
    .btn-welcome-ghost { background: rgba(255,255,255,.16); color: #fff; border: 1px solid rgba(255,255,255,.3) !important; backdrop-filter: blur(4px); }
    .btn-welcome-ghost:hover { background: rgba(255,255,255,.26); }
    .dash-pill {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28);
        color: #fff; font-size: 12px; font-weight: 600;
        padding: 5px 12px; border-radius: 999px; backdrop-filter: blur(4px);
    }
    .dash-pill .lucide { width: 13px; height: 13px; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 22px; }

    .stat-card {
        position: relative;
        display: flex; align-items: center; gap: 16px;
        padding: 20px;
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; opacity: .9; }
    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px -8px rgba(15,23,42,.15); }
    .stat-card.stat-blue { background: linear-gradient(135deg, rgba(0,115,230,.12), rgba(0,115,230,.02) 70%); border-color: rgba(0,115,230,.14); }
    .stat-card.stat-blue::before { background: linear-gradient(90deg,#0073e6,#38bdf8); }
    .stat-card.stat-green { background: linear-gradient(135deg, rgba(22,163,74,.12), rgba(22,163,74,.02) 70%); border-color: rgba(22,163,74,.14); }
    .stat-card.stat-green::before { background: linear-gradient(90deg,#16a34a,#4ade80); }
    .stat-card.stat-purple { background: linear-gradient(135deg, rgba(124,58,237,.12), rgba(124,58,237,.02) 70%); border-color: rgba(124,58,237,.14); }
    .stat-card.stat-purple::before { background: linear-gradient(90deg,#7c3aed,#c084fc); }
    .stat-card.stat-orange { background: linear-gradient(135deg, rgba(217,119,6,.12), rgba(217,119,6,.02) 70%); border-color: rgba(217,119,6,.14); }
    .stat-card.stat-orange::before { background: linear-gradient(90deg,#d97706,#fbbf24); }
    .stat-card .stat-icon {
        width: 52px; height: 52px; min-width: 52px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 6px 14px -4px rgba(15,23,42,.2);
    }
    .stat-card .stat-icon .lucide { width: 24px; height: 24px; }
    .stat-card .stat-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .stat-card .stat-number { font-size: 24px; font-weight: 800; letter-spacing: -.5px; color: var(--text); line-height: 1; }
    .stat-card .stat-label { font-size: 12px; color: var(--text3); font-weight: 500; }
    .stat-sub { font-size: 11px; color: var(--text4); font-weight: 500; }
    .stat-mini-bar { height: 5px; border-radius: 99px; background: rgba(15,23,42,.08); overflow: hidden; margin-top: 6px; max-width: 140px; }
    .stat-mini-bar > div { height: 100%; border-radius: 99px; transition: width .6s ease; }

    .card { border-radius: 16px; }
    .card-header { padding: 16px 20px; }
    .card-header h3 { font-size: 14px; }

    .disk-layout { display: flex; gap: 30px; align-items: center; flex-wrap: wrap; }
    .disk-donut-wrap { position: relative; width: 170px; height: 170px; flex-shrink: 0; margin: 0 auto; }
    .disk-donut-wrap svg { width: 100%; height: 100%; }
    .dd-track { fill: none; stroke: var(--bg4); stroke-width: 13; }
    .dd-bar { fill: none; stroke: url(#diskGrad); stroke-width: 13; stroke-linecap: round; transform: rotate(-90deg); transform-origin: 50% 50%; transition: stroke-dashoffset .7s cubic-bezier(.16,1,.3,1); }
    .disk-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px; }
    .disk-donut-center .dd-pct { font-size: 32px; font-weight: 800; color: var(--text); letter-spacing: -1px; line-height: 1; }
    .disk-donut-center .dd-lbl { font-size: 11px; color: var(--text3); font-weight: 600; text-transform: uppercase; letter-spacing: .6px; }

    .disk-right { flex: 1; min-width: 300px; }
    .disk-progress-info { margin-bottom: 14px; }
    .dpi-line { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--text); }
    .dpi-line span:last-child { color: var(--text3); font-weight: 500; }
    .disk-progress-track { height: 10px; background: var(--bg4); border-radius: 99px; overflow: hidden; border: 1px solid var(--border); }
    .dpi-sub { font-size: 11px; color: var(--text3); margin-top: 5px; }
    .disk-breakdown-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
    .bd-tile {
        display: flex; flex-direction: column; align-items: center; text-align: center; gap: 4px;
        padding: 14px 8px;
        background: var(--bg3); border-radius: 12px; border: 1px solid var(--border);
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .bd-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 16px -6px rgba(15,23,42,.18); }
    .bd-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 2px; }
    .bd-icon .lucide { width: 18px; height: 18px; }
    .bd-tile .bd-val { font-size: 14px; font-weight: 700; color: var(--text); }
    .bd-tile .bd-lbl { font-size: 10px; color: var(--text3); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }

    .quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
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

    .info-table td:first-child { font-weight: 600; color: var(--text3); white-space: nowrap; width: 42%; }

    @media (max-width: 768px) {
        .disk-breakdown-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 480px) {
        .disk-breakdown-grid { grid-template-columns: repeat(2, 1fr); }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .stat-card { padding: 14px; gap: 12px; }
        .stat-card .stat-icon { width: 44px; height: 44px; min-width: 44px; }
        .stat-card .stat-number { font-size: 19px; }
        .dash-welcome-actions { width: 100%; }
    }
</style>

<!-- Welcome Banner -->
<div class="dash-welcome fade-in">
    <div class="dash-welcome-left">
        <h2>Welcome back, <?= h($user['username']) ?> &#128075;</h2>
        <div class="dash-date"><?= date('l, F j, Y') ?> &middot; Everything looks healthy</div>
        <span class="dash-pill"><i data-lucide="package"></i> <?= h($user['package_name'] ?? 'Paid') ?> Plan</span>
    </div>
    <div class="dash-welcome-actions">
        <?php if (feature_flag('kod_file_manager')): ?>
        <a href="/file-manager" class="btn btn-welcome-primary"><i data-lucide="folder-open"></i> File Manager</a>
        <?php endif; ?>
        <a href="http://<?= h($user['domain'] ?: SITE_DOMAIN) ?>" target="_blank" rel="noopener" class="btn btn-welcome-ghost"><i data-lucide="external-link"></i> View Site</a>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="folder-open"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= number_format($total_files) ?></div>
            <div class="stat-label">Files</div>
            <div class="stat-sub"><?= format_size($total_size) ?> total</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="hard-drive"></i></div>
        <div class="stat-info">
            <div class="stat-number" id="diskUsedSize"><?= format_size($total_size) ?></div>
            <div class="stat-label">Disk Used</div>
            <div class="stat-sub" id="diskQuotaShort"><?= $user['disk_quota'] ? 'of ' . format_size($user['disk_quota']) : 'unlimited' ?></div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-2">
        <div class="stat-icon icon-purple"><i data-lucide="cpu"></i></div>
        <div class="stat-info">
            <div class="stat-number" id="cpPanelCpuPct">--%</div>
            <div class="stat-label">CPU Load</div>
            <div class="stat-mini-bar"><div id="cpCpuBar" style="width:0%;background:linear-gradient(90deg,#7c3aed,#c084fc)"></div></div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-3">
        <div class="stat-icon icon-orange"><i data-lucide="memory-stick"></i></div>
        <div class="stat-info">
            <div class="stat-number" id="cpPanelMemPct">--%</div>
            <div class="stat-label">Memory</div>
            <div class="stat-mini-bar"><div id="cpMemBar" style="width:0%;background:linear-gradient(90deg,#d97706,#fbbf24)"></div></div>
        </div>
    </div>
</div>

<?php $diskPct = $user['disk_quota'] > 0 ? min(round(($total_size / $user['disk_quota']) * 100), 100) : min(round(($total_size / max(1, disk_total_space('/'))) * 100), 100); ?>
<?php $diskColor = $diskPct > 90 ? ['#ef4444', '#dc2626'] : ($diskPct > 70 ? ['#f97316', '#ea580c'] : ['#22c55e', '#16a34a']); ?>
<!-- Disk Usage Card -->
<div class="card fade-in-delay-2">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0"><i data-lucide="hard-drive"></i> Disk Usage
            <span id="diskLivePulse" style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;font-size:11px;font-weight:400;color:var(--text3)">
                <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;animation:pulse-dot 1.5s infinite"></span> Live
            </span>
        </h3>
        <button type="button" class="btn btn-sm" onclick="refreshDiskUsage(true)" style="font-size:11px"><i data-lucide="refresh-cw"></i> Refresh</button>
    </div>
    <div class="card-body">
        <div class="disk-layout">
            <div class="disk-donut-wrap">
                <svg viewBox="0 0 120 120">
                    <defs>
                        <linearGradient id="diskGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop id="diskGradStop1" offset="0%" stop-color="<?= $diskColor[0] ?>"/>
                            <stop id="diskGradStop2" offset="100%" stop-color="<?= $diskColor[1] ?>"/>
                        </linearGradient>
                    </defs>
                    <circle class="dd-track" cx="60" cy="60" r="52"></circle>
                    <circle id="diskDonut" class="dd-bar" cx="60" cy="60" r="52" stroke-dasharray="326.73" stroke-dashoffset="<?= number_format(326.73 * (1 - $diskPct / 100), 2) ?>"></circle>
                </svg>
                <div class="disk-donut-center">
                    <div class="dd-pct" id="diskDonutPct"><?= $diskPct ?>%</div>
                    <div class="dd-lbl">used</div>
                </div>
            </div>
            <div class="disk-right">
                <div class="disk-progress-info">
                    <div class="dpi-line">
                        <span id="diskUsedLabel"><?= format_size($total_size) ?> used</span>
                        <span id="diskQuotaLabel"><?= $user['disk_quota'] ? format_size($user['disk_quota']) . ' total' : 'Unlimited' ?></span>
                    </div>
                    <div class="disk-progress-track">
                        <div id="diskProgressBar" style="height:100%;border-radius:99px;background:linear-gradient(90deg,<?= $diskColor[0] ?>,<?= $diskColor[1] ?>);transition:width 0.6s ease;width:<?= $diskPct ?>%"></div>
                    </div>
                    <div class="dpi-sub" id="diskPercentLabel"><?= $diskPct ?>% used &middot; <span id="diskFilesCount"><?= number_format($total_files) ?></span> files</div>
                </div>

                <!-- Breakdown Grid -->
                <div class="disk-breakdown-grid" id="diskBreakdown">
                    <div class="bd-tile">
                        <div class="bd-icon" style="background:rgba(0,115,230,.1);color:#0073e6"><i data-lucide="file-code"></i></div>
                        <div class="bd-val" id="bdHtml">0 B</div>
                        <div class="bd-lbl">HTML/CSS</div>
                    </div>
                    <div class="bd-tile">
                        <div class="bd-icon" style="background:rgba(124,58,237,.1);color:#7c3aed"><i data-lucide="image"></i></div>
                        <div class="bd-val" id="bdImages">0 B</div>
                        <div class="bd-lbl">Images</div>
                    </div>
                    <div class="bd-tile">
                        <div class="bd-icon" style="background:rgba(217,119,6,.1);color:#d97706"><i data-lucide="code"></i></div>
                        <div class="bd-val" id="bdScripts">0 B</div>
                        <div class="bd-lbl">Scripts</div>
                    </div>
                    <div class="bd-tile">
                        <div class="bd-icon" style="background:rgba(22,163,74,.1);color:#16a34a"><i data-lucide="database"></i></div>
                        <div class="bd-val" id="bdData">0 B</div>
                        <div class="bd-lbl">Data</div>
                    </div>
                    <div class="bd-tile">
                        <div class="bd-icon" style="background:rgba(100,116,139,.12);color:#475569"><i data-lucide="folder"></i></div>
                        <div class="bd-val" id="bdOther">0 B</div>
                        <div class="bd-lbl">Other</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card fade-in-delay-3" style="margin-top:20px">
    <div class="card-header"><h3><i data-lucide="zap"></i> Quick Actions</h3></div>
    <div class="card-body">
        <div class="quick-actions-grid">
            <?php if (feature_flag('kod_file_manager')): ?>
            <a href="/file-manager" class="qa-tile">
                <div class="qa-icon" style="background:rgba(0,115,230,.1);color:#0073e6;border:1px solid rgba(0,115,230,.16)"><i data-lucide="folder-open"></i></div>
                <div class="qa-info"><span class="qa-title">File Manager</span><span class="qa-sub"><?= $total_files ?> files</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('backup_services')): ?>
            <a href="/cpanel/backups.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(217,119,6,.1);color:#d97706;border:1px solid rgba(217,119,6,.16)"><i data-lucide="archive"></i></div>
                <div class="qa-info"><span class="qa-title">Backups</span><span class="qa-sub">Create &amp; restore</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('ssl_services')): ?>
            <a href="/cpanel/ssl.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(220,38,38,.1);color:#dc2626;border:1px solid rgba(220,38,38,.16)"><i data-lucide="lock"></i></div>
                <div class="qa-info"><span class="qa-title">SSL/TLS</span><span class="qa-sub"><?= $total_ssl ?> certificates</span></div>
            </a>
            <?php endif; ?>
            <?php if (feature_flag('ftp_services')): ?>
            <a href="/cpanel/ftp.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(13,148,136,.1);color:#0d9488;border:1px solid rgba(13,148,136,.16)"><i data-lucide="hard-drive"></i></div>
                <div class="qa-info"><span class="qa-title">FTP Accounts</span><span class="qa-sub"><?= $total_ftp ?> accounts</span></div>
            </a>
            <?php endif; ?>
            <a href="/cpanel/domains.php" class="qa-tile">
                <div class="qa-icon" style="background:rgba(37,99,235,.1);color:#2563eb;border:1px solid rgba(37,99,235,.16)"><i data-lucide="globe"></i></div>
                <div class="qa-info"><span class="qa-title">Domains</span><span class="qa-sub"><?= feature_flag('subdomain_services') ? (count($subdomains) . ' subdomains') : 'manage your domains' ?></span></div>
            </a>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top:20px">
    <!-- Package Info -->
    <div class="card fade-in-delay-1">
        <div class="card-header"><h3><i data-lucide="package" style="color:#7c3aed"></i> My Package</h3></div>
        <div class="card-body" style="padding:0">
            <div class="table-responsive">
                <table class="table info-table">
                    <tr><td>Package</td><td class="text-right"><strong><?= h($user['package_name'] ?? 'Unlimited') ?></strong></td></tr>
                    <tr>
                        <td>Disk Usage</td>
                        <td class="text-right">
                            <?php if ($user['disk_quota']): ?>
                                <?php $pct = $user['disk_quota'] > 0 ? min(round(($total_size / $user['disk_quota']) * 100), 100) : 0; ?>
                                <div class="text-sm font-semibold" style="margin-bottom:4px"><?= format_size($total_size) ?> / <?= format_size($user['disk_quota']) ?></div>
                                <div class="progress-bar" style="height:6px">
                                    <div class="progress-fill" style="width:<?= $pct ?>%"></div>
                                </div>
                            <?php else: ?>
                                &#8734;
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Bandwidth</td>
                        <td class="text-right"><?= $user['bandwidth'] ? format_size($user['bandwidth']) : '&#8734;' ?></td>
                    </tr>
                    <tr><td>Max Domains</td><td class="text-right"><?= $user['max_domains'] ?? '&#8734;' ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Account Info -->
    <div class="card fade-in-delay-2">
        <div class="card-header"><h3><i data-lucide="bar-chart-3" style="color:#0073e6"></i> Account Info</h3></div>
        <div class="card-body" style="padding:0">
            <div class="table-responsive">
                <table class="table info-table">
                    <tr><td class="text-nowrap">Username</td><td><strong><?= h($user['username']) ?></strong></td></tr>
                    <tr><td class="text-nowrap">Domain</td><td><span class="font-mono text-sm"><?= h($user['domain'] ?: SITE_DOMAIN) ?></span></td></tr>
                    <tr><td class="text-nowrap">Email</td><td><?= h($user['email'] ?? '-') ?></td></tr>
                    <tr>
                        <td class="text-nowrap">Home Dir</td>
                        <td><code class="code-inline" style="word-break: break-all;"><?= h($user['home_dir'] ?? '-') ?></code></td>
                    </tr>
                    <tr><td class="text-nowrap">Created</td><td><?= date('M d, Y', strtotime($user['created_at'])) ?></td></tr>
                    <tr><td class="text-nowrap">Status</td><td><span class="badge badge-<?= $user['status'] ?>"><?= h($user['status']) ?></span></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Lucide icons initialize if needed
if (typeof lucide !== 'undefined') {
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function updateCpStatCards() {
    fetch('/api/server_health.php').then(function(r){return r.json()}).then(function(d){
        var cpuPct = d.cpu.percent;
        var el1 = document.getElementById('cpPanelCpuPct');
        if (el1) el1.textContent = cpuPct + '%';
        var bar1 = document.getElementById('cpCpuBar');
        if (bar1) bar1.style.width = Math.min(cpuPct, 100) + '%';
        var memPct = d.memory.percent;
        var el2 = document.getElementById('cpPanelMemPct');
        if (el2) el2.textContent = memPct + '%';
        var bar2 = document.getElementById('cpMemBar');
        if (bar2) bar2.style.width = Math.min(memPct, 100) + '%';
    }).catch(function(){});
}
updateCpStatCards();
setInterval(updateCpStatCards, 5000);

function refreshDiskUsage(force) {
    fetch('/api/disk_usage.php' + (force ? '?force=1' : ''))
        .then(r => r.json())
        .then(d => {
            var pct = d.disk_quota > 0 ? d.percentage : Math.min(d.actual_percent, 100);
            document.getElementById('diskUsedSize').textContent = d.total_size_human;
            document.getElementById('diskUsedLabel').textContent = d.total_size_human + ' used';
            document.getElementById('diskFilesCount').textContent = d.total_files.toLocaleString();
            document.getElementById('diskProgressBar').style.width = pct + '%';

            var donut = document.getElementById('diskDonut');
            if (donut) donut.style.strokeDashoffset = (326.73 * (1 - pct / 100)).toFixed(2);
            var dp = document.getElementById('diskDonutPct');
            if (dp) dp.textContent = pct + '%';

            var colors = ['#22c55e', '#16a34a'];
            if (pct > 90) { colors = ['#ef4444', '#dc2626']; }
            else if (pct > 70) { colors = ['#f97316', '#ea580c']; }
            var g1 = document.getElementById('diskGradStop1');
            var g2 = document.getElementById('diskGradStop2');
            if (g1) g1.setAttribute('stop-color', colors[0]);
            if (g2) g2.setAttribute('stop-color', colors[1]);
            document.getElementById('diskProgressBar').style.background = 'linear-gradient(90deg,' + colors[0] + ',' + colors[1] + ')';

            if (d.disk_quota > 0) {
                document.getElementById('diskQuotaLabel').textContent = d.disk_quota_human + ' total';
                document.getElementById('diskPercentLabel').innerHTML = d.percentage + '% used &middot; <span id="diskFilesCount">' + d.total_files.toLocaleString() + '</span> files';
            } else {
                document.getElementById('diskQuotaLabel').textContent = 'Unlimited';
                document.getElementById('diskPercentLabel').innerHTML = d.total_size_human + ' used &middot; <span id="diskFilesCount">' + d.total_files.toLocaleString() + '</span> files';
            }

            document.getElementById('bdHtml').textContent = d.breakdown.html;
            document.getElementById('bdImages').textContent = d.breakdown.images;
            document.getElementById('bdScripts').textContent = d.breakdown.scripts;
            document.getElementById('bdData').textContent = d.breakdown.data;
            document.getElementById('bdOther').textContent = d.breakdown.other;
        })
        .catch(() => {});
}
refreshDiskUsage();
setInterval(refreshDiskUsage, 10000);
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
