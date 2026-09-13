<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('ip_blocker');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $ip = trim($_POST['ip_address'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if (!empty($ip)) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $exists = $db->prepare("SELECT id FROM ip_blocklist WHERE ip_address = ? AND user_id = ?");
                $exists->execute([$ip, $user_id]);
                if (!$exists->fetch()) {
                    $db->prepare("INSERT INTO ip_blocklist (user_id, ip_address, reason) VALUES (?, ?, ?)")
                       ->execute([$user_id, $ip, $reason]);
                    flash('success', "IP address {$ip} blocked");
                } else {
                    flash('error', 'IP already blocked');
                }
            } elseif (strpos($ip, '/') !== false && preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $ip)) {
                $db->prepare("INSERT INTO ip_blocklist (user_id, ip_address, reason) VALUES (?, ?, ?)")
                   ->execute([$user_id, $ip, $reason]);
                flash('success', "IP range {$ip} blocked");
            } else {
                flash('error', 'Invalid IP address format');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM ip_blocklist WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'IP removed from blocklist');
    }
    redirect('/cpanel/ip-blocker.php');
}

$blocked = $db->prepare("SELECT * FROM ip_blocklist WHERE user_id = ? ORDER BY created_at DESC");
$blocked->execute([$user_id]);
$blocked = $blocked->fetchAll(PDO::FETCH_ASSOC);

$ranges = 0;
foreach ($blocked as $b) { if (strpos($b['ip_address'], '/') !== false) $ranges++; }

$nav = 'ipblocker';
$page_title = 'IP Blocker';
require_once __DIR__ . '/../templates/header.php';
?>

<?php
$single_count = count($blocked) - $ranges;
$month_count = 0;
foreach ($blocked as $b) {
    if (date('Y-m', strtotime($b['created_at'])) === date('Y-m')) $month_count++;
}
?>

<div class="page-hero fade-in">
    <div class="hero-icon red"><i data-lucide="shield-x" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">IP Blocker</div>
        <div class="hero-desc">Block abusive IP addresses or entire ranges from reaching your websites.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-suspended"><?= count($blocked) ?> blocked</span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="shield-x" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($blocked) ?></div>
            <div class="stat-label">Total Blocked</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $single_count ?> single + <?= $ranges ?> range<?= $ranges === 1 ? '' : 's' ?></div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="globe-lock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $single_count ?></div>
            <div class="stat-label">Single IPs</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">blocked individually</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="network" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $ranges ?></div>
            <div class="stat-label">Blocked Ranges</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">CIDR netblocks</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="calendar-plus" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $month_count ?></div>
            <div class="stat-label">Added This Month</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= date('F Y') ?></div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="plus-circle" class="lucide"></i> Block IP Address</h3></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>IP Address or Range <span class="label-req">*</span></label>
                <input type="text" name="ip_address" required placeholder="e.g. 192.168.1.100 or 10.0.0.0/24" style="font-family:'Fira Code',monospace;font-size:13px">
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Single IPv4/IPv6 addresses and CIDR ranges like 10.0.0.0/24 are supported</div>
            </div>
            <div class="form-group">
                <label>Reason (optional)</label>
                <input type="text" name="reason" placeholder="e.g. Brute force attack" maxlength="255">
                <div class="form-hint"><i data-lucide="notebook-pen" class="lucide"></i> Helps you remember why an address was blocked</div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-danger"><i data-lucide="ban" class="lucide"></i> Block IP</button>
            </div>
        </form>
    </div>
</div>

<div class="tip-card tip-red fade-in-delay-1">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong>Careful.</strong> Blocking applies immediately and until you remove the entry. If you block your own IP, you will lock yourself out of every site on this account.</div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> Blocked IPs (<?= count($blocked) ?>)</h3></div>
    <?php if (!empty($blocked)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="ipSearch" placeholder="Search IPs or reasons..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="ipCount"><?= count($blocked) ?> entr<?= count($blocked) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($blocked) ? '14px' : '16px' ?>">
        <?php if (empty($blocked)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="shield-check" class="lucide"></i></div>
                <strong>No IPs blocked</strong>
                <p>Your blocklist is empty. Add an address above if you notice suspicious traffic.</p>
            </div>
        <?php else: ?>
        <div class="ip-list">
            <?php foreach ($blocked as $b):
                $is_range = strpos($b['ip_address'], '/') !== false;
            ?>
                <div class="ip-row" data-name="<?= h(strtolower($b['ip_address'] . ' ' . $b['reason'] . ' ' . ($is_range ? 'range' : 'single'))) ?>">
                    <span class="ip-ic <?= $is_range ? 'is-range' : '' ?>"><i data-lucide="<?= $is_range ? 'network' : 'ban' ?>" class="lucide"></i></span>
                    <div class="ip-main">
                        <div class="ip-name">
                            <code><?= h($b['ip_address']) ?></code>
                            <button type="button" class="ip-copy" data-copy="<?= h($b['ip_address']) ?>" title="Copy IP address"><i data-lucide="copy" class="lucide"></i></button>
                        </div>
                        <div class="ip-meta">
                            <span class="badge <?= $is_range ? 'badge-purple' : 'badge-blue' ?>"><?= $is_range ? 'Range' : 'Single' ?></span>
                            <?php if ($b['reason']): ?>
                                <span><?= h($b['reason']) ?></span>
                            <?php endif; ?>
                            <span>Blocked <?= date('M d, Y H:i', strtotime($b['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="ip-actions">
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove this entry from the blocklist? The visitor will be able to access your site again.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Unblock"><i data-lucide="shield-check" class="lucide"></i> Unblock</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.ip-list{display:flex;flex-direction:column;gap:10px}
.ip-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.ip-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.ip-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444);color:#fff;box-shadow:0 4px 10px rgba(220,38,38,.18)}
.ip-ic.is-range{background:linear-gradient(135deg,#b45309,#d97706 55%,#f59e0b);box-shadow:0 4px 10px rgba(217,119,6,.18)}
.ip-ic .lucide{width:19px;height:19px}
.ip-main{min-width:0;flex:1}
.ip-name{display:flex;align-items:center;gap:7px}
.ip-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ip-copy{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.ip-copy .lucide{width:12px;height:12px}
.ip-copy:hover{background:rgba(220,38,38,.1);color:var(--danger)}
.ip-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.ip-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.ip-meta .badge{font-size:10px;padding:3px 8px}
.ip-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:640px){
  .ip-row{flex-wrap:wrap}
  .ip-main{flex-basis:100%}
  .ip-actions{width:100%}
  .ip-actions form{flex:1}
  .ip-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var input = document.getElementById('ipSearch');
    var count = document.getElementById('ipCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.ip-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' of ' + rows.length + ' entr' + (rows.length === 1 ? 'y' : 'ies');
        });
    }
    document.querySelectorAll('.ip-copy').forEach(function (btn) {
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