<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$home_dir = $user['home_dir'] ?? getenv('HOME');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $dir_path = trim($_POST['directory_path'] ?? '');
        $style = $_POST['style'] ?? 'default';
        $valid_styles = ['default', 'fancying', 'icons', 'none', 'description'];
        if (!in_array($style, $valid_styles)) $style = 'default';
        if (!empty($dir_path)) {
            $exists = $db->prepare("SELECT id FROM directory_indexes WHERE directory_path = ? AND user_id = ?");
            $exists->execute([$dir_path, $user_id]);
            if (!$exists->fetch()) {
                $db->prepare("INSERT INTO directory_indexes (user_id, directory_path, style) VALUES (?, ?, ?)")
                   ->execute([$user_id, $dir_path, $style]);
                write_htaccess_indexes($home_dir, $dir_path, $style, 'active');
                flash('success', "Index rule added for {$dir_path}");
            } else {
                flash('error', 'A rule for this directory already exists');
            }
        } else {
            flash('error', 'Directory path is required');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM directory_indexes WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rule) {
            $new_status = $rule['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE directory_indexes SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            if ($new_status === 'active') {
                write_htaccess_indexes($home_dir, $rule['directory_path'], $rule['style'], $new_status);
            } else {
                remove_htaccess_indexes($home_dir, $rule['directory_path']);
            }
            flash('success', "Rule {$new_status}");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM directory_indexes WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rule) {
            remove_htaccess_indexes($home_dir, $rule['directory_path']);
            $db->prepare("DELETE FROM directory_indexes WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
            flash('success', 'Rule deleted');
        }
    }
    redirect('/cpanel/indexes.php');
}

function write_htaccess_indexes($home_dir, $dir_path, $style, $status) {
    $full_path = rtrim($home_dir, '/') . '/' . ltrim($dir_path, '/');
    if (!is_dir($full_path)) {
        @mkdir($full_path, 0755, true);
    }
    $htaccess = $full_path . '/.htaccess';
    $lines = [];
    if (file_exists($htaccess)) {
        $content = file_get_contents($htaccess);
        $lines = preg_split('/\r?\n/', $content);
    }
    $cleaned = [];
    $skip = false;
    foreach ($lines as $line) {
        if (preg_match('/^# ZRPanel Index Start$/i', $line)) { $skip = true; continue; }
        if (preg_match('/^# ZRPanel Index End$/i', $line)) { $skip = false; continue; }
        if (!$skip) $cleaned[] = $line;
    }
    $directives = ['# ZRPanel Index Start'];
    $directives[] = 'Options +Indexes';
    $directives[] = 'IndexOptions';
    switch ($style) {
        case 'fancying':
            $directives[] = 'IndexOptions FancyIndexing VersionSort HTMLTable NameWidth=* DescriptionWidth=*';
            break;
        case 'icons':
            $directives[] = 'IndexOptions FancyIndexing VersionSort';
            $directives[] = 'IndexIgnore .htaccess';
            break;
        case 'none':
            $cleaned[] = '# ZRPanel Index Start';
            $cleaned[] = 'Options -Indexes';
            $cleaned[] = '# ZRPanel Index End';
            file_put_contents($htaccess, implode("\n", $cleaned) . "\n");
            return;
        case 'description':
            $directives[] = 'IndexOptions FancyIndexing VersionSort HTMLTable NameWidth=* DescriptionWidth=*';
            break;
        default:
            $directives[] = 'IndexOptions VersionSort';
            break;
    }
    $directives[] = '# ZRPanel Index End';
    $result = array_merge($cleaned, $directives);
    file_put_contents($htaccess, implode("\n", array_filter($result, function($l) { return $l !== ''; })) . "\n");
}

function remove_htaccess_indexes($home_dir, $dir_path) {
    $full_path = rtrim($home_dir, '/') . '/' . ltrim($dir_path, '/');
    $htaccess = $full_path . '/.htaccess';
    if (!file_exists($htaccess)) return;
    $lines = file($htaccess, FILE_IGNORE_NEW_LINES);
    $cleaned = [];
    $skip = false;
    foreach ($lines as $line) {
        if (preg_match('/^# ZRPanel Index Start$/i', $line)) { $skip = true; continue; }
        if (preg_match('/^# ZRPanel Index End$/i', $line)) { $skip = false; continue; }
        if (!$skip) $cleaned[] = $line;
    }
    file_put_contents($htaccess, implode("\n", array_filter($cleaned, function($l) { return $l !== ''; })) . "\n");
}

$rules = $db->prepare("SELECT * FROM directory_indexes WHERE user_id = ? ORDER BY created_at DESC");
$rules->execute([$user_id]);
$rules = $rules->fetchAll(PDO::FETCH_ASSOC);

$style_descriptions = [
    'default'     => 'Server default (follows global Apache config)',
    'fancying'    => 'Fancy indexing with description',
    'icons'       => 'Fancy indexing with icons',
    'none'        => 'No directory listing',
    'description' => 'Show file descriptions',
];
$style_icons = [
    'default'     => 'layout-grid',
    'fancying'    => 'list',
    'icons'       => 'image',
    'none'        => 'eye-off',
    'description' => 'file-text',
];
$style_directives = [
    'default'     => 'Options +Indexes',
    'fancying'    => 'IndexOptions FancyIndexing',
    'icons'       => 'IndexOptions FancyIndexing VersionSort',
    'none'        => 'Options -Indexes',
    'description' => 'IndexOptions DescriptionWidth=*',
];

$active_rules = 0;
$disabled_rules = 0;
$protected_rules = 0;
foreach ($rules as $r) {
    if ($r['status'] === 'active') {
        $active_rules++;
    } else {
        $disabled_rules++;
    }
    if ($r['style'] === 'none') {
        $protected_rules++;
    }
}

$nav = 'indexes';
$page_title = 'Indexes';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="grid" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Indexes</div>
        <div class="hero-desc">Control how Apache displays directory contents when no index file is present. Disable listing on sensitive folders to keep files private.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><i data-lucide="folder-cog" class="lucide"></i> <?= count($rules) ?> rule<?= count($rules) === 1 ? '' : 's' ?></span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="play" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_rules ?></div>
            <div class="stat-label">Active Rules</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">applied to directories</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="pause" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $disabled_rules ?></div>
            <div class="stat-label">Disabled Rules</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">saved but not applied</div>
        </div>
    </div>
    <div class="stat-card stat-red fade-in">
        <div class="stat-icon"><i data-lucide="eye-off" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $protected_rules ?></div>
            <div class="stat-label">Listing Disabled</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">403 to directory visitors</div>
        </div>
    </div>
</div>

<div class="tip-card tip-orange fade-in-delay-1">
    <i data-lucide="shield-alert" class="lucide"></i>
    <div class="tip-body"><strong>Security tip.</strong> Use the <em>No listing</em> style for backup or private folders — it returns 403 instead of exposing your files to visitors.</div>
</div>

<div class="card fade-in">
    <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Add Directory Index Rule</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Directory Path <span style="color:var(--danger)">*</span></label>
                <input type="text" id="idxPath" name="directory_path" required placeholder="e.g. public_html/images" value="public_html">
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Path relative to your home directory (e.g. <code>public_html/assets</code>)</div>
            </div>
            <div class="idx-chips">
                <span class="idx-chip-label">Quick add</span>
                <button type="button" class="btn btn-sm btn-ghost idx-chip" data-path="public_html">public_html</button>
                <button type="button" class="btn btn-sm btn-ghost idx-chip" data-path="public_html/uploads">public_html/uploads</button>
                <button type="button" class="btn btn-sm btn-ghost idx-chip" data-path="public_html/backups">public_html/backups</button>
            </div>

            <div class="form-group" style="margin-top:18px">
                <label>Index Style</label>
                <div class="idx-styles">
                    <?php foreach ($style_descriptions as $val => $desc): ?>
                    <label class="idx-style">
                        <input type="radio" name="style" value="<?= h($val) ?>" <?= $val === 'default' ? 'checked' : '' ?>>
                        <span class="idx-tile">
                            <span class="idx-tile-ic"><i data-lucide="<?= $style_icons[$val] ?>" class="lucide"></i></span>
                            <span class="idx-tile-row">
                                <span class="idx-tile-name"><?= h(ucfirst($val)) ?></span>
                                <code class="idx-tile-dir"><?= h($style_directives[$val]) ?></code>
                            </span>
                            <span class="idx-tile-desc"><?= h($desc) ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-hint"><i data-lucide="eye" class="lucide"></i> Determines how files are listed to visitors</div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:16px">
                <button type="submit" class="btn btn-primary"><i data-lucide="plus" class="lucide"></i> Add Rule</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> Directory Index Rules (<?= count($rules) ?>)</h3></div>
    <?php if (!empty($rules)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="idxSearch" placeholder="Search directories..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="idxCount"><?= count($rules) ?> rule<?= count($rules) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($rules) ? '14px' : '16px' ?>">
        <?php if (empty($rules)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="folder-search" class="lucide"></i></div>
                <strong>No index rules yet</strong>
                <p>Add a rule above to control how directory contents are displayed to visitors.</p>
            </div>
        <?php else: ?>
        <div class="idx-list">
            <?php foreach ($rules as $r): ?>
                <div class="idx-row" data-name="<?= h($r['directory_path']) ?>">
                    <span class="idx-ic <?= $r['style'] === 'none' ? 'is-red' : '' ?>"><i data-lucide="folder" class="lucide"></i></span>
                    <div class="idx-main">
                        <div class="idx-name">/<?= h($r['directory_path']) ?></div>
                        <div class="idx-meta">
                            <span class="badge <?= $r['style'] === 'none' ? 'badge-suspended' : 'badge-blue' ?>"><?= h(ucfirst($r['style'])) ?></span>
                            <span class="idx-status <?= $r['status'] === 'active' ? 'is-active' : 'is-off' ?>"><span class="dot"></span> <?= h(ucfirst($r['status'])) ?></span>
                            <span>Created <?= date('M d, Y', strtotime($r['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="idx-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $r['status'] === 'active' ? 'btn-warning' : 'btn-success' ?>"><i data-lucide="<?= $r['status'] === 'active' ? 'pause' : 'play' ?>" class="lucide"></i> <?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this index rule? The .htaccess directive will be removed.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
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
.stat-red .stat-icon{background:var(--danger-light);color:var(--danger);border:1px solid var(--danger-border)}
.idx-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
.idx-chip-label{font-size:11px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px}
.idx-styles{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,185px),1fr));gap:10px}
.idx-style{position:relative;cursor:pointer}
.idx-style input{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:1}
.idx-tile{display:flex;flex-direction:column;gap:7px;height:100%;padding:12px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-sm);transition:border-color .15s,box-shadow .15s,transform .15s}
.idx-style:hover .idx-tile{border-color:var(--text4);transform:translateY(-1px)}
.idx-style input:checked + .idx-tile{border-color:var(--primary);background:var(--primary-light);box-shadow:0 0 0 2px rgba(0,115,230,.12)}
.idx-tile-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:var(--bg3);border:1px solid var(--border);color:var(--text3)}
.idx-style input:checked + .idx-tile .idx-tile-ic{background:var(--primary);border-color:var(--primary);color:#fff}
.idx-tile-ic .lucide{width:15px;height:15px}
.idx-tile-row{display:flex;align-items:center;justify-content:space-between;gap:6px}
.idx-tile-name{font-weight:700;color:var(--text);font-size:13px}
.idx-tile-dir{font-size:10px;font-family:'Fira Code',monaco,consolas,monospace;color:var(--text4);background:var(--bg3);border:1px solid var(--border);padding:2px 6px;border-radius:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:55%}
.idx-tile-desc{font-size:11px;color:var(--text4);line-height:1.45}
.idx-list{display:flex;flex-direction:column;gap:10px}
.idx-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.idx-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.idx-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0062bd,#0073e6 55%,#1e8ff0);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.idx-ic.is-red{background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444);box-shadow:0 4px 10px rgba(220,38,38,.18)}
.idx-ic .lucide{width:19px;height:19px}
.idx-main{min-width:0;flex:1}
.idx-name{font-weight:600;color:var(--text);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.idx-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:3px}
.idx-meta .badge{font-size:10px;padding:3px 8px}
.idx-status{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700}
.idx-status .dot{width:7px;height:7px;border-radius:50%}
.idx-status.is-active{color:var(--success)}
.idx-status.is-active .dot{background:var(--success);box-shadow:0 0 5px rgba(5,150,105,.45)}
.idx-status.is-off{color:var(--text4)}
.idx-status.is-off .dot{background:var(--text4)}
.idx-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:640px){
  .idx-row{flex-wrap:wrap}
  .idx-main{flex-basis:100%}
  .idx-actions{margin-left:0;width:100%}
  .idx-actions form{flex:1}
  .idx-actions .btn{width:100%}
}
</style>

<script>
(function () {
    var input = document.getElementById('idxSearch');
    var count = document.getElementById('idxCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.idx-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' rule' + (shown === 1 ? '' : 's');
        });
    }
    var pathInput = document.getElementById('idxPath');
    document.querySelectorAll('.idx-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            if (pathInput) pathInput.value = c.getAttribute('data-path');
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
