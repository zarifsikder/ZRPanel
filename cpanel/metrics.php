<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('metrics_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT u.*, p.name as package_name, p.disk_quota, p.bandwidth FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$home = $user['home_dir'] ?? '';
$total_files = 0;
$total_size = 0;
$file_types = [];
$recent_files = [];

if (is_dir($home)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home));
    foreach ($rii as $file) {
        if ($file->isFile()) {
            $total_files++;
            $size = $file->getSize();
            $total_size += $size;
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) ?: 'other';
            if (!isset($file_types[$ext])) $file_types[$ext] = ['count' => 0, 'size' => 0];
            $file_types[$ext]['count']++;
            $file_types[$ext]['size'] += $size;
            $recent_files[] = [
                'name' => $file->getFilename(),
                'size' => $size,
                'mtime' => $file->getMTime(),
                'path' => str_replace($home, '', $file->getPathname()),
            ];
        }
    }
}

usort($recent_files, function($a, $b) { return $b['mtime'] - $a['mtime']; });
$recent_files = array_slice($recent_files, 0, 10);
uasort($file_types, function($a, $b) { return $b['size'] - $a['size']; });
$file_types = array_slice($file_types, 0, 8, true);

$subdomains = $db->prepare("SELECT COUNT(*) FROM subdomains WHERE user_id = ?");
$subdomains->execute([$user_id]);
$total_subdomains = $subdomains->fetchColumn();

$bandwidth_used = 0;
$bandwidth_total = $user['bandwidth'] ?? 0;
$disk_used = $total_size;
$disk_total = $user['disk_quota'] ?? 0;

$nav = 'metrics';
$page_title = 'Metrics & Statistics';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="chart-column" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Metrics &amp; Statistics</div>
        <div class="hero-desc">A snapshot of your account: files, storage, domains and traffic at a glance.</div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="folder" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= number_format($total_files) ?></div>
            <div class="stat-label">Total Files</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="hard-drive" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= format_size($total_size) ?></div>
            <div class="stat-label">Disk Used</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-2">
        <div class="stat-icon icon-purple"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_subdomains ?></div>
            <div class="stat-label">Subdomains</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-3">
        <div class="stat-icon icon-orange"><i data-lucide="gauge" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $bandwidth_total ? format_size($bandwidth_total) : '&#8734;' ?></div>
            <div class="stat-label">Bandwidth Quota</div>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Disk Usage -->
    <div class="card fade-in-delay-1">
        <div class="card-header"><h3><i data-lucide="hard-drive" class="lucide"></i> Disk Usage</h3></div>
        <div class="card-body">
            <?php if ($disk_total > 0): ?>
                <?php $disk_pct = round(($disk_used / $disk_total) * 100); ?>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                    <span class="text-muted">Used</span>
                    <span style="font-weight:700"><?= format_size($disk_used) ?> / <?= format_size($disk_total) ?></span>
                </div>
                <div class="progress-bar" style="height:12px">
                    <div class="progress-fill" style="width:<?= min($disk_pct, 100) ?>%"></div>
                </div>
                <div style="text-align:right;margin-top:4px;font-size:12px;font-weight:600;color:var(--text3)"><?= $disk_pct ?>%</div>
            <?php else: ?>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                    <span class="text-muted">Used</span>
                    <span style="font-weight:700"><?= format_size($disk_used) ?></span>
                </div>
                <div class="progress-bar" style="height:12px">
                    <div class="progress-fill" style="width:0%"></div>
                </div>
                <div style="text-align:center;margin-top:8px;color:var(--text4);font-size:13px">Unlimited</div>
            <?php endif; ?>

            <div class="separator"></div>

            <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px">Space by File Type</p>
            <?php if (!empty($file_types)): ?>
                <?php foreach ($file_types as $ext => $info): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border2)">
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;background:var(--bg4);padding:2px 8px;border-radius:4px;min-width:40px;text-align:center"><?= h($ext) ?></span>
                            <span style="font-size:12px;color:var(--text3)"><?= $info['count'] ?> files</span>
                        </div>
                        <span style="font-size:12px;font-weight:600"><?= format_size($info['size']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted" style="font-size:13px">No files found</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bandwidth & Resources -->
    <div class="card fade-in-delay-2">
        <div class="card-header"><h3><i data-lucide="trending-up" class="lucide"></i> Resource Usage</h3></div>
        <div class="card-body">
            <div style="margin-bottom:20px">
                <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                    <span class="text-muted">Bandwidth</span>
                    <span style="font-weight:700"><?= format_size($bandwidth_used) ?> / <?= $bandwidth_total ? format_size($bandwidth_total) : '&#8734;' ?></span>
                </div>
                <?php if ($bandwidth_total > 0): ?>
                <div class="progress-bar" style="height:8px">
                    <div class="progress-fill progress-green" style="width:<?= min(round(($bandwidth_used / $bandwidth_total) * 100), 100) ?>%"></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="separator"></div>

            <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px">Service Usage</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <?php if (feature_flag('subdomain_services')): ?>
                <div style="padding:14px;background:var(--bg3);border-radius:var(--radius-sm);text-align:center;border:1px solid var(--border)">
                    <div style="font-size:24px;font-weight:800;color:var(--text)"><?= $total_subdomains ?></div>
                    <div style="font-size:11px;color:var(--text3);font-weight:600;margin-top:2px">Subdomains</div>
                </div>
                <?php endif; ?>
                <div style="padding:14px;background:var(--bg3);border-radius:var(--radius-sm);text-align:center;border:1px solid var(--border)">
                    <div style="font-size:24px;font-weight:800;color:var(--text)"><?= $total_files ?></div>
                    <div style="font-size:11px;color:var(--text3);font-weight:600;margin-top:2px">Files</div>
                </div>
            </div>

            <div class="separator"></div>

            <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:8px">Recent Files</p>
            <?php if (!empty($recent_files)): ?>
                <?php foreach ($recent_files as $f): ?>
                    <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border2);font-size:12px">
                        <span style="color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%"><?= h($f['name']) ?></span>
                        <span style="color:var(--text4);white-space:nowrap"><?= format_size($f['size']) ?> &middot; <?= date('M d', $f['mtime']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
