<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$error_log_path = __DIR__ . '/../error.log';
$project_dir = __DIR__ . '/..';

$actions = ['clear', 'download', 'delete_log_file'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $actions)) {
    $action = $_POST['action'];
    $target = $_POST['log_file'] ?? 'error.log';
    $target = basename($target);
    $target_path = $project_dir . '/' . $target;

    if ($action === 'clear') {
        if (file_exists($target_path) && is_writable($target_path)) {
            file_put_contents($target_path, '');
            flash('success', h($target) . ' cleared');
        } else {
            flash('error', 'Cannot clear log file');
        }
        redirect('/cpanel/logs.php?log=' . urlencode($target));
    }

    if ($action === 'download') {
        if (file_exists($target_path) && is_readable($target_path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $target . '"');
            header('Content-Length: ' . filesize($target_path));
            header('Cache-Control: no-cache');
            readfile($target_path);
            exit;
        }
        flash('error', 'File not found');
        redirect('/cpanel/logs.php?log=' . urlencode($target));
    }

    if ($action === 'delete_log_file') {
        if (file_exists($target_path) && is_writable($target_path)) {
            unlink($target_path);
            flash('success', h($target) . ' deleted');
        } else {
            flash('error', 'Cannot delete log file');
        }
        redirect('/cpanel/logs.php');
    }
}

$selected_log = $_GET['log'] ?? 'error.log';
$selected_log = basename($selected_log);
$selected_path = $project_dir . '/' . $selected_log;

$log_files = glob($project_dir . '/*.log');
$log_files[] = $error_log_path;
$seen = [];
$unique_logs = [];
foreach ($log_files as $lf) {
    $base = basename($lf);
    if (!isset($seen[$base])) {
        $seen[$base] = true;
        $unique_logs[$base] = $lf;
    }
}
if (!isset($seen[$selected_log]) && file_exists($selected_path)) {
    $unique_logs[$selected_log] = $selected_path;
}
ksort($unique_logs);

$log_content = '';
$log_lines = [];
$log_size = 0;
if (file_exists($selected_path) && is_readable($selected_path)) {
    $log_size = filesize($selected_path);
    $content = file_get_contents($selected_path);
    $log_lines = explode("\n", $content);
    $log_lines = array_filter($log_lines, function($l) { return $l !== ''; });
    $log_lines = array_values($log_lines);
    $log_lines = array_slice($log_lines, -200);
    $log_lines = array_reverse($log_lines);
    $log_content = implode("\n", $log_lines);
}

$cron_stmt = $db->prepare("SELECT command, status, last_run, created_at FROM cron_jobs WHERE user_id = ? ORDER BY last_run DESC LIMIT 50");
$cron_stmt->execute([$user_id]);
$cron_logs = $cron_stmt->fetchAll(PDO::FETCH_ASSOC);

$nav = 'logs';
$page_title = 'Logs';
require_once __DIR__ . '/../templates/header.php';

$total_log_size = 0;
foreach ($unique_logs as $lp) { $total_log_size += @filesize($lp) ?: 0; }
?>

<div class="page-hero fade-in">
    <div class="hero-icon orange"><i data-lucide="scroll-text" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Logs</div>
        <div class="hero-desc">Inspect error, access and cron logs in real time &mdash; search, download or clear them as needed.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><?= count($unique_logs) ?> files &middot; <?= format_size($total_log_size) ?></span></div>
</div>

<div class="card fade-in" style="padding:0;overflow:hidden;background:transparent;border:none;">
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('errorlog')"><i data-lucide="alert-triangle" class="lucide" style="width:14px;height:14px"></i> Error Log</button>
        <button class="tab-btn" onclick="switchTab('accesslog')"><i data-lucide="globe" class="lucide" style="width:14px;height:14px"></i> Access Log</button>
        <button class="tab-btn" onclick="switchTab('cronlog')"><i data-lucide="clock" class="lucide" style="width:14px;height:14px"></i> Cron Log</button>
    </div>

    <div id="tab-errorlog" class="tab-panel active">
        <div class="log-viewer-wrap">
            <div class="log-toolbar">
                <select id="logFileSelect" onchange="changeLogFile(this.value)">
                    <?php foreach ($unique_logs as $fname => $fpath): ?>
                        <option value="<?= h($fname) ?>" <?= $fname === $selected_log ? 'selected' : '' ?>><?= h($fname) ?> (<?= format_size(@filesize($fpath)) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="logSearch" placeholder="Search logs..." oninput="filterLogs()" style="flex:1;min-width:150px">
                <label style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap">
                    <input type="checkbox" id="autoRefreshToggle" onchange="toggleAutoRefresh()" style="accent-color:#6c5ce7">
                    Auto-refresh
                </label>
                <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Download <?= h($selected_log) ?>?')">
                    <input type="hidden" name="action" value="download">
                    <input type="hidden" name="log_file" value="<?= h($selected_log) ?>">
                    <button type="submit" class="btn btn-sm btn-ghost" style="color:#c8d6e5;font-size:12px"><i data-lucide="download" class="lucide" style="width:13px;height:13px"></i> Download</button>
                </form>
                <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Clear <?= h($selected_log) ?>?')">
                    <input type="hidden" name="action" value="clear">
                    <input type="hidden" name="log_file" value="<?= h($selected_log) ?>">
                    <button type="submit" class="btn btn-sm btn-warning" style="font-size:12px"><i data-lucide="trash-2" class="lucide" style="width:13px;height:13px"></i> Clear</button>
                </form>
                <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Delete <?= h($selected_log) ?> permanently?')">
                    <input type="hidden" name="action" value="delete_log_file">
                    <input type="hidden" name="log_file" value="<?= h($selected_log) ?>">
                    <button type="submit" class="btn btn-sm btn-danger" style="font-size:12px"><i data-lucide="x" class="lucide" style="width:13px;height:13px"></i> Delete</button>
                </form>
            </div>
            <div class="log-content" id="logContent">
                <?php if (empty($log_lines)): ?>
                    <pre style="text-align:center;color:#3a3a5c;padding:40px">Log file is empty or not readable</pre>
                <?php else: ?>
                    <?php foreach ($log_lines as $idx => $line): ?>
                        <?php
                            $class = 'log-line';
                            $lower = strtolower($line);
                            if (strpos($lower, 'fatal') !== false || strpos($lower, 'parse error') !== false || strpos($lower, '致命') !== false) {
                                $class .= ' level-fatal';
                            } elseif (strpos($lower, 'error') !== false) {
                                $class .= ' level-error';
                            } elseif (strpos($lower, 'warning') !== false || strpos($lower, 'warn') !== false) {
                                $class .= ' level-warning';
                            } elseif (strpos($lower, 'notice') !== false || strpos($lower, 'deprecated') !== false) {
                                $class .= ' level-notice';
                            } elseif (strpos($lower, 'info') !== false) {
                                $class .= ' level-info';
                            }
                        ?><span class="<?= $class ?>"><?= h($line) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="log-meta">
                <span><span class="dot" style="background:#22c55e" id="liveDot"></span> <span id="refreshStatus">Ready</span></span>
                <span>Lines: <?= count($log_lines) ?></span>
                <span>Size: <?= format_size($log_size) ?></span>
                <span>File: <?= h($selected_log) ?></span>
            </div>
        </div>
    </div>

    <div id="tab-accesslog" class="tab-panel">
        <div class="card fade-in" style="margin-top:0">
            <div class="card-header">
                <h3><i data-lucide="globe" class="lucide"></i> Access Log</h3>
                <span class="badge badge-active" style="font-size:11px">PHP Built-in Server</span>
            </div>
            <div class="card-body" style="padding:0">
                <?php
                $access_entries = [];
                $access_patterns = [
                    $project_dir . '/access.log',
                    $project_dir . '/server.log',
                    $project_dir . '/php_server.log',
                ];
                foreach ($access_patterns as $ap) {
                    if (file_exists($ap) && is_readable($ap)) {
                        $lines = file($ap, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $lines = array_slice($lines, -100);
                        foreach ($lines as $al) {
                            $access_entries[] = $al;
                        }
                    }
                }
                ?>
                <?php if (empty($access_entries)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i data-lucide="globe" class="lucide"></i></div>
                        <p>No access logs found</p>
                        <p style="font-size:12px;color:var(--text3);margin-top:4px">The PHP built-in server does not generate access logs by default.<br>Use <code style="font-size:11px;background:var(--bg4);padding:2px 6px;border-radius:4px">php -S 0.0.0.0:8080 -t public/ &gt;&gt; access.log 2&gt;&amp;1</code> to enable logging.</p>
                    </div>
                <?php else: ?>
                    <div class="log-viewer-wrap" style="border:none;border-radius:0">
                        <div class="log-content" id="accessLogContent" style="max-height:500px">
                            <?php foreach (array_reverse($access_entries) as $aentry): ?>
                                <?php
                                    $status_class = '';
                                    if (preg_match('/\s(\d{3})\s/', $aentry, $sm)) {
                                        $code = (int)$sm[1];
                                        if ($code >= 200 && $code < 300) $status_class = 'log-status-2xx';
                                        elseif ($code >= 300 && $code < 400) $status_class = 'log-status-3xx';
                                        elseif ($code >= 400 && $code < 500) $status_class = 'log-status-4xx';
                                        elseif ($code >= 500) $status_class = 'log-status-5xx';
                                    }
                                ?><span class="log-line level-info"><?= $status_class ? '<span class="' . $status_class . '">' . h($aentry) . '</span>' : h($aentry) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="tab-cronlog" class="tab-panel">
        <div class="card fade-in" style="margin-top:0">
            <div class="card-header">
                <h3><i data-lucide="clock" class="lucide"></i> Cron Job History</h3>
                <span class="badge badge-active" style="font-size:11px"><?= count($cron_logs) ?> entries</span>
            </div>
            <div class="card-body" style="padding:0">
                <?php if (empty($cron_logs)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i data-lucide="clock" class="lucide"></i></div>
                        <p>No cron jobs have run yet</p>
                    </div>
                <?php else: ?>
                    <div class="table-toolbar">
                        <div class="toolbar-search">
                            <i data-lucide="search" class="lucide"></i>
                            <input type="text" id="cronLogSearch" placeholder="Search commands, statuses..." autocomplete="off">
                        </div>
                        <span class="toolbar-count" id="cronLogCount"><?= count($cron_logs) ?> run<?= count($cron_logs) === 1 ? '' : 's' ?></span>
                    </div>
                    <div style="padding:16px">
                        <div class="cl-list">
                            <?php foreach ($cron_logs as $cl): ?>
                                <div class="cl-row" data-name="<?= h(strtolower($cl['command'] . ' ' . $cl['status'])) ?>">
                                    <span class="cl-ic <?= $cl['status'] !== 'active' ? 'is-off' : '' ?>"><i data-lucide="terminal" class="lucide"></i></span>
                                    <div class="cl-main">
                                        <div class="cl-name">
                                            <code><?= h($cl['command']) ?></code>
                                            <?php if ($cl['status'] === 'active'): ?>
                                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><span class="dot red"></span> <?= h(ucfirst($cl['status'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="cl-meta">
                                            <span>Last run <?= $cl['last_run'] ? date('M d, Y H:i:s', strtotime($cl['last_run'])) : '<em style="color:var(--text4)">never</em>' ?></span>
                                            <span>Created <?= date('M d, Y H:i:s', strtotime($cl['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.cl-list{display:flex;flex-direction:column;gap:10px}
.cl-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.cl-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.cl-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#c2410c,#ea580c 55%,#f97316);color:#fff;box-shadow:0 4px 10px rgba(249,115,22,.18)}
.cl-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.cl-ic .lucide{width:19px;height:19px}
.cl-main{min-width:0;flex:1}
.cl-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.cl-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-name .badge{font-size:10px;padding:3px 8px}
.cl-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
@media(max-width:720px){.cl-row{flex-wrap:wrap}.cl-main{flex-basis:100%}}
</style>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('tab-' + name).classList.add('active');
    event.currentTarget.classList.add('active');
}

function changeLogFile(file) {
    window.location.href = '/cpanel/logs.php?log=' + encodeURIComponent(file);
}

var searchInput = document.getElementById('logSearch');
var logContent = document.getElementById('logContent');
var originalHTML = logContent ? logContent.innerHTML : '';

function filterLogs() {
    if (!logContent) return;
    var q = searchInput.value.trim().toLowerCase();
    if (!q) {
        logContent.innerHTML = originalHTML;
        return;
    }
    var lines = logContent.querySelectorAll('.log-line');
    var visible = 0;
    lines.forEach(function(line) {
        var text = line.textContent.toLowerCase();
        if (text.indexOf(q) !== -1) {
            line.style.display = '';
            visible++;
            var inner = line.textContent;
            var escaped = inner.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            line.innerHTML = escaped.replace(re, '<span class="log-highlight">$1</span>');
        } else {
            line.style.display = 'none';
        }
    });
}

var autoRefreshInterval = null;
function toggleAutoRefresh() {
    var cb = document.getElementById('autoRefreshToggle');
    var dot = document.getElementById('liveDot');
    var status = document.getElementById('refreshStatus');
    if (cb.checked) {
        dot.style.background = '#22c55e';
        dot.classList.add('pulse-dot');
        status.textContent = 'Auto-refreshing (5s)';
        autoRefreshInterval = setInterval(function() {
            status.textContent = 'Refreshing...';
            fetch('/cpanel/logs.php?log=' + encodeURIComponent(document.getElementById('logFileSelect').value) + '&ajax=1')
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var newContent = doc.getElementById('logContent');
                    if (newContent) {
                        logContent.innerHTML = newContent.innerHTML;
                        originalHTML = logContent.innerHTML;
                        if (searchInput.value.trim()) filterLogs();
                    }
                    status.textContent = 'Auto-refreshing (5s)';
                })
                .catch(function() { status.textContent = 'Refresh failed'; });
        }, 5000);
    } else {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        dot.style.background = '#6b7280';
        dot.classList.remove('pulse-dot');
        status.textContent = 'Ready';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var lc = document.getElementById('logContent');
    if (lc) lc.scrollTop = 0;
});

(function () {
    var q = document.getElementById('cronLogSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.cl-row'));
        var c = document.getElementById('cronLogCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' run' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
