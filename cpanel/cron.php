<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('cron_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $command = trim($_POST['command'] ?? '');
        $minute = trim($_POST['minute'] ?? '*');
        $hour = trim($_POST['hour'] ?? '*');
        $day = trim($_POST['day'] ?? '*');
        $month = trim($_POST['month'] ?? '*');
        $weekday = trim($_POST['weekday'] ?? '*');
        if (!empty($command)) {
            $db->prepare("INSERT INTO cron_jobs (user_id, command, minute, hour, day, month, weekday) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$user_id, $command, $minute, $hour, $day, $month, $weekday]);
            flash('success', 'Cron job created');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM cron_jobs WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'Cron job deleted');
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM cron_jobs WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $new = $job['status'] === 'active' ? 'paused' : 'active';
            $db->prepare("UPDATE cron_jobs SET status = ? WHERE id = ?")->execute([$new, $id]);
            flash('success', "Job {$new}");
        }
    }
    redirect('/cpanel/cron.php');
}

$jobs = $db->prepare("SELECT * FROM cron_jobs WHERE user_id = ? ORDER BY created_at DESC");
$jobs->execute([$user_id]);
$jobs = $jobs->fetchAll(PDO::FETCH_ASSOC);

$active_jobs = 0;
$paused_jobs = 0;
foreach ($jobs as $j) {
    if ($j['status'] === 'active') $active_jobs++;
    else $paused_jobs++;
}

$nav = 'cron';
$page_title = 'Cron Jobs';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="clock" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Cron Jobs</div>
        <div class="hero-desc">Schedule commands and scripts to run automatically at fixed times, dates or intervals.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><?= count($jobs) ?> job<?= count($jobs) === 1 ? '' : 's' ?></span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="zap" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_jobs ?></div>
            <div class="stat-label">Active Jobs</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">queued by the scheduler</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="pause-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $paused_jobs ?></div>
            <div class="stat-label">Paused Jobs</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">kept but not running</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="clock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($jobs) ?></div>
            <div class="stat-label">Total Scheduled</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">active + paused</div>
        </div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Add Cron Job</h3></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="create">
            <div class="form-group" style="grid-column:1/-1">
                <label>Command <span class="label-req">*</span></label>
                <input type="text" name="command" required placeholder="e.g. php /home/user/public_html/cron.php" style="font-family:'Fira Code',monospace;font-size:13px">
                <div class="form-hint"><i data-lucide="terminal" class="lucide"></i> Use absolute paths &mdash; cron does not load your shell environment</div>
            </div>
            <div class="form-group">
                <label>Minute (0-59)</label>
                <input type="text" name="minute" value="*" placeholder="*" style="font-family:'Fira Code',monospace;text-align:center">
            </div>
            <div class="form-group">
                <label>Hour (0-23)</label>
                <input type="text" name="hour" value="*" placeholder="*" style="font-family:'Fira Code',monospace;text-align:center">
            </div>
            <div class="form-group">
                <label>Day (1-31)</label>
                <input type="text" name="day" value="*" placeholder="*" style="font-family:'Fira Code',monospace;text-align:center">
            </div>
            <div class="form-group">
                <label>Month (1-12)</label>
                <input type="text" name="month" value="*" placeholder="*" style="font-family:'Fira Code',monospace;text-align:center">
            </div>
            <div class="form-group">
                <label>Weekday (0-6)</label>
                <input type="text" name="weekday" value="*" placeholder="*" style="font-family:'Fira Code',monospace;text-align:center">
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> 0 = Sunday &middot; supports * , - / ranges</div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><i data-lucide="plus" class="lucide"></i> Add Job</button>
            </div>
        </form>
        <div class="tip-card tip-purple" style="margin-top:16px">
            <i data-lucide="wand-sparkles" class="lucide"></i>
            <div class="tip-body">
                <strong>Common schedules.</strong> Click one to fill the fields above.
                <div class="tag-list" style="margin-top:10px">
                <span class="tag" onclick="setCron('* * * * *')" style="cursor:pointer">Every minute</span>
                <span class="tag" onclick="setCron('0 * * * *')" style="cursor:pointer">Every hour</span>
                <span class="tag" onclick="setCron('0 0 * * *')" style="cursor:pointer">Daily at midnight</span>
                <span class="tag" onclick="setCron('0 0 * * 0')" style="cursor:pointer">Weekly (Sunday)</span>
                <span class="tag" onclick="setCron('0 0 1 * *')" style="cursor:pointer">Monthly (1st)</span>
                <span class="tag" onclick="setCron('*/5 * * * *')" style="cursor:pointer">Every 5 minutes</span>
                <span class="tag" onclick="setCron('0 */6 * * *')" style="cursor:pointer">Every 6 hours</span>
                <span class="tag" onclick="setCron('30 2 * * *')" style="cursor:pointer">Daily at 2:30 AM</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> All Jobs (<?= count($jobs) ?>)</h3></div>
    <?php if (!empty($jobs)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="cronSearch" placeholder="Search commands, schedules..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="cronCount"><?= count($jobs) ?> job<?= count($jobs) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($jobs) ? '14px' : '16px' ?>">
        <?php if (empty($jobs)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="clock" class="lucide"></i></div>
                <strong>No cron jobs configured</strong>
                <p>Schedule your first job above &mdash; for example a nightly backup or cache warm-up.</p>
            </div>
        <?php else: ?>
        <div class="cron-list">
            <?php foreach ($jobs as $j): ?>
                <div class="cron-row" data-name="<?= h(strtolower($j['minute'] . ' ' . $j['hour'] . ' ' . $j['day'] . ' ' . $j['month'] . ' ' . $j['weekday'] . ' ' . $j['command'] . ' ' . $j['status'])) ?>">
                    <span class="cron-ic <?= $j['status'] !== 'active' ? 'is-off' : '' ?>"><i data-lucide="clock" class="lucide"></i></span>
                    <div class="cron-main">
                        <div class="cron-name">
                            <code><?= h($j['command']) ?></code>
                            <?php if ($j['status'] === 'active'): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                            <?php else: ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><span class="dot red"></span> <?= h(ucfirst($j['status'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="cron-meta">
                            <code class="chip-mono"><?= h($j['minute'] . ' ' . $j['hour'] . ' ' . $j['day'] . ' ' . $j['month'] . ' ' . $j['weekday']) ?></code>
                            <span>Last run <?= $j['last_run'] ? date('M d, H:i', strtotime($j['last_run'])) : '<em style="color:var(--text4)">never</em>' ?></span>
                        </div>
                    </div>
                    <div class="cron-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $j['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $j['status'] === 'active' ? 'btn-warning' : 'btn-success' ?>" title="<?= $j['status'] === 'active' ? 'Pause' : 'Resume' ?>"><i data-lucide="<?= $j['status'] === 'active' ? 'pause' : 'play' ?>" class="lucide"></i> <?= $j['status'] === 'active' ? 'Pause' : 'Resume' ?></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this cron job? It will stop running permanently.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $j['id'] ?>">
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
.cron-list{display:flex;flex-direction:column;gap:10px}
.cron-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.cron-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.cron-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1d4ed8,#2563eb 55%,#3b82f6);color:#fff;box-shadow:0 4px 10px rgba(59,130,246,.18)}
.cron-ic.is-off{background:linear-gradient(135deg,#78716c,#a8a29e 55%,#d6d3d1);box-shadow:0 4px 10px rgba(168,162,158,.18)}
.cron-ic .lucide{width:19px;height:19px}
.cron-main{min-width:0;flex:1}
.cron-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.cron-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
.cron-name .badge{font-size:10px;padding:3px 8px}
.cron-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.cron-meta .chip-mono{font-size:11px}
.cron-actions{display:flex;gap:6px;align-items:center;flex-shrink:0}
@media(max-width:720px){
  .cron-row{flex-wrap:wrap}
  .cron-main{flex-basis:100%}
  .cron-actions{width:100%;flex-wrap:wrap;justify-content:flex-end}
}
</style>

<script>
function setCron(expr) {
    var parts = expr.split(' ');
    var fields = ['minute','hour','day','month','weekday'];
    fields.forEach(function(f,i) {
        var el = document.querySelector('[name="'+f+'"]');
        if (el && parts[i] !== undefined) el.value = parts[i];
    });
}
(function () {
    var q = document.getElementById('cronSearch');
    if (q) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.cron-row'));
        var c = document.getElementById('cronCount');
        q.addEventListener('input', function () {
            var v = q.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function (r) {
                var hit = !v || (r.getAttribute('data-name') || '').indexOf(v) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            if (c) c.textContent = n + ' of ' + rows.length + ' job' + (rows.length === 1 ? '' : 's');
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
