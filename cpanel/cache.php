<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('cache_services');
init_db();
$db = db();

$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$home = $user['home_dir'] ?? '/sdcard/Download/Hosting/public_html';

$cleared = [];
$total_freed = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'opcache') {
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $cleared[] = 'OPcache';
        } else {
            $cleared[] = 'OPcache (not available)';
        }
    }

    if ($action === 'sessions') {
        $session_path = sys_get_temp_dir();
        $count = 0;
        if (is_dir($session_path)) {
            foreach (glob($session_path . '/sess_*') as $file) {
                $total_freed += @filesize($file);
                @unlink($file);
                $count++;
            }
        }
        $cleared[] = "{$count} session file(s)";
    }

    if ($action === 'tmp') {
        $dirs = [$home . '/tmp', $home . '/cache', $home . '/logs', $home . '/.cache'];
        $count = 0;
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($rii as $file) {
                    if ($file->isFile()) {
                        $total_freed += $file->getSize();
                        @unlink($file->getPathname());
                        $count++;
                    }
                }
            }
        }
        $cleared[] = "{$count} temp/cache file(s)";
    }

    if ($action === 'php_errors') {
        $count = 0;
        foreach (glob($home . '/*.log') as $file) {
            $total_freed += @filesize($file);
            @unlink($file);
            $count++;
        }
        foreach (glob($home . '/php_errors.log') as $file) {
            $total_freed += @filesize($file);
            @unlink($file);
            $count++;
        }
        $cleared[] = "{$count} log file(s)";
    }

    if ($action === 'all') {
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $cleared[] = 'OPcache';
        }

        $session_path = sys_get_temp_dir();
        $scount = 0;
        if (is_dir($session_path)) {
            foreach (glob($session_path . '/sess_*') as $file) {
                $total_freed += @filesize($file);
                @unlink($file);
                $scount++;
            }
        }
        $cleared[] = "{$scount} session file(s)";

        $dirs = [$home . '/tmp', $home . '/cache', $home . '/logs', $home . '/.cache'];
        $tcount = 0;
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($rii as $file) {
                    if ($file->isFile()) {
                        $total_freed += $file->getSize();
                        @unlink($file->getPathname());
                        $tcount++;
                    }
                }
            }
        }
        $cleared[] = "{$tcount} temp/cache file(s)";

        $lcount = 0;
        foreach (glob($home . '/*.log') as $file) {
            $total_freed += @filesize($file);
            @unlink($file);
            $lcount++;
        }
        $cleared[] = "{$lcount} log file(s)";
    }

    if ($action === 'opcache_all' || $action === 'all') {
        flash('success', 'Cache cleared: ' . implode(', ', $cleared) . ' (' . format_size($total_freed) . ' freed)');
    } else {
        flash('success', 'Cleared: ' . implode(', ', $cleared));
    }
    redirect('/cpanel/cache.php');
}

$opcache = function_exists('opcache_get_status') ? @opcache_get_status() : false;
$opcache_enabled = $opcache && !empty($opcache['opcache_enabled']);
$opcache_mem = $opcache_enabled ? ($opcache['memory_usage'] ?? []) : [];
$opcache_memory = $opcache_mem['used_memory'] ?? 0;
$opcache_free = $opcache_mem['free_memory'] ?? 0;
$opcache_total = $opcache_memory + $opcache_free;
$opcache_pct = $opcache_total > 0 ? round($opcache_memory / $opcache_total * 100) : 0;
$opcache_hits = $opcache_enabled ? ($opcache['opcache_statistics']['hits'] ?? 0) : 0;
$opcache_misses = $opcache_enabled ? ($opcache['opcache_statistics']['misses'] ?? 0) : 0;
$opcache_requests = $opcache_hits + $opcache_misses;
$opcache_hit_pct = $opcache_requests > 0 ? round($opcache_hits / $opcache_requests * 100) : 0;
$opcache_cached = $opcache_enabled ? intval($opcache['opcache_statistics']['num_cached_scripts'] ?? 0) : 0;

$session_count = 0;
$session_size = 0;
$session_path = sys_get_temp_dir();
if (is_dir($session_path)) {
    foreach (glob($session_path . '/sess_*') as $file) {
        $session_count++;
        $session_size += @filesize($file);
    }
}

$tmp_size = 0;
$tmp_count = 0;
$dirs = [$home . '/tmp', $home . '/cache', $home . '/logs', $home . '/.cache'];
foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $tmp_count++;
                $tmp_size += $file->getSize();
            }
        }
    }
}

$log_count = 0;
$log_size = 0;
foreach (glob($home . '/*.log') as $file) {
    if (!is_file($file)) continue;
    $log_count++;
    $log_size += @filesize($file);
}

$nav = 'cache';
$page_title = 'Cache Manager';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="zap" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Cache Manager</div>
        <div class="hero-desc">Clear OPcache bytecode, session files, temp files and error logs to reclaim space and fix stale-page issues.</div>
    </div>
    <div class="hero-actions">
        <a href="/cpanel/cache.php" class="btn btn-ghost"><i data-lucide="refresh-cw" class="lucide"></i> Refresh</a>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="all">
            <button type="submit" class="btn btn-danger" onclick="return confirm('Clear ALL cache types? Active sessions will be logged out.')"><i data-lucide="trash-2" class="lucide"></i> Clear All</button>
        </form>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="cpu" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $opcache_enabled ? format_size($opcache_memory) : 'N/A' ?></div>
            <div class="stat-label">OPcache Memory</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $opcache_total ? round($opcache_memory / $opcache_total * 100) . '% of ' . format_size($opcache_total) : 'Not enabled' ?></div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $session_count ?></div>
            <div class="stat-label">Session Files</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= format_size($session_size) ?></div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon"><i data-lucide="folder-clock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $tmp_count ?></div>
            <div class="stat-label">Temp &amp; Cache Files</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= format_size($tmp_size) ?></div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon"><i data-lucide="file-warning" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $log_count ?></div>
            <div class="stat-label">Error Log Files</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= format_size($log_size) ?></div>
        </div>
    </div>
</div>

<div class="grid-2 fade-in-delay-2" style="margin-bottom:20px">

    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div class="rec-icon icon-green"><i data-lucide="cpu" class="lucide"></i></div>
                    <div>
                        <div style="font-weight:700;color:var(--text);font-size:14px">OPcache</div>
                        <div style="font-size:12px;color:var(--text3);margin-top:2px">Bytecode cache for compiled PHP</div>
                    </div>
                </div>
                <span class="badge <?= $opcache_enabled ? 'badge-active' : 'badge-suspended' ?>"><span class="dot <?= $opcache_enabled ? 'green' : 'gray' ?>"></span> <?= $opcache_enabled ? 'Active' : 'Inactive' ?></span>
            </div>

            <?php if ($opcache_enabled): ?>
            <div>
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px">
                    <span class="text-muted">Memory used</span>
                    <span style="font-weight:700;color:var(--text)"><?= format_size($opcache_memory) ?> <span style="color:var(--text4);font-weight:400">/ <?= format_size($opcache_total) ?></span></span>
                </div>
                <div class="progress-bar" style="height:6px"><div class="progress-fill" style="width:<?= $opcache_pct ?>%"></div></div>
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-top:12px;margin-bottom:5px">
                    <span class="text-muted">Hit rate</span>
                    <span style="font-weight:700;color:var(--text)"><?= $opcache_hit_pct ?>%</span>
                </div>
                <div class="progress-bar" style="height:6px"><div class="progress-fill progress-green" style="width:<?= $opcache_hit_pct ?>%"></div></div>
                <div style="display:flex;justify-content:space-between;margin-top:8px">
                    <span style="font-size:11px;color:var(--text4)"><?= number_format($opcache_hits) ?> hits &middot; <?= number_format($opcache_misses) ?> misses</span>
                    <span style="font-size:11px;color:var(--text4)"><?= $opcache_cached ?> scripts cached</span>
                </div>
            </div>
            <?php else: ?>
            <div class="tip-card tip-orange" style="margin:0">
                <i data-lucide="info" class="lucide"></i>
                <div class="tip-body">OPcache is not enabled on this server. Enable it in your PHP configuration to speed up PHP execution.</div>
            </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto">
                <span style="font-size:11px;color:var(--text4)">Recompiles scripts on next request</span>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="action" value="opcache">
                    <button type="submit" class="btn btn-sm btn-ghost"><i data-lucide="refresh-cw" class="lucide"></i> Reset</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div class="rec-icon icon-blue"><i data-lucide="key-round" class="lucide"></i></div>
                    <div>
                        <div style="font-weight:700;color:var(--text);font-size:14px">Session Files</div>
                        <div style="font-size:12px;color:var(--text3);margin-top:2px"><?= $session_count ?> active session file<?= $session_count === 1 ? '' : 's' ?></div>
                    </div>
                </div>
                <span class="chip-mono"><?= format_size($session_size) ?></span>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto">
                <span style="font-size:11px;color:var(--text4)"><i data-lucide="info" class="lucide" style="width:12px;height:12px;vertical-align:-2px"></i> Clears all sessions and logs everyone out</span>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="action" value="sessions">
                    <button type="submit" class="btn btn-sm btn-ghost" onclick="return confirm('Clear all session files? Users will be logged out.')"><i data-lucide="trash-2" class="lucide"></i> Clear</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div class="rec-icon icon-orange"><i data-lucide="folder-clock" class="lucide"></i></div>
                    <div>
                        <div style="font-weight:700;color:var(--text);font-size:14px">Temp &amp; Cache Files</div>
                        <div style="font-size:12px;color:var(--text3);margin-top:2px"><?= $tmp_count ?> file<?= $tmp_count === 1 ? '' : 's' ?> in tmp, cache &amp; logs dirs</div>
                    </div>
                </div>
                <span class="chip-mono"><?= format_size($tmp_size) ?></span>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto">
                <span style="font-size:11px;color:var(--text4)"><i data-lucide="info" class="lucide" style="width:12px;height:12px;vertical-align:-2px"></i> Safe to clear at any time</span>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="action" value="tmp">
                    <button type="submit" class="btn btn-sm btn-ghost" onclick="return confirm('Clear temp/cache files?')"><i data-lucide="trash-2" class="lucide"></i> Clear</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;gap:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div class="rec-icon icon-purple"><i data-lucide="file-warning" class="lucide"></i></div>
                    <div>
                        <div style="font-weight:700;color:var(--text);font-size:14px">Error Logs</div>
                        <div style="font-size:12px;color:var(--text3);margin-top:2px">PHP error log<?= $log_count === 1 ? '' : 's' ?> in your home directory</div>
                    </div>
                </div>
                <span class="chip-mono"><?= format_size($log_size) ?></span>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto">
                <span style="font-size:11px;color:var(--text4)"><i data-lucide="info" class="lucide" style="width:12px;height:12px;vertical-align:-2px"></i> Helpful for debugging, safe to delete</span>
                <form method="POST" style="margin:0">
                    <input type="hidden" name="action" value="php_errors">
                    <button type="submit" class="btn btn-sm btn-ghost" onclick="return confirm('Delete error log files?')"><i data-lucide="trash-2" class="lucide"></i> Clear</button>
                </form>
            </div>
        </div>
    </div>

</div>

<div class="tip-card tip-orange fade-in-delay-3">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong>Careful.</strong> Clearing sessions logs out every active user. Temp and log files are safe to clear at any time.</div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
