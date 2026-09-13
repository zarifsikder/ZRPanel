<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('backup_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$home = $user['home_dir'] ?? '';
$backup_dir = $home . '/backups';
if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $ts = date('Y-m-d_H-i-s');
        $filename = "backup_{$ts}.tar.gz";
        $source = $home . '/public_html';
        if (is_dir($source)) {
            exec("tar -czf " . escapeshellarg($backup_dir . '/' . $filename) . " -C " . escapeshellarg(dirname($source)) . " " . escapeshellarg(basename($source)) . " 2>&1", $out, $ret);
            $type = 'files';
        } else {
            flash('error', 'No files to backup');
            redirect('/cpanel/backups.php');
        }

        $filepath = $backup_dir . '/' . $filename;
        if (file_exists($filepath)) {
            $size = filesize($filepath);
            $db->prepare("INSERT INTO backups (user_id, filename, size, type) VALUES (?, ?, ?, ?)")
               ->execute([$user_id, $filename, $size, $type]);
            flash('success', "Backup created: {$filename} (" . format_size($size) . ")");
        } else {
            flash('error', 'Backup creation failed');
        }
    } elseif ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT filename, type FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($backup && file_exists($backup_dir . '/' . $backup['filename'])) {
            $filepath = $backup_dir . '/' . $backup['filename'];
            $restore_dir = $backup_dir . '/restore_' . time();
            @mkdir($restore_dir, 0755, true);
            exec("tar -xzf " . escapeshellarg($filepath) . " -C " . escapeshellarg($restore_dir) . " 2>&1", $out, $ret);
            if ($ret === 0) {
                $restored = 0;
                $public_html_restore = $restore_dir . '/public_html';
                if (is_dir($public_html_restore)) {
                    exec("cp -a " . escapeshellarg($public_html_restore . '/.') . " " . escapeshellarg($home . '/public_html/') . " 2>&1");
                    $restored++;
                }
                exec("rm -rf " . escapeshellarg($restore_dir));
                flash('success', "Backup restored successfully ({$restored} items)");
            } else {
                exec("rm -rf " . escapeshellarg($restore_dir));
                flash('error', 'Failed to extract backup archive');
            }
        } else {
            flash('error', 'Backup file not found');
        }
    } elseif ($action === 'download') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT filename FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($backup && file_exists($backup_dir . '/' . $backup['filename'])) {
            $filepath = $backup_dir . '/' . $backup['filename'];
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        }
        flash('error', 'Backup file not found');
        redirect('/cpanel/backups.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT filename FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($backup) {
            @unlink($backup_dir . '/' . $backup['filename']);
        }
        $db->prepare("DELETE FROM backups WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'Backup deleted');
    }
    redirect('/cpanel/backups.php');
}

$backups = $db->prepare("SELECT * FROM backups WHERE user_id = ? ORDER BY created_at DESC");
$backups->execute([$user_id]);
$backups = $backups->fetchAll(PDO::FETCH_ASSOC);

$total_size = array_sum(array_column($backups, 'size'));

$public_html = $home . '/public_html';
$web_files = 0;
$web_size = 0;
if (is_dir($public_html)) {
    try {
        $rsc = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public_html, FilesystemIterator::SKIP_DOTS));
        foreach ($rsc as $f) {
            if ($f->isFile()) {
                $web_files++;
                $web_size += $f->getSize();
            }
        }
    } catch (Throwable $e) {}
}
$free_space = @disk_free_space(realpath($home) ?: $home) ?: 0;
$latest = $backups[0] ?? null;

function bkid_friendly_time($dt) {
    $ts = strtotime((string)$dt);
    if (!$ts) return (string)$dt;
    $diff = time() - $ts;
    if ($diff < 0) return 'just now';
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 86400 * 30) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

$nav = 'backups';
$page_title = 'Backups';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon green"><i data-lucide="archive" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Backups</div>
        <div class="hero-desc">Create snapshots of your website files, then download or restore them anytime.</div>
    </div>
    <div class="hero-actions">
        <form method="POST" data-backup style="margin:0">
            <input type="hidden" name="action" value="create">
            <button type="submit" class="btn btn-primary"><i data-lucide="plus" class="lucide"></i> Backup Now</button>
        </form>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="hard-drive" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($backups) ?></div>
            <div class="stat-label">Total Backups</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= format_size($total_size) ?> on disk</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon"><i data-lucide="archive" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= format_size($total_size) ?></div>
            <div class="stat-label">Storage Used</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= count($backups) ?> backup<?= count($backups) === 1 ? '' : 's' ?> in ~/backups</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon"><i data-lucide="clock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" style="font-size:clamp(16px,3vw,22px)"><?= $latest ? bkid_friendly_time($latest['created_at']) : '—' ?></div>
            <div class="stat-label">Latest Backup</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $latest ? date('M d, Y H:i', strtotime($latest['created_at'])) : 'No backups yet' ?></div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon"><i data-lucide="space" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= format_size($free_space) ?></div>
            <div class="stat-label">Free Space</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">available on disk</div>
        </div>
    </div>
</div>

<div class="grid-2 fade-in-delay-2" style="margin-bottom:20px">
    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Create Backup</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;flex:1">
            <div class="bk-scope">
                <span class="bk-scope-ic"><i data-lucide="folder" class="lucide"></i></span>
                <div>
                    <div class="bk-scope-title">public_html</div>
                    <div class="bk-scope-meta"><?= number_format($web_files) ?> files &middot; <?= format_size($web_size) ?></div>
                </div>
            </div>
            <div style="font-size:12px;color:var(--text3);line-height:1.6">
                A new backup archives everything inside your website root into a
                <code style="font-size:11px">.tar.gz</code> file saved to your <code style="font-size:11px">~/backups</code> folder.
            </div>
            <form method="POST" data-backup style="margin-top:auto">
                <input type="hidden" name="action" value="create">
                <button type="submit" class="btn btn-primary" style="width:100%"><i data-lucide="download" class="lucide"></i> Create Backup</button>
            </form>
        </div>
    </div>

    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-header"><h3><i data-lucide="shield-check" class="lucide"></i> Backup Details</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;flex:1">
            <div style="display:flex;align-items:center;gap:12px;font-size:13px">
                <i data-lucide="file-archive" class="lucide" style="width:16px;height:16px;color:var(--success);flex-shrink:0"></i>
                <span style="color:var(--text2)">Archives are gzip-compressed TAR files (only files are backed up).</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;font-size:13px">
                <i data-lucide="rotate-ccw" class="lucide" style="width:16px;height:16px;color:var(--primary);flex-shrink:0"></i>
                <span style="color:var(--text2)">Restoring overwrites your current website files with the snapshot contents.</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;font-size:13px">
                <i data-lucide="download" class="lucide" style="width:16px;height:16px;color:var(--warning);flex-shrink:0"></i>
                <span style="color:var(--text2)">Download any backup to keep an off-server copy on your device.</span>
            </div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-3">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> Available Backups (<?= count($backups) ?>)</h3></div>
    <?php if (!empty($backups)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="bkSearch" placeholder="Search backups..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="bkCount"><?= count($backups) ?> backup<?= count($backups) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($backups) ? '14px' : '16px' ?>">
        <?php if (empty($backups)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="archive-x" class="lucide"></i></div>
                <strong>No backups yet</strong>
                <p>Create your first backup to snapshot your website files.</p>
                <div class="empty-state-actions">
                    <form method="POST" data-backup>
                        <input type="hidden" name="action" value="create">
                        <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="download" class="lucide"></i> Create First Backup</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
        <div class="bk-list">
            <?php foreach ($backups as $b): ?>
                <?php $btype = strtolower((string)($b['type'] ?? 'files')); ?>
                <?php if ($btype === 'files') { $bt_badge = 'badge-blue'; $bt_label = 'Files'; }
                      elseif ($btype === 'full') { $bt_badge = 'badge-purple'; $bt_label = 'Files + DB'; }
                      elseif ($btype === 'database') { $bt_badge = 'badge-pending'; $bt_label = 'Database'; }
                      else { $bt_badge = 'badge-blue'; $bt_label = ucfirst($btype); } ?>
                <div class="bk-row" data-name="<?= h($b['filename']) ?>">
                    <span class="bk-ic"><i data-lucide="archive" class="lucide"></i></span>
                    <div class="bk-main">
                        <div class="bk-name" title="<?= h($b['filename']) ?>"><?= h($b['filename']) ?></div>
                        <div class="bk-meta">
                            <span class="badge <?= $bt_badge ?>"><?= $bt_label ?></span>
                            <span title="<?= date('M d, Y H:i', strtotime($b['created_at'])) ?>"><?= bkid_friendly_time($b['created_at']) ?></span>
                            <span style="font-weight:600;color:var(--text2)"><?= format_size($b['size']) ?></span>
                        </div>
                    </div>
                    <div class="bk-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="download">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-info" title="Download"><i data-lucide="download" class="lucide"></i> Download</button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Restore this backup? This will overwrite your current website files.')">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Restore"><i data-lucide="rotate-ccw" class="lucide"></i> Restore</button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete this backup? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.bk-scope{display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm)}
.bk-scope-ic{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:var(--primary-light);color:var(--primary);border:1px solid var(--primary-border);flex-shrink:0}
.bk-scope-ic .lucide{width:17px;height:17px}
.bk-scope-title{font-weight:700;color:var(--text);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace}
.bk-scope-meta{font-size:12px;color:var(--text3);margin-top:1px}
.bk-list{display:flex;flex-direction:column;gap:10px}
.bk-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.bk-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.bk-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0062bd,#0073e6 55%,#1e8ff0);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.bk-ic .lucide{width:19px;height:19px}
.bk-main{min-width:0;flex:1}
.bk-name{font-weight:600;color:var(--text);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bk-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:3px}
.bk-meta .badge{font-size:10px;padding:3px 8px}
.bk-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:640px){
  .bk-row{flex-wrap:wrap}
  .bk-main{flex-basis:100%}
  .bk-actions{margin-left:0;width:100%}
  .bk-actions form{flex:1}
  .bk-actions .btn{width:100%}
}
</style>

<script>
(function () {
    var input = document.getElementById('bkSearch');
    var count = document.getElementById('bkCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.bk-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' backup' + (shown === 1 ? '' : 's');
        });
    }
    document.querySelectorAll('form[data-backup]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = this.querySelector('button[type=submit]');
            if (!b || b.disabled) return;
            b.disabled = true;
            b.innerHTML = '<i data-lucide="loader-circle" class="lucide"></i> Creating&hellip;';
            if (window.lucide) { try { window.lucide.createIcons(); } catch (e) {} }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
