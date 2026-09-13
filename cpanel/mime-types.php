<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $ext = strtolower(trim($_POST['extension'] ?? ''));
        $mime = trim($_POST['mime_type'] ?? '');
        if (!empty($ext) && !empty($mime)) {
            $exists = $db->prepare("SELECT id FROM mime_types WHERE extension = ? AND user_id = ?");
            $exists->execute([$ext, $user_id]);
            if (!$exists->fetch()) {
                $db->prepare("INSERT INTO mime_types (user_id, extension, mime_type) VALUES (?, ?, ?)")
                   ->execute([$user_id, $ext, $mime]);
                flash('success', "MIME type for .{$ext} added");
            } else {
                flash('error', 'Extension already registered');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM mime_types WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'MIME type deleted');
    }
    redirect('/cpanel/mime-types.php');
}

$types = $db->prepare("SELECT * FROM mime_types WHERE user_id = ? ORDER BY extension");
$types->execute([$user_id]);
$types = $types->fetchAll(PDO::FETCH_ASSOC);

$media_count = 0;
$data_count = 0;
foreach ($types as $t) {
    if (preg_match('#^(image|video|audio|font)/#i', $t['mime_type'])) $media_count++;
    elseif (preg_match('#^(application|text)/#i', $t['mime_type'])) $data_count++;
}

$nav = 'mimetypes';
$page_title = 'MIME Types';
require_once __DIR__ . '/../templates/header.php';

$common_mimes = [
    'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
    'js' => 'application/javascript', 'json' => 'application/json',
    'xml' => 'application/xml', 'txt' => 'text/plain', 'md' => 'text/markdown',
    'pdf' => 'application/pdf', 'zip' => 'application/zip',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    'ico' => 'image/x-icon', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
    'webm' => 'video/webm', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff',
];
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="file" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">MIME Types</div>
        <div class="hero-desc">Register custom file-extension to Content-Type mappings so Apache serves matching files correctly.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-blue" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="file-text" class="lucide"></i> <?= count($types) ?> type<?= count($types) === 1 ? '' : 's' ?></span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="file" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($types) ?></div>
            <div class="stat-label">Registered Types</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">extension &rarr; MIME pairings</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="image" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $media_count ?></div>
            <div class="stat-label">Media Formats</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">image, video, audio, fonts</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="file-text" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $data_count ?></div>
            <div class="stat-label">Data &amp; Text</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">application, json, text docs</div>
        </div>
    </div>
</div>

<div class="grid-2 fade-in-delay-2">
    <div class="card">
        <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Add MIME Type</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>Extension</label>
                    <div style="display:flex;gap:0">
                        <input type="text" name="extension" id="mtExt" required placeholder="e.g. webp" style="border-radius:var(--radius-sm) 0 0 var(--radius-sm);flex:1">
                        <span style="display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-left:0;border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-size:13px;color:var(--text3);white-space:nowrap">.ext</span>
                    </div>
                    <div class="mt-preview">
                        <span style="font-size:11px;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;font-weight:700">Will register</span>
                        <code id="mtPrev">.webp</code>
                    </div>
                    <div class="form-hint" style="margin-top:8px"><i data-lucide="info" class="lucide"></i> Enter the extension without the leading dot &mdash; it is added automatically.</div>
                </div>
                <div class="form-group" style="margin-top:16px">
                    <label>MIME Type</label>
                    <input type="text" name="mime_type" id="mtType" required placeholder="e.g. image/webp" list="mime-suggestions">
                    <datalist id="mime-suggestions">
                        <?php foreach ($common_mimes as $e => $m): ?>
                            <option value="<?= h($m) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-hint"><i data-lucide="type" class="lucide"></i> Use the standard <code>type/subtype</code> format, e.g. <code>image/webp</code></div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px"><i data-lucide="plus" class="lucide"></i> Add Type</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i data-lucide="info" class="lucide"></i> How It Works</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;gap:12px;font-size:13px">
                <i data-lucide="file-text" class="lucide" style="width:16px;height:16px;color:var(--primary);flex-shrink:0"></i>
                <span style="color:var(--text2)">When a file with a registered extension is requested, Apache sends it with the matching <em>Content-Type</em> header.</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;font-size:13px">
                <i data-lucide="download" class="lucide" style="width:16px;height:16px;color:var(--success);flex-shrink:0"></i>
                <span style="color:var(--text2)">A wrong type can break in-browser viewing or downloads &mdash; e.g. serving <code>SVG</code> as plain text.</span>
            </div>
            <div style="padding-top:12px;border-top:1px dashed var(--border)">
                <div style="font-size:11px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Common &mdash; already recognized</div>
                <div class="mt-ref-grid">
                    <?php $ci = 0; foreach ($common_mimes as $e => $m): if ($ci >= 6) break; $ci++; ?>
                        <span class="mt-ref"><code>.<?= h($e) ?></code><code><?= h($m) ?></code></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> Custom MIME Types (<?= count($types) ?>)</h3></div>
    <?php if (!empty($types)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="mtSearch" placeholder="Search extensions or types..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="mtCount"><?= count($types) ?> type<?= count($types) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($types) ? '14px' : '16px' ?>">
        <?php if (empty($types)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="file" class="lucide"></i></div>
                <strong>No custom MIME types</strong>
                <p>Add your first mapping above so uncommon file extensions are served with the right Content-Type.</p>
            </div>
        <?php else: ?>
        <div class="mt-list">
            <?php foreach ($types as $t): ?>
                <div class="mt-row" data-name="<?= h(strtolower($t['extension'] . ' ' . $t['mime_type'])) ?>">
                    <span class="mt-ic"><i data-lucide="file-text" class="lucide"></i></span>
                    <div class="mt-main">
                        <div class="mt-name">.<?= h($t['extension']) ?></div>
                        <div class="mt-meta">
                            <code class="chip-mono"><?= h($t['mime_type']) ?></code>
                            <button type="button" class="mt-copy" data-copy="<?= h($t['mime_type']) ?>" title="Copy MIME type"><i data-lucide="copy" class="lucide"></i></button>
                            <span>Added automatically</span>
                        </div>
                    </div>
                    <div class="mt-actions">
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this MIME type?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= h($t['id']) ?>">
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
.mt-preview{display:flex;align-items:center;gap:10px;margin-top:10px;background:var(--bg2);border:1px dashed var(--border);border-radius:var(--radius-sm);padding:8px 10px}
.mt-preview code{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mt-ref-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr));gap:6px}
.mt-ref{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 9px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-xs)}
.mt-ref code{font-size:10.5px;font-family:'Fira Code',monaco,consolas,monospace;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mt-ref code:first-child{font-weight:700;color:var(--text)}
.mt-list{display:flex;flex-direction:column;gap:10px}
.mt-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.mt-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.mt-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0059b3,#0073e6 55%,#1e8ff0);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.mt-ic .lucide{width:19px;height:19px}
.mt-main{min-width:0;flex:1}
.mt-name{font-weight:700;color:var(--text);font-size:14px;font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mt-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.mt-copy{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.mt-copy .lucide{width:12px;height:12px}
.mt-copy:hover{background:rgba(0,115,230,.1);color:var(--primary)}
.mt-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.mt-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:720px){
  .mt-row{flex-wrap:wrap}
  .mt-main{flex-basis:100%}
  .mt-actions{width:100%;justify-content:flex-end}
}
</style>

<script>
(function () {
    var extInput = document.getElementById('mtExt');
    var prev = document.getElementById('mtPrev');
    if (extInput && prev) {
        var show = function () {
            var v = (extInput.value || '').trim().replace(/^\./, '');
            prev.textContent = '.' + (v || 'webp');
        };
        extInput.addEventListener('input', show);
        show();
    }
    var q = document.getElementById('mtSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.mt-row'));
        var c = document.getElementById('mtCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' type' + (rows.length === 1 ? '' : 's');
        });
    }
    document.querySelectorAll('.mt-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-copy') || '';
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(val).then(function () { flashCopy(btn); }).catch(function () { fallbackCopy(val, btn); });
            } else {
                fallbackCopy(val, btn);
            }
        });
    });
    function fallbackCopy(val, btn) {
        var ta = document.createElement('textarea');
        ta.value = val;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        flashCopy(btn);
    }
    function flashCopy(btn) {
        var icon = btn.querySelector('.lucide');
        var old = icon ? icon.getAttribute('data-lucide') : 'copy';
        if (icon) icon.setAttribute('data-lucide', 'check');
        btn.classList.add('copied');
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({root: btn});
        setTimeout(function () {
            btn.classList.remove('copied');
            if (icon) icon.setAttribute('data-lucide', old);
            if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({root: btn});
        }, 1200);
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>