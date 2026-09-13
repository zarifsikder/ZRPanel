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
        $extension = strtolower(trim($_POST['extension'] ?? ''));
        $handler = trim($_POST['handler'] ?? '');
        if (!empty($extension) && !empty($handler)) {
            if ($extension[0] !== '.') $extension = '.' . $extension;
            $exists = $db->prepare("SELECT id FROM apache_handlers WHERE extension = ? AND user_id = ?");
            $exists->execute([$extension, $user_id]);
            if (!$exists->fetch()) {
                $db->prepare("INSERT INTO apache_handlers (user_id, extension, handler) VALUES (?, ?, ?)")
                   ->execute([$user_id, $extension, $handler]);
                write_htaccess_handlers($home_dir);
                flash('success', "Handler for {$extension} added");
            } else {
                flash('error', 'Handler for this extension already exists');
            }
        } else {
            flash('error', 'Extension and handler type are required');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM apache_handlers WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $handler = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($handler) {
            $new_status = $handler['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE apache_handlers SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            write_htaccess_handlers($home_dir);
            flash('success', "Handler {$new_status}");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM apache_handlers WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        write_htaccess_handlers($home_dir);
        flash('success', 'Handler deleted');
    }
    redirect('/cpanel/apache-handlers.php');
}

function write_htaccess_handlers($home_dir) {
    global $db, $user_id;
    $htaccess = rtrim($home_dir, '/') . '/.htaccess';
    $lines = [];
    if (file_exists($htaccess)) {
        $lines = preg_split('/\r?\n/', file_get_contents($htaccess));
    }
    $cleaned = [];
    $skip = false;
    foreach ($lines as $line) {
        if (preg_match('/^# ZRPanel Handlers Start$/i', $line)) { $skip = true; continue; }
        if (preg_match('/^# ZRPanel Handlers End$/i', $line)) { $skip = false; continue; }
        if (!$skip) $cleaned[] = $line;
    }
    $stmt = $db->prepare("SELECT extension, handler FROM apache_handlers WHERE user_id = ? AND status = 'active' ORDER BY extension");
    $stmt->execute([$user_id]);
    $active = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($active)) {
        $directives = ['# ZRPanel Handlers Start'];
        foreach ($active as $h) {
            $ext = $h['extension'];
            $handler = $h['handler'];
            if ($handler === 'None' || $handler === '') {
                $directives[] = "RemoveHandler {$ext}";
            } elseif ($handler === 'php-script' || $handler === 'application/x-httpd-php') {
                $directives[] = "AddHandler {$handler} {$ext}";
            } else {
                $directives[] = "AddHandler {$handler} {$ext}";
            }
        }
        $directives[] = '# ZRPanel Handlers End';
        $cleaned = array_merge($cleaned, $directives);
    }
    file_put_contents($htaccess, implode("\n", array_filter($cleaned, function($l) { return $l !== ''; })) . "\n");
}

$handlers = $db->prepare("SELECT * FROM apache_handlers WHERE user_id = ? ORDER BY extension");
$handlers->execute([$user_id]);
$handlers = $handlers->fetchAll(PDO::FETCH_ASSOC);

$active_count = 0;
$disabled_count = 0;
foreach ($handlers as $h) {
    if ($h['status'] === 'active') {
        $active_count++;
    } else {
        $disabled_count++;
    }
}

$handler_types = [
    'cgi-script'            => 'CGI scripts',
    'server-parsed'         => 'Server-side includes',
    'php-script'            => 'PHP scripts',
    'application/x-httpd-php' => 'PHP (application/x-httpd-php)',
    ''                      => 'None (remove handler)',
];

$predefined_handlers = [
    ['.cgi', 'cgi-script', 'Perl/CGI scripts'],
    ['.pl', 'cgi-script', 'Perl scripts'],
    ['.py', 'cgi-script', 'Python CGI scripts'],
    ['.php', 'application/x-httpd-php', 'PHP scripts'],
    ['.phtml', 'application/x-httpd-php', 'PHP (phtml)'],
    ['.php3', 'application/x-httpd-php', 'PHP (legacy)'],
    ['.php4', 'application/x-httpd-php', 'PHP (legacy)'],
    ['.php5', 'application/x-httpd-php', 'PHP (legacy)'],
    ['.shtml', 'server-parsed', 'Server-side includes'],
    ['.shtm', 'server-parsed', 'Server-side includes'],
];

$nav = 'apachehandlers';
$page_title = 'Apache Handlers';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon orange"><i data-lucide="code-2" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Apache Handlers</div>
        <div class="hero-desc">Map file extensions to the handlers Apache uses to process them, and write those mappings into your home <code>.htaccess</code>.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><i data-lucide="layers" class="lucide"></i> <?= count($handlers) ?> handler<?= count($handlers) === 1 ? '' : 's' ?></span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="check-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_count ?></div>
            <div class="stat-label">Active Handlers</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">written to .htaccess</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="pause-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $disabled_count ?></div>
            <div class="stat-label">Disabled Handlers</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">saved but not applied</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="file-code" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($handlers) ?></div>
            <div class="stat-label">Total Configured</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">active + disabled</div>
        </div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Add Apache Handler</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>File Extension <span style="color:var(--danger)">*</span></label>
                <input type="text" id="ahExt" name="extension" required placeholder="e.g. .cgi, .py, .shtml" pattern="\.[a-zA-Z0-9]+" title="Must start with a dot followed by extension characters">
            </div>
            <div class="ah-handlers">
                <label class="ah-label">Handler Type <span style="color:var(--danger)">*</span></label>
                <label class="ah-tile">
                    <input type="radio" name="handler" value="cgi-script" checked>
                    <span class="ah-tile-ic"><i data-lucide="terminal" class="lucide"></i></span>
                    <span class="ah-tile-name">cgi-script</span>
                    <span class="ah-tile-desc">CGI scripts</span>
                </label>
                <label class="ah-tile">
                    <input type="radio" name="handler" value="server-parsed">
                    <span class="ah-tile-ic"><i data-lucide="file-text" class="lucide"></i></span>
                    <span class="ah-tile-name">server-parsed</span>
                    <span class="ah-tile-desc">Server-side includes</span>
                </label>
                <label class="ah-tile">
                    <input type="radio" name="handler" value="php-script">
                    <span class="ah-tile-ic"><i data-lucide="zap" class="lucide"></i></span>
                    <span class="ah-tile-name">php-script</span>
                    <span class="ah-tile-desc">PHP scripts</span>
                </label>
                <label class="ah-tile">
                    <input type="radio" name="handler" value="application/x-httpd-php">
                    <span class="ah-tile-ic"><i data-lucide="code" class="lucide"></i></span>
                    <span class="ah-tile-name">x-httpd-php</span>
                    <span class="ah-tile-desc">PHP via Apache module</span>
                </label>
                <label class="ah-tile">
                    <input type="radio" name="handler" value="None">
                    <span class="ah-tile-ic"><i data-lucide="x-circle" class="lucide"></i></span>
                    <span class="ah-tile-name">None</span>
                    <span class="ah-tile-desc">Remove handler</span>
                </label>
            </div>
            <div class="ah-chips" style="margin-top:16px">
                <span class="ah-chip-label">Quick add</span>
                <button type="button" class="btn btn-sm btn-ghost ah-chip" data-ext=".cgi" data-handler="cgi-script">.cgi</button>
                <button type="button" class="btn btn-sm btn-ghost ah-chip" data-ext=".pl" data-handler="cgi-script">.pl</button>
                <button type="button" class="btn btn-sm btn-ghost ah-chip" data-ext=".py" data-handler="cgi-script">.py</button>
                <button type="button" class="btn btn-sm btn-ghost ah-chip" data-ext=".php" data-handler="application/x-httpd-php">.php</button>
                <button type="button" class="btn btn-sm btn-ghost ah-chip" data-ext=".phtml" data-handler="application/x-httpd-php">.phtml</button>
                <button type="button" class="btn btn-sm btn-ghost ah-chip" data-ext=".shtml" data-handler="server-parsed">.shtml</button>
            </div>
            <div style="margin-top:18px">
                <button type="submit" class="btn btn-primary"><i data-lucide="plus" class="lucide"></i> Add Handler</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="layers" class="lucide"></i> Configured Handlers (<?= count($handlers) ?>)</h3></div>
    <?php if (!empty($handlers)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="ahSearch" placeholder="Search extensions, handlers..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="ahCount"><?= count($handlers) ?> handler<?= count($handlers) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($handlers) ? '14px' : '16px' ?>">
        <?php if (empty($handlers)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="code-2" class="lucide"></i></div>
                <strong>No Apache handlers configured</strong>
                <p>Add a handler above to map a file extension to how Apache processes it.</p>
            </div>
        <?php else: ?>
        <div class="ah-list">
            <?php foreach ($handlers as $h): ?>
                <div class="ah-row" data-name="<?= h(strtolower($h['extension'] . ' ' . $h['handler'] . ' ' . $h['status'])) ?>">
                    <span class="ah-ic <?= $h['status'] !== 'active' ? 'is-off' : '' ?>"><i data-lucide="code" class="lucide"></i></span>
                    <div class="ah-main">
                        <div class="ah-name">
                            <code><?= h($h['extension']) ?></code>
                            <?php if ($h['status'] === 'active'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                            <?php else: ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><span class="dot red"></span> <?= h(ucfirst($h['status'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ah-meta">
                            <code class="chip-mono"><?= ($h['handler'] === 'None' || $h['handler'] === '') ? 'RemoveHandler' : 'AddHandler' ?> <?= $h['handler'] === 'None' || $h['handler'] === '' ? h($h['extension']) : h($h['handler']) . ' ' . h($h['extension']) ?></code>
                            <span><?= h($handler_types[$h['handler']] ?? $h['handler']) ?></span>
                        </div>
                    </div>
                    <div class="ah-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $h['status'] === 'active' ? 'btn-warning' : 'btn-success' ?>" title="<?= $h['status'] === 'active' ? 'Disable' : 'Enable' ?>"><i data-lucide="<?= $h['status'] === 'active' ? 'pause' : 'play' ?>" class="lucide"></i> <?= $h['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this handler?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card fade-in-delay-1" style="margin-top:16px">
    <div class="card-header"><h3><i data-lucide="book-open" class="lucide"></i> Common Predefined Handlers</h3></div>
    <div class="card-body">
        <div class="ah-ref">
            <?php foreach ($predefined_handlers as $ph): ?>
                <button type="button" class="ah-ref-tile" data-ext="<?= h($ph[0]) ?>" data-handler="<?= h($ph[1]) ?>" title="Use this handler">
                    <span class="ah-ref-ext"><code><?= h($ph[0]) ?></code></span>
                    <span class="chip-mono" style="font-size:10px"><?= h($ph[1]) ?></span>
                    <span class="ah-ref-desc"><?= h($ph[2]) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="form-hint" style="margin-top:10px"><i data-lucide="info" class="lucide"></i> Click a tile to pre-fill the add form above, then press <em>Add Handler</em>.</div>
    </div>
</div>

<style>
.ah-handlers{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:10px}
.ah-label{font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px}
.ah-tile{display:flex;flex-direction:column;align-items:center;gap:4px;padding:12px 10px;background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius);cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .15s;text-align:center}
.ah-tile:hover{border-color:var(--text4);transform:translateY(-1px)}
.ah-tile input{position:absolute;opacity:0;pointer-events:none}
.ah-tile:has(input:checked){border-color:var(--orange);background:rgba(249,115,22,.06);box-shadow:0 0 0 3px rgba(249,115,22,.12)}
.ah-tile-ic{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(249,115,22,.1);color:var(--orange)}
.ah-tile-ic .lucide{width:16px;height:16px}
.ah-tile:has(input:checked) .ah-tile-ic{background:var(--orange);color:#fff}
.ah-tile-name{font-size:12px;font-weight:700;color:var(--text)}
.ah-tile-desc{font-size:10px;color:var(--text4);line-height:1.3}
.ah-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.ah-chip-label{font-size:11px;font-weight:600;color:var(--text4);text-transform:uppercase;letter-spacing:.04em;margin-right:2px}
.ah-list{display:flex;flex-direction:column;gap:10px}
.ah-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.ah-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.ah-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#c2410c,#ea580c 55%,#f97316);color:#fff;box-shadow:0 4px 10px rgba(249,115,22,.18)}
.ah-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.ah-ic .lucide{width:19px;height:19px}
.ah-main{min-width:0;flex:1}
.ah-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ah-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace}
.ah-name .badge{font-size:10px;padding:3px 8px}
.ah-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.ah-meta .chip-mono{font-size:11px}
.ah-actions{display:flex;gap:6px;align-items:center;flex-shrink:0}
.ah-ref{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.ah-ref-tile{display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:12px 14px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;text-align:left;transition:border-color .15s,box-shadow .15s,transform .15s;font-family:inherit}
.ah-ref-tile:hover{border-color:var(--orange);box-shadow:var(--shadow);transform:translateY(-2px)}
.ah-ref-ext code{font-weight:700;font-size:13px;color:var(--orange);font-family:'Fira Code',monaco,consolas,monospace}
.ah-ref-desc{font-size:11px;color:var(--text4)}
@media(max-width:720px){
  .ah-row{flex-wrap:wrap}
  .ah-main{flex-basis:100%}
  .ah-actions{width:100%;flex-wrap:wrap;justify-content:flex-end}
}
</style>

<script>
(function () {
    var ext = document.getElementById('ahExt');
    var chips = Array.prototype.slice.call(document.querySelectorAll('.ah-chip, .ah-ref-tile'));
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (ext) ext.value = chip.getAttribute('data-ext') || ext.value;
            var handler = chip.getAttribute('data-handler');
            if (handler) {
                var radios = document.querySelectorAll('input[name="handler"]');
                radios.forEach(function (r) { r.checked = r.value === handler; });
            }
            if (ext) { ext.focus(); ext.select(); }
        });
    });
    var q = document.getElementById('ahSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.ah-row'));
        var c = document.getElementById('ahCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' handler' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
