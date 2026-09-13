<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_editor');
init_db();
$db = db();

define('SFM_ROOT', '/');
define('SFM_DEFAULT', '/storage/emulated/0/Download/hosting');
define('SFM_MAX_EDIT', 2097152);

$current = realpath($_GET['path'] ?? SFM_DEFAULT) ?: SFM_DEFAULT;
if (strpos($current . '/', SFM_ROOT) !== 0) $current = SFM_ROOT;
if (!is_dir($current)) $current = dirname($current);

$sfm_message = $_SESSION['sfm_message'] ?? '';
$_SESSION['sfm_message'] = '';

function sfm_rel($path, $root) {
    if ($path === $root) return '';
    return substr($path, strlen(rtrim($root, '/')));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $file = realpath($_POST['file'] ?? '');
        if ($file && strpos($file . '/', SFM_ROOT) === 0 && is_file($file)) {
            if (filesize($file) <= SFM_MAX_EDIT) {
                file_put_contents($file, $_POST['content'] ?? '');
                $sfm_message = "Saved: " . basename($file);
            } else {
                $sfm_message = "File too large to save";
            }
        }
    } elseif ($act === 'create') {
        $name = trim($_POST['create_name'] ?? '');
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            $type = ($_POST['create_type'] ?? 'file') === 'folder' ? 'folder' : 'file';
            $full = $current . '/' . $name;
            if ($type === 'folder') {
                if (!file_exists($full)) { mkdir($full, 0755); $sfm_message = "Created folder: $name"; }
                else $sfm_message = "Folder already exists";
            } else {
                if (!file_exists($full)) { file_put_contents($full, ''); $sfm_message = "Created file: $name"; }
                else $sfm_message = "File already exists";
            }
        } else {
            $sfm_message = "Invalid name";
        }
    } elseif ($act === 'rename') {
        $old = trim($_POST['old_name'] ?? '');
        $new = trim($_POST['new_name'] ?? '');
        if ($old && $new && $old !== $new) {
            if (rename($current . '/' . $old, $current . '/' . $new)) $sfm_message = "Renamed: $old → $new";
            else $sfm_message = "Rename failed";
        }
    } elseif ($act === 'delete') {
        $targets = is_array($_POST['targets'] ?? null) ? $_POST['targets'] : [$_POST['targets'] ?? ''];
        $deleted = 0;
        foreach ($targets as $t) {
            $full = realpath($current . '/' . $t);
            if ($full && strpos($full . '/', SFM_ROOT) === 0 && $full !== '/') {
                is_dir($full) ? exec("rm -rf " . escapeshellarg($full)) : unlink($full);
                $deleted++;
            }
        }
        $sfm_message = "Deleted $deleted item(s)";
    }

    $_SESSION['sfm_message'] = $sfm_message;
    header("Location: /whm/editor?path=" . urlencode(sfm_rel($current, SFM_ROOT)));
    exit;
}

if (isset($_GET['download'])) {
    $dl = realpath(SFM_ROOT . ltrim($_GET['download'], '/'));
    if ($dl && strpos($dl . '/', SFM_ROOT) === 0 && is_file($dl)) {
        header('Content-Disposition: attachment; filename="' . basename($dl) . '"');
        readfile($dl);
        exit;
    }
}

$edit_file = null;
$edit_content = '';
$edit_path = '';
if (isset($_GET['edit'])) {
    $ef = realpath(SFM_ROOT . ltrim($_GET['edit'], '/'));
    if ($ef && strpos($ef . '/', SFM_ROOT) === 0 && is_file($ef) && filesize($ef) <= SFM_MAX_EDIT) {
        $edit_file = $ef;
        $edit_path = $_GET['edit'];
        $edit_content = file_get_contents($ef);
    }
}

$sfm_items = [];
$total_size = 0; $folder_count = 0; $file_count = 0;
if (is_dir($current)) {
    $handle = @opendir($current);
    if ($handle !== false) {
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $fp = $current . '/' . $entry;
            if (!is_readable($fp)) continue;
            $is_dir = is_dir($fp);
            $size = $is_dir ? 0 : (int) @filesize($fp);
            $total_size += $size;
            $is_dir ? $folder_count++ : $file_count++;
            $sfm_items[] = [
                'name' => $entry, 'is_dir' => $is_dir, 'size' => $size,
                'mtime' => @filemtime($fp), 'ext' => strtolower(pathinfo($entry, PATHINFO_EXTENSION))
            ];
        }
        closedir($handle);
    } else {
        $sfm_message = "Unable to list this directory (permission denied).";
    }
}
usort($sfm_items, fn($a, $b) => $b['is_dir'] <=> $a['is_dir'] ?: strcasecmp($a['name'], $b['name']));

$rel_current = sfm_rel($current, SFM_ROOT);
$nav = 'server';
$page_title = 'Secret File Manager';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.sfm-wrap{max-width:1200px;margin:0 auto}
.sfm-wrap .card{margin-bottom:16px}
.sfm-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;background:linear-gradient(135deg,#f8fafc,#fff);border-bottom:1px solid var(--border)}
.sfm-path-input{flex:1;min-width:220px;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;font-family:monospace;background:var(--bg);color:var(--text)}
.sfm-breadcrumb{display:flex;align-items:center;gap:2px;font-size:12px;font-weight:500;flex-wrap:wrap;min-width:0}
.sfm-breadcrumb a{color:var(--text2);padding:2px 5px;border-radius:5px}
.sfm-breadcrumb a:hover{background:var(--primary-light);color:var(--cpanel-blue)}
.sfm-breadcrumb .lucide{width:12px;height:12px;color:var(--text4);flex-shrink:0}
.sfm-list{display:flex;flex-direction:column;gap:4px;padding:10px}
.sfm-item{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:10px;border:1px solid transparent;transition:background .12s,border-color .12s}
.sfm-item:hover{background:rgba(0,115,230,.04);border-color:rgba(0,115,230,.1)}
.sfm-item-icon{display:flex;align-items:center;justify-content:center;width:36px;height:36px;min-width:36px;border-radius:9px;text-decoration:none}
.sfm-item-icon .lucide{width:16px;height:16px}
.sfm-item-name{flex:1;min-width:0;font-weight:500;font-size:13px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none}
a.sfm-item-name:hover{color:var(--cpanel-blue)}
.sfm-item-meta{display:flex;align-items:center;gap:12px;flex-shrink:0;font-size:11.5px;color:var(--text4)}
.sfm-item-actions{display:flex;gap:2px;flex-shrink:0;opacity:.7;transition:opacity .15s}
.sfm-item:hover .sfm-item-actions{opacity:1}
.sfm-editor{width:100%;min-height:520px;border:none;border-radius:0;background:#0d1117;color:#c9d1d9;font-family:'JetBrains Mono',Consolas,monospace;font-size:13px;line-height:1.7;padding:18px;resize:vertical;outline:none;white-space:pre;overflow:auto}
.sfm-empty{text-align:center;padding:56px 20px}
.sfm-empty p{color:var(--text3);font-size:13px;margin:0}
.sfm-dir{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700;background:var(--warning-light);color:var(--warning);border:1px solid var(--warning-border)}
.sfm-ext{display:inline-block;padding:2px 7px;border-radius:5px;font-size:9px;font-weight:700;background:var(--bg4);color:var(--text3);margin-right:8px}
@media(max-width:768px){
  .sfm-item-meta{display:none}
  .sfm-item{padding:8px 10px;gap:8px}
  .sfm-item-actions{opacity:1}
}
</style>

<div class="sfm-wrap">

<?php if ($sfm_message): ?>
<?php $is_err = stripos($sfm_message, 'fail') !== false || stripos($sfm_message, 'invalid') !== false || stripos($sfm_message, 'already') !== false || stripos($sfm_message, 'large') !== false; ?>
<div class="alert <?= $is_err ? 'alert-danger' : 'alert-success' ?>" style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;<?= $is_err ? 'background:rgba(231,76,60,.1);color:#e74c3c;border:1px solid rgba(231,76,60,.3)' : '' ?>">
    <i data-lucide="<?= $is_err ? 'alert-circle' : 'check-circle' ?>" class="lucide" style="width:16px;height:16px;flex-shrink:0"></i>
    <?= h($sfm_message) ?>
</div>
<?php endif; ?>

<?php if ($edit_file): ?>

<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="file-code" class="lucide"></i> <?= h(basename($edit_file)) ?> <span style="font-weight:400;font-size:11px;color:var(--text3)">(<?= h($edit_path) ?>)</span></h3>
        <div class="header-actions">
            <a href="/whm/editor?path=<?= urlencode($rel_current) ?>" class="btn btn-sm btn-ghost"><i data-lucide="arrow-left" class="lucide"></i> Back</a>
            <button type="submit" form="sfmSaveForm" class="btn btn-sm btn-primary"><i data-lucide="save" class="lucide"></i> Save</button>
        </div>
    </div>
    <div class="card-body" style="padding:0">
        <form method="POST" id="sfmSaveForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="file" value="<?= h($edit_file) ?>">
            <textarea name="content" class="sfm-editor" spellcheck="false"><?= h($edit_content) ?></textarea>
        </form>
    </div>
</div>

<?php else: ?>

<div class="card fade-in">
    <div class="sfm-bar">
        <div class="sfm-breadcrumb" style="flex:1;min-width:0">
            <i data-lucide="folder-open" class="lucide"></i>
            <?php
            $crumbs = $current === '/' ? [] : array_filter(explode('/', $rel_current));
            $acc = '';
            echo '<a href="/whm/editor?path=">/</a>';
            foreach ($crumbs as $c) {
                $acc .= '/' . $c;
                echo '<i data-lucide="chevron-right" class="lucide"></i><a href="/whm/editor?path=' . urlencode($acc) . '">' . h($c) . '</a>';
            }
            ?>
        </div>
        <form method="GET" style="display:flex;gap:8px;flex:1">
            <input type="text" name="path" value="<?= h($current) ?>" class="sfm-path-input" placeholder="/absolute/path/to/directory">
            <button type="submit" class="btn btn-sm btn-info">Go</button>
        </form>
    </div>
    <div class="card-body" style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid var(--border)">
        <div style="display:flex;gap:12px;font-size:12px;color:var(--text3);font-weight:600;flex-wrap:wrap">
            <span><i data-lucide="folder" class="lucide" style="width:12px;height:12px;color:var(--warning)"></i> <?= $folder_count ?> folders</span>
            <span><i data-lucide="file" class="lucide" style="width:12px;height:12px"></i> <?= $file_count ?> files</span>
            <span><i data-lucide="hard-drive" class="lucide" style="width:12px;height:12px"></i> <?= format_size($total_size) ?></span>
        </div>
        <button class="btn btn-sm btn-primary" onclick="document.getElementById('sfmCreateModal').classList.add('open')"><i data-lucide="plus" class="lucide"></i> New</button>
    </div>
    <div class="sfm-list">
        <?php if ($current !== '/'): ?>
            <a href="/whm/editor?path=<?= urlencode(dirname($rel_current) === '' ? '/' : dirname($rel_current)) ?>" class="sfm-item">
                <div class="sfm-item-icon" style="background:var(--bg3);color:var(--text3)"><i data-lucide="arrow-left" class="lucide"></i></div>
                <span class="sfm-item-name">.. (parent)</span>
            </a>
        <?php endif; ?>
        <?php if (empty($sfm_items)): ?>
            <div class="sfm-empty"><p>This directory is empty.</p></div>
        <?php else: foreach ($sfm_items as $sfm_item):
            $fp_rel = ($rel_current ? $rel_current . '/' : '') . $sfm_item['name'];
            if ($sfm_item['is_dir']) { $icon_cls = 'icon-folder'; $icon_name = 'folder'; }
            elseif (in_array($sfm_item['ext'], ['php','phtml'])) { $icon_cls = 'icon-php'; $icon_name = 'file-code'; }
            elseif (in_array($sfm_item['ext'], ['html','htm','css','scss','less'])) { $icon_cls = 'icon-web'; $icon_name = 'file-code'; }
            elseif (in_array($sfm_item['ext'], ['js','json','ts','jsx','tsx','c','cpp','h','py','rb','sh','yml','yaml','xml','ini','conf','env','txt','md','sql','log'])) { $icon_cls = 'icon-code'; $icon_name = 'file-code'; }
            elseif (in_array($sfm_item['ext'], ['jpg','jpeg','png','gif','svg','webp','ico','bmp'])) { $icon_cls = 'icon-img'; $icon_name = 'image'; }
            elseif (in_array($sfm_item['ext'], ['zip','gz','tar','rar','7z','tgz'])) { $icon_cls = 'icon-data'; $icon_name = 'archive'; }
            else { $icon_cls = 'icon-file'; $icon_name = 'file'; }
        ?>
        <div class="sfm-item">
            <?php if ($sfm_item['is_dir']): ?>
                <a href="/whm/editor?path=<?= urlencode($fp_rel) ?>" class="sfm-item-icon <?= $icon_cls ?>"><i data-lucide="<?= $icon_name ?>" class="lucide"></i></a>
                <a href="/whm/editor?path=<?= urlencode($fp_rel) ?>" class="sfm-item-name"><?= h($sfm_item['name']) ?></a>
            <?php else: ?>
                <span class="sfm-item-icon <?= $icon_cls ?>"><i data-lucide="<?= $icon_name ?>" class="lucide"></i></span>
                <span class="sfm-item-name"><?= h($sfm_item['name']) ?></span>
            <?php endif; ?>
            <div class="sfm-item-meta">
                <span class="sfm-item-size"><?= $sfm_item['is_dir'] ? '<span class="sfm-dir">DIR</span>' : '<span class="sfm-ext">' . h(strtoupper($sfm_item['ext'] ?: '?')) . '</span>' . format_size($sfm_item['size']) ?></span>
                <span><?= date('M d, Y H:i', $sfm_item['mtime']) ?></span>
            </div>
            <div class="sfm-item-actions">
                <?php if (!$sfm_item['is_dir']): ?>
                    <a href="/whm/editor?edit=<?= urlencode($fp_rel) ?>&path=<?= urlencode($rel_current) ?>" class="btn-icon" title="Edit"><i data-lucide="file-edit" class="lucide"></i></a>
                    <a href="/whm/editor?download=<?= urlencode($fp_rel) ?>" class="btn-icon" title="Download"><i data-lucide="download" class="lucide"></i></a>
                <?php endif; ?>
                <button class="btn-icon" title="Rename" onclick="sfmRename('<?= h(addslashes($sfm_item['name'])) ?>')"><i data-lucide="pencil" class="lucide"></i></button>
                <button class="btn-icon btn-icon-danger" title="Delete" onclick="sfmDelete('<?= h(addslashes($sfm_item['name'])) ?>')"><i data-lucide="trash-2" class="lucide"></i></button>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php endif; ?>
</div>

<!-- Create Modal -->
<div class="modal-overlay" id="sfmCreateModal">
    <div class="modal-box">
        <div class="modal-top">
            <h3><i data-lucide="plus-circle" class="lucide"></i> Create New</h3>
            <button class="modal-x" onclick="this.closest('.modal-overlay').classList.remove('open')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <div class="form-group" style="margin-bottom:12px">
                    <label>Type</label>
                    <select name="create_type" class="form-control" style="width:100%;padding:10px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:13px">
                        <option value="file">File</option>
                        <option value="folder">Folder</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label>Name</label>
                    <input type="text" name="create_name" required placeholder="e.g. index.php" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:13px">
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('sfmCreateModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="check" class="lucide"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Rename Modal -->
<div class="modal-overlay" id="sfmRenameModal">
    <div class="modal-box">
        <div class="modal-top">
            <h3><i data-lucide="pencil" class="lucide"></i> Rename</h3>
            <button class="modal-x" onclick="document.getElementById('sfmRenameModal').classList.remove('open')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="old_name" id="sfmOldName">
                <div class="form-group" style="margin:0">
                    <label>New Name</label>
                    <input type="text" name="new_name" id="sfmNewName" required style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:13px">
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('sfmRenameModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="check" class="lucide"></i> Rename</button>
            </div>
        </form>
    </div>
</div>

<script>
function sfmRename(name) {
    document.getElementById('sfmOldName').value = name;
    document.getElementById('sfmNewName').value = name;
    document.getElementById('sfmRenameModal').classList.add('open');
}
function sfmDelete(name) {
    if (!confirm('Delete "' + name + '"?')) return;
    var f = document.createElement('form'); f.method = 'POST';
    f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="targets[]" value="' + name + '">';
    document.body.appendChild(f); f.submit();
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
