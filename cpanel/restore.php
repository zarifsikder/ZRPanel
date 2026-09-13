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

$detail_backup = null;
$detail_files = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        $restore_type = $_POST['restore_type'] ?? 'full';

        $stmt = $db->prepare("SELECT filename, type FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$backup || !file_exists($backup_dir . '/' . $backup['filename'])) {
            flash('error', 'Backup file not found');
            redirect('/cpanel/restore.php');
        }

        $filepath = $backup_dir . '/' . $backup['filename'];
        $restored_files = false;
        $restored_db = false;
        $errors = [];

        if ($restore_type === 'full' || $restore_type === 'files') {
            $ret = 0;
            exec("tar -xzf " . escapeshellarg($filepath) . " -C " . escapeshellarg($home) . " 2>&1", $out, $ret);
            if ($ret === 0) {
                $restored_files = true;
            } else {
                $errors[] = 'File extraction failed: ' . implode("\n", $out);
            }
        }

        if ($restore_type === 'full' || $restore_type === 'database') {
            $tmp_dir = sys_get_temp_dir() . '/backup_extract_' . uniqid();
            @mkdir($tmp_dir, 0755, true);
            exec("tar -xzf " . escapeshellarg($filepath) . " -C " . escapeshellarg($tmp_dir) . " 2>&1", $out, $ret);
            if ($ret === 0) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp_dir));
                foreach ($iterator as $file) {
                    if ($file->isFile() && preg_match('/\.sql$/i', $file->getFilename())) {
                        $sql_content = file_get_contents($file->getPathname());
                        if ($sql_content !== false) {
                            try {
                                $db->exec($sql_content);
                                $restored_db = true;
                            } catch (PDOException $e) {
                                $errors[] = 'SQL import error: ' . $e->getMessage();
                            }
                        }
                    }
                }
            } else {
                $errors[] = 'Archive extraction for DB failed';
            }
            $iterator2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp_dir), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator2 as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($tmp_dir);
        }

        if (!empty($errors)) {
            flash('error', 'Restore completed with errors: ' . implode('; ', $errors));
        } else {
            $msg = 'Successfully restored';
            if ($restored_files && $restored_db) $msg .= ' (files + database)';
            elseif ($restored_files) $msg .= ' (files only)';
            elseif ($restored_db) $msg .= ' (database only)';
            flash('success', $msg);
        }
        redirect('/cpanel/restore.php');

    } elseif ($action === 'detail') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $detail_backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($detail_backup && file_exists($backup_dir . '/' . $detail_backup['filename'])) {
            $filepath = $backup_dir . '/' . $detail_backup['filename'];
            $out = [];
            exec("tar -tzf " . escapeshellarg($filepath) . " 2>&1", $out, $ret);
            if ($ret === 0) {
                $detail_files = $out;
            }
        }
        $detail_backup = $detail_backup ?: null;

    } elseif ($action === 'upload') {
        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['backup_file']['tmp_name'];
            $orig_name = $_FILES['backup_file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $gz = strtolower(pathinfo($orig_name, PATHINFO_FILENAME));

            if ($ext !== 'gz' && !($ext === 'tar' && $gz === '')) {
                $is_tar_gz = (substr($orig_name, -7) === '.tar.gz');
            } else {
                $is_tar_gz = true;
            }

            if (!$is_tar_gz) {
                flash('error', 'Only .tar.gz backup files are allowed');
                redirect('/cpanel/restore.php');
            }

            $ret = 0;
            exec("tar -tzf " . escapeshellarg($tmp) . " 2>&1", $out, $ret);
            if ($ret !== 0) {
                flash('error', 'Invalid archive file');
                redirect('/cpanel/restore.php');
            }

            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($orig_name));
            if (substr($safe_name, -7) !== '.tar.gz') {
                $safe_name = preg_replace('/\.(tar\.gz|tar|gz)$/i', '', $safe_name) . '.tar.gz';
            }
            $dest = $backup_dir . '/' . $safe_name;

            if (file_exists($dest)) {
                flash('error', 'A backup with this name already exists');
                redirect('/cpanel/restore.php');
            }

            if (move_uploaded_file($tmp, $dest)) {
                $size = filesize($dest);
                $type = 'full';
                $sql_count = 0;
                exec("tar -tzf " . escapeshellarg($dest) . " 2>&1", $list, $ret);
                if ($ret === 0) {
                    foreach ($list as $entry) {
                        if (preg_match('/\.sql$/i', $entry)) $sql_count++;
                    }
                }
                if ($sql_count > 0 && count($list) <= $sql_count + 1) {
                    $type = 'database';
                } elseif ($sql_count === 0) {
                    $type = 'files';
                }

                $db->prepare("INSERT INTO backups (user_id, filename, size, type) VALUES (?, ?, ?, ?)")
                   ->execute([$user_id, $safe_name, $size, $type]);
                flash('success', "Backup uploaded: {$safe_name} (" . format_size($size) . ")");
            } else {
                flash('error', 'Failed to save uploaded file');
            }
        } else {
            flash('error', 'No file uploaded or upload error');
        }
        redirect('/cpanel/restore.php');

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
        redirect('/cpanel/restore.php');

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
        redirect('/cpanel/restore.php');
    }
}

$backups = $db->prepare("SELECT * FROM backups WHERE user_id = ? ORDER BY created_at DESC");
$backups->execute([$user_id]);
$backups = $backups->fetchAll(PDO::FETCH_ASSOC);

$total_size = array_sum(array_column($backups, 'size'));
$type_counts = array_count_values(array_map(fn($b) => $b['type'], $backups));
$full_count = $type_counts['full'] ?? 0;
$file_count = $type_counts['files'] ?? 0;
$db_count = $type_counts['database'] ?? 0;

$nav = 'restore';
$page_title = 'Backup & Restore';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.rs-hero{
    position:relative;overflow:hidden;
    border-radius:18px;
    background:linear-gradient(120deg,#0f766e 0%,#2563eb 55%,#6d28d9 100%);
    padding:26px 28px;margin-bottom:22px;color:#fff;
    box-shadow:0 14px 34px -12px rgba(37,99,235,.45);
}
.rs-hero::before,.rs-hero::after{content:'';position:absolute;border-radius:50%;pointer-events:none}
.rs-hero::before{width:230px;height:230px;background:radial-gradient(circle,rgba(255,255,255,.16),transparent 70%);top:-80px;right:-40px}
.rs-hero::after{width:150px;height:150px;background:radial-gradient(circle,rgba(255,255,255,.1),transparent 70%);bottom:-70px;left:28%}
.rs-hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.rs-hero-icon{
    width:52px;height:52px;border-radius:14px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,.16);backdrop-filter:blur(4px);
    border:1px solid rgba(255,255,255,.22);
}
.rs-hero-icon .lucide{width:26px;height:26px}
.rs-hero-text{flex:1;min-width:220px}
.rs-hero-text h2{margin:0 0 4px;font-size:22px;font-weight:700;letter-spacing:-.3px}
.rs-hero-text p{margin:0;font-size:13px;opacity:.85}
.rs-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.rs-hero-stats{position:relative;z-index:1;display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
.rs-pill{
    display:inline-flex;align-items:center;gap:7px;
    padding:6px 14px;border-radius:999px;font-size:12.5px;font-weight:600;
    background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);backdrop-filter:blur(4px);
}
.rs-pill .lucide{width:14px;height:14px}
.rs-pill strong{font-weight:800}

.rs-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px}
.rs-stat{
    display:flex;align-items:center;gap:13px;padding:16px 18px;
    background:var(--bg2);border:1px solid var(--border);border-radius:14px;
    box-shadow:var(--shadow-xs);transition:transform .18s ease,box-shadow .18s ease;
}
.rs-stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.rs-stat-icon{width:44px;height:44px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.rs-stat-icon .lucide{width:21px;height:21px}
.rs-stat-num{font-size:22px;font-weight:800;letter-spacing:-.5px;color:var(--text);line-height:1}
.rs-stat-lbl{font-size:11.5px;color:var(--text3);font-weight:600;margin-top:3px}

.rs-grid{display:grid;grid-template-columns:1.6fr 1fr;gap:18px;align-items:start}
.rs-side{display:grid;gap:18px}
@media(max-width:860px){.rs-grid{grid-template-columns:1fr}}

.rs-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-xs)}
.rs-card-head{display:flex;align-items:center;gap:9px;padding:16px 20px;border-bottom:1px solid var(--border2)}
.rs-card-head .lucide{width:17px;height:17px;color:#6366f1}
.rs-card-head h3{margin:0;font-size:14px;font-weight:700;color:var(--text);flex:1}
.rs-card-head .rs-count{
    font-size:11.5px;font-weight:700;color:var(--text3);background:var(--bg4);
    padding:2px 10px;border-radius:999px;
}
.rs-card-body{padding:16px 20px}

.rs-row{
    display:flex;align-items:center;gap:14px;
    padding:14px 16px;border:1px solid var(--border);border-radius:12px;
    background:var(--bg3);margin-bottom:10px;transition:border-color .15s,box-shadow .15s;
}
.rs-row:hover{border-color:rgba(0,115,230,.25);box-shadow:var(--shadow-sm)}
.rs-row:last-child{margin-bottom:0}
.rs-avatar{
    width:42px;height:42px;min-width:42px;border-radius:11px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;color:#fff;
}
.rs-avatar .lucide{width:20px;height:20px}
.rs-row-main{flex:1;min-width:0}
.rs-row-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rs-name{font-size:13.5px;font-weight:700;color:var(--text);font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;word-break:break-all}
.rs-badge{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:2px 9px;border-radius:999px;letter-spacing:.3px;text-transform:uppercase}
.rs-badge .lucide{width:10px;height:10px}
.rs-badge-blue{background:var(--primary-light);color:var(--primary);border:1px solid var(--primary-border)}
.rs-badge-violet{background:rgba(99,102,241,.09);color:#6366f1;border:1px solid rgba(99,102,241,.18)}
.rs-badge-green{background:var(--success-light);color:var(--success);border:1px solid var(--success-border)}
.rs-row-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:5px;font-size:11.5px;color:var(--text4)}
.rs-row-meta .lucide{width:12px;height:12px;color:var(--text4)}
.rs-meta-item{display:inline-flex;align-items:center;gap:5px;min-width:0}

.rs-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    padding:7px 13px;border-radius:9px;font-size:12px;font-weight:600;
    cursor:pointer;transition:background .15s,color .15s,border-color .15s,transform .15s;
    text-decoration:none;white-space:nowrap;border:1px solid transparent;font-family:inherit;
}
.rs-btn .lucide{width:14px;height:14px}
.rs-btn:hover{transform:translateY(-1px)}
.rs-btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.rs-btn-primary:hover{background:var(--primary-hover);color:#fff}
.rs-btn-ghost{background:transparent;color:var(--text2);border-color:var(--border)}
.rs-btn-ghost:hover{background:var(--bg4);border-color:var(--text4);color:var(--text)}
.rs-btn-danger-ghost{background:transparent;color:var(--danger);border-color:var(--danger-border)}
.rs-btn-danger-ghost:hover{background:var(--danger-light);color:var(--danger)}
.rs-btn-ghost-white{background:rgba(255,255,255,.16);color:#fff;border:1px solid rgba(255,255,255,.3)}
.rs-btn-ghost-white:hover{background:rgba(255,255,255,.26);color:#fff}
.rs-btn-block{width:100%}
.rs-btn-ico{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    padding:7px 9px;border-radius:9px;font-size:11.5px;font-weight:600;
    cursor:pointer;transition:background .15s,color .15s,border-color .15s;
    background:transparent;color:var(--text3);border:1px solid var(--border);font-family:inherit;
}
.rs-btn-ico .lucide{width:14px;height:14px}
.rs-btn-ico:hover{background:var(--bg4);border-color:var(--text4);color:var(--text)}
.rs-btn-ico-danger:hover{background:var(--danger-light);border-color:var(--danger-border);color:var(--danger)}
.rs-row-actions{display:flex;flex-direction:column;gap:6px;flex-shrink:0;min-width:150px}
.rs-row-actions .rs-btn{width:100%}
.rs-row-icons{display:flex;gap:6px}
.rs-row-icons .rs-btn-ico{flex:1}

.rs-empty{
    text-align:center;padding:30px 16px;color:var(--text3);
    border:1.5px dashed var(--border);border-radius:12px;background:var(--bg3);
}
.rs-empty>i.lucide{width:30px;height:30px;color:var(--text4);margin-bottom:10px}
.rs-empty p{margin:0 0 14px;font-size:13px}
.rs-empty-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

.rs-dropzone{
    display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;
    padding:26px 16px;margin-bottom:14px;cursor:pointer;
    border:1.5px dashed var(--border);border-radius:12px;background:var(--bg3);
    transition:border-color .15s,background .15s;position:relative;
}
.rs-dropzone:hover{border-color:rgba(0,115,230,.4);background:var(--bg4)}
.rs-dropzone input[type=file]{position:absolute;opacity:0;width:100%;height:100%;inset:0;cursor:pointer}
.rs-dropzone i.lucide{width:26px;height:26px;color:var(--primary);margin-bottom:2px}
.rs-dropzone strong{font-size:13px;font-weight:700;color:var(--text)}
.rs-dropzone span{font-size:11.5px;color:var(--text4)}

.rs-steps{display:grid;gap:14px}
.rs-step{display:flex;gap:12px;align-items:flex-start}
.rs-step-num{
    width:26px;height:26px;min-width:26px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#0f766e,#2563eb);color:#fff;
    font-size:12.5px;font-weight:800;
}
.rs-step strong{display:block;font-size:12.5px;font-weight:700;color:var(--text);margin-bottom:2px}
.rs-step p{margin:0;font-size:11.5px;color:var(--text4);line-height:1.55}

@media(max-width:560px){
    .rs-row{flex-wrap:wrap}
    .rs-row-actions{flex-direction:column;width:100%;min-width:0}
    .rs-row-icons{width:100%}
    .rs-hero{padding:22px 20px}
}
</style>

<!-- Hero -->
<div class="rs-hero fade-in">
    <div class="rs-hero-inner">
        <div class="rs-hero-icon"><i data-lucide="rotate-ccw"></i></div>
        <div class="rs-hero-text">
            <h2>Backup &amp; Restore</h2>
            <p>Restore files and databases from your saved backups, or upload an existing archive.</p>
        </div>
        <div class="rs-hero-actions">
            <a href="#upload" class="rs-btn rs-btn-ghost-white"><i data-lucide="upload-cloud"></i> Upload Backup</a>
            <a href="/cpanel/backups.php" class="rs-btn rs-btn-ghost-white"><i data-lucide="package"></i> Go to Backups</a>
        </div>
    </div>
    <div class="rs-hero-stats">
        <span class="rs-pill"><i data-lucide="archive"></i> <strong><?= count($backups) ?></strong> Backups</span>
        <span class="rs-pill"><i data-lucide="hard-drive"></i> <strong><?= format_size($total_size) ?></strong> Stored</span>
        <span class="rs-pill"><i data-lucide="database"></i> <strong><?= $db_count + $full_count ?></strong> DB Ready</span>
    </div>
</div>

<!-- Stats -->
<div class="rs-stats fade-in-delay-1">
    <div class="rs-stat">
        <div class="rs-stat-icon" style="background:var(--primary-light);color:var(--primary)"><i data-lucide="archive"></i></div>
        <div>
            <div class="rs-stat-num"><?= count($backups) ?></div>
            <div class="rs-stat-lbl">Total Backups</div>
        </div>
    </div>
    <div class="rs-stat">
        <div class="rs-stat-icon" style="background:rgba(13,148,136,.1);color:#0d9488"><i data-lucide="hard-drive"></i></div>
        <div>
            <div class="rs-stat-num"><?= format_size($total_size) ?></div>
            <div class="rs-stat-lbl">Total Size</div>
        </div>
    </div>
    <div class="rs-stat">
        <div class="rs-stat-icon" style="background:rgba(99,102,241,.1);color:#6366f1"><i data-lucide="folder"></i></div>
        <div>
            <div class="rs-stat-num"><?= $file_count + $full_count ?></div>
            <div class="rs-stat-lbl">File Backups</div>
        </div>
    </div>
    <div class="rs-stat">
        <div class="rs-stat-icon" style="background:rgba(5,150,105,.1);color:#059669"><i data-lucide="database"></i></div>
        <div>
            <div class="rs-stat-num"><?= $db_count + $full_count ?></div>
            <div class="rs-stat-lbl">Database Backups</div>
        </div>
    </div>
</div>

<div class="rs-grid fade-in-delay-2">
    <!-- Backup list -->
    <div class="rs-card">
        <div class="rs-card-head">
            <i data-lucide="archive"></i>
            <h3>Available Backups</h3>
            <span class="rs-count"><?= count($backups) ?></span>
        </div>
        <div class="rs-card-body">
            <?php
            $rs_avatars = [
                ['#0073e6', '#38bdf8'], ['#7c3aed', '#c084fc'], ['#059669', '#4ade80'],
                ['#0f766e', '#2dd4bf'], ['#2563eb', '#818cf8'], ['#db2777', '#f472b6'],
            ];
            function rs_avatar_gradient($str, $palette) {
                $h = 0;
                foreach (str_split($str) as $ch) { $h = ($h * 31 + ord($ch)) & 0x7fffffff; }
                $g = $palette[$h % count($palette)];
                return "linear-gradient(135deg, {$g[0]} 0%, {$g[1]} 100%)";
            }
            $rs_types = [
                'full'     => ['label' => 'Full Restore', 'cls' => 'rs-badge-blue',   'icon' => 'package',  'icbg' => 'rgba(0,115,230,.09)',   'ic' => 'var(--primary)'],
                'files'    => ['label' => 'Files',       'cls' => 'rs-badge-violet',  'icon' => 'folder',   'icbg' => 'rgba(99,102,241,.09)',  'ic' => '#6366f1'],
                'database' => ['label' => 'Database',    'cls' => 'rs-badge-green',   'icon' => 'database', 'icbg' => 'rgba(5,150,105,.09)',   'ic' => '#059669'],
            ];
            ?>
            <?php if (empty($backups)): ?>
                <div class="rs-empty">
                    <i data-lucide="archive"></i>
                    <p>No backups available yet. Create one in the Backups page or upload an existing archive.</p>
                    <div class="rs-empty-actions">
                        <a href="/cpanel/backups.php" class="rs-btn rs-btn-primary"><i data-lucide="package"></i> Create a Backup</a>
                        <a href="#upload" class="rs-btn rs-btn-ghost"><i data-lucide="upload-cloud"></i> Upload Existing</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($backups as $b):
                    $t = $rs_types[$b['type']] ?? $rs_types['full'];
                ?>
                <div class="rs-row">
                    <div class="rs-avatar" style="background:<?= rs_avatar_gradient($b['filename'], $rs_avatars) ?>"><i data-lucide="<?= h($t['icon']) ?>"></i></div>
                    <div class="rs-row-main">
                        <div class="rs-row-title">
                            <span class="rs-name"><?= h($b['filename']) ?></span>
                            <span class="rs-badge <?= $t['cls'] ?>"><i data-lucide="<?= h($t['icon']) ?>"></i> <?= h($t['label']) ?></span>
                        </div>
                        <div class="rs-row-meta">
                            <span class="rs-meta-item"><i data-lucide="hard-drive"></i> <?= format_size($b['size']) ?></span>
                            <span class="rs-meta-item"><i data-lucide="calendar"></i> <?= date('M d, Y H:i', strtotime($b['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="rs-row-actions">
                        <button type="button" class="rs-btn rs-btn-primary" onclick="showRestoreModal(<?= $b['id'] ?>, '<?= h($b['filename']) ?>', '<?= h($b['type']) ?>')">
                            <i data-lucide="rotate-ccw"></i> Restore
                        </button>
                        <div class="rs-row-icons">
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="detail">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="rs-btn-ico" title="View contents"><i data-lucide="eye"></i></button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="download">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="rs-btn-ico" title="Download"><i data-lucide="download"></i></button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this backup permanently?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="rs-btn-ico rs-btn-ico-danger" title="Delete"><i data-lucide="trash-2"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Side -->
    <div class="rs-side">
        <div class="rs-card" id="upload">
            <div class="rs-card-head">
                <i data-lucide="upload-cloud"></i>
                <h3>Upload Backup</h3>
            </div>
            <div class="rs-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <label class="rs-dropzone">
                        <input type="file" name="backup_file" accept=".tar.gz,.gz,.tgz" required>
                        <i data-lucide="file-archive"></i>
                        <strong>Drop or choose a .tar.gz file</strong>
                        <span>Archives from the Backups page or another server</span>
                    </label>
                    <button type="submit" class="rs-btn rs-btn-primary rs-btn-block"><i data-lucide="upload"></i> Upload Backup</button>
                </form>
            </div>
        </div>

        <div class="rs-card">
            <div class="rs-card-head">
                <i data-lucide="info"></i>
                <h3>How it works</h3>
            </div>
            <div class="rs-card-body">
                <div class="rs-steps">
                    <div class="rs-step">
                        <span class="rs-step-num">1</span>
                        <div><strong>Create a backup</strong><p>Head to the Backups page to generate a full or partial archive.</p></div>
                    </div>
                    <div class="rs-step">
                        <span class="rs-step-num">2</span>
                        <div><strong>Choose a restore mode</strong><p>Restore everything, only files, or only the database.</p></div>
                    </div>
                    <div class="rs-step">
                        <span class="rs-step-num">3</span>
                        <div><strong>Confirm &amp; wait</strong><p>Restoring overwrites current data and cannot be undone.</p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($detail_backup): ?>
<div class="rs-card fade-in-delay-2" style="margin-top:18px">
    <div class="rs-card-head">
        <i data-lucide="eye"></i>
        <h3>Backup Contents: <?= h($detail_backup['filename']) ?></h3>
        <span class="rs-badge <?= ($rs_types[$detail_backup['type']]['cls'] ?? 'rs-badge-blue') ?>">
            <i data-lucide="<?= h($rs_types[$detail_backup['type']]['icon'] ?? 'package') ?>"></i> <?= h(ucfirst($detail_backup['type'])) ?>
        </span>
    </div>
    <div class="rs-card-body">
        <?php if (empty($detail_files)): ?>
            <div class="rs-empty"><i data-lucide="file-archive"></i><p>No contents found or unable to read archive.</p></div>
        <?php else: ?>
            <div style="margin-bottom:12px;font-size:13px;color:var(--text3)"><?= count($detail_files) ?> item(s) in archive</div>
            <div style="max-height:400px;overflow-y:auto;background:var(--bg4);border-radius:var(--radius-sm);border:1px solid var(--border);padding:12px;font-family:monospace;font-size:12px;line-height:1.8;color:var(--text)">
                <?php foreach ($detail_files as $entry): ?>
                    <div style="border-bottom:1px solid var(--border);padding:2px 0;word-break:break-all">
                        <?php if (preg_match('/\.sql$/i', $entry)): ?>
                            <span style="color:#a78bfa">&#9670;</span> <?= h($entry) ?> <span class="rs-badge rs-badge-violet" style="font-size:9px;vertical-align:middle">SQL</span>
                        <?php elseif (substr($entry, -1) === '/'): ?>
                            <span style="color:#60a5fa">&#128193;</span> <?= h($entry) ?>
                        <?php else: ?>
                            <span style="color:var(--text3)">&#9672;</span> <?= h($entry) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div id="restoreModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:24px;max-width:460px;width:90%;box-shadow:var(--shadow-lg)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <div style="width:40px;height:40px;border-radius:10px;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center">
                <i data-lucide="alert-triangle" class="lucide" style="width:20px;height:20px;color:#ef4444"></i>
            </div>
            <div>
                <h3 style="margin:0;font-size:16px;color:var(--text)">Restore Backup</h3>
                <div style="font-size:12px;color:var(--text3)" id="restoreFileName"></div>
            </div>
        </div>

        <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:var(--radius-sm);padding:12px;margin-bottom:16px;font-size:13px;color:#ef4444;line-height:1.5">
            <strong>&#9888; Warning:</strong> This will overwrite existing files and/or database data. This action cannot be undone.
        </div>

        <div style="margin-bottom:20px">
            <label style="display:block;font-size:13px;font-weight:600;color:var(--text);margin-bottom:8px">Restore Mode:</label>
            <div style="display:flex;flex-direction:column;gap:8px">
                <label class="rs-restore-option" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
                    <input type="radio" name="restore_type" value="full" checked>
                    <div>
                        <div class="rs-opt-title">Full Restore</div>
                        <div class="rs-opt-sub">Restore files and database</div>
                    </div>
                </label>
                <label class="rs-restore-option" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
                    <input type="radio" name="restore_type" value="files">
                    <div>
                        <div class="rs-opt-title">Files Only</div>
                        <div class="rs-opt-sub">Restore files to home directory</div>
                    </div>
                </label>
                <label class="rs-restore-option" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
                    <input type="radio" name="restore_type" value="database">
                    <div>
                        <div class="rs-opt-title">Database Only</div>
                        <div class="rs-opt-sub">Import SQL dumps from backup</div>
                    </div>
                </label>
            </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" class="rs-btn" onclick="closeRestoreModal()" style="background:var(--bg3);color:var(--text)">Cancel</button>
            <form method="POST" id="restoreForm" style="display:inline">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="id" id="restoreId">
                <input type="hidden" name="restore_type" id="restoreTypeInput">
                <button type="submit" class="rs-btn rs-btn-danger-ghost" style="background:var(--danger);color:#fff;border-color:var(--danger)" onclick="document.getElementById('restoreTypeInput').value=document.querySelector('input[name=restore_type]:checked').value">
                    <i data-lucide="rotate-ccw" class="lucide"></i> Restore Now
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.rs-restore-option{
    display:flex;align-items:center;gap:10px;padding:10px 12px;
    border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;
    background:var(--bg3);transition:border-color .15s,background .15s;
}
.rs-restore-option:has(input:checked){border-color:var(--primary);background:var(--primary-light)}
.rs-restore-option input{accent-color:var(--primary)}
.rs-opt-title{font-size:13px;font-weight:600;color:var(--text)}
.rs-opt-sub{font-size:11px;color:var(--text3)}
</style>

<script>
function showRestoreModal(id, filename, type) {
    document.getElementById('restoreId').value = id;
    document.getElementById('restoreFileName').textContent = filename + ' (' + type + ')';
    document.getElementById('restoreModal').style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeRestoreModal() {
    document.getElementById('restoreModal').style.display = 'none';
}
document.getElementById('restoreModal').addEventListener('click', function(e) {
    if (e.target === this) closeRestoreModal();
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
