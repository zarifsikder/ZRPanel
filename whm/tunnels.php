<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../tunnel_manager.php';
require_whm();
require_feature('whm_tunnels');
init_db();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    // Panel/server tunnel actions
    if (in_array($action, ['panel_set_token', 'panel_set_domain', 'panel_tunnel_stop', 'panel_tunnel_start'], true)) {
        if (!csrf_verify()) csrf_fail();

        if ($action === 'panel_set_token') {
            $token = trim($_POST['panel_token'] ?? '');
            if ($token !== '') {
                $saved = TunnelManager::panelSaveToken($token);
                if (!$saved['success']) {
                    flash('error', 'Token not saved: ' . ($saved['error'] ?? 'invalid'));
                    redirect('/whm/tunnels.php');
                }
            }
            $restart = TunnelManager::panelRestart();
            if ($restart['success']) {
                flash('success', 'Panel tunnel token saved and tunnel restarted' . (isset($restart['pid']) ? ' (PID ' . $restart['pid'] . ')' : ''));
            } else {
                flash('error', 'Token saved, but tunnel restart failed: ' . ($restart['error'] ?? 'unknown'));
            }
            redirect('/whm/tunnels.php');
        }

        if ($action === 'panel_set_domain') {
            $domain = valid_hostname($_POST['panel_domain'] ?? '');
            if ($domain === null) {
                flash('error', 'Invalid tunnel domain. Use a valid hostname like you.example.com (not localhost or an IP).');
                redirect('/whm/tunnels.php');
            }
            $r = TunnelManager::panelApplyDomain($domain);
            if (empty($r['success'])) {
                flash('error', 'Tunnel domain set to "' . $domain . '", but the cloudflared config could not be written: ' . ($r['config_error'] ?? 'unknown') . '. Token still defines the connector.');
                redirect('/whm/tunnels.php');
            }
            $bits = ['Tunnel domain set to "' . $domain . '"'];
            if (!empty($r['site_domain'])) $bits[] = 'SITE_DOMAIN updated';
            if (!empty($r['tunnel_config'])) $bits[] = 'cloudflared config regenerated with the domain as public hostname';
            if (!empty($r['restart']['success'])) {
                $bits[] = 'tunnel restarted (PID ' . $r['restart']['pid'] . ')';
            } elseif (!empty($r['restart']['error'])) {
                $bits[] = 'tunnel not started: ' . $r['restart']['error'];
            }
            flash('success', implode('. ', $bits) . '.');
            redirect('/whm/tunnels.php');
        }

        if ($action === 'panel_tunnel_stop') {
            TunnelManager::panelStop();
            flash('success', 'Panel tunnel stopped.');
            redirect('/whm/tunnels.php');
        }

        if ($action === 'panel_tunnel_start') {
            $r = TunnelManager::panelRestart();
            if ($r['success']) {
                flash('success', 'Panel tunnel started (PID ' . $r['pid'] . ').');
            } else {
                flash('error', 'Panel tunnel failed to start: ' . ($r['error'] ?? 'unknown'));
            }
            redirect('/whm/tunnels.php');
        }
    }

    // Per-customer tunnel actions (connections that route customer domains)
    if ($uid > 0 && in_array($action, ['start', 'stop', 'restart'], true)) {
        if (!csrf_verify()) csrf_fail();

        if ($action === 'start') {
            $stmt = $db->prepare("SELECT tunnel_token FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $row = $stmt->fetch();
            if ($row && !empty($row['tunnel_token'])) {
                $result = TunnelManager::start($uid, $row['tunnel_token']);
                if ($result['success']) {
                    flash('success', "Tunnel started for user ID {$uid}");
                } else {
                    flash('error', "Failed to start tunnel: " . ($result['error'] ?? 'Unknown'));
                }
            } else {
                flash('error', 'No tunnel token found for this user');
            }
        } elseif ($action === 'stop') {
            TunnelManager::stop($uid);
            flash('success', 'Tunnel stopped');
        } elseif ($action === 'restart') {
            $result = TunnelManager::restart($uid);
            if ($result['success']) {
                flash('success', 'Tunnel restarted');
            } else {
                flash('error', 'Restart failed: ' . ($result['error'] ?? 'Unknown'));
            }
        }

        redirect('/whm/tunnels.php');
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'logs') {
    $uid = (int)($_GET['user_id'] ?? 0);
    header('Content-Type: text/plain; charset=utf-8');
    echo TunnelManager::getLogs($uid, 100);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'panel_log') {
    header('Content-Type: text/plain; charset=utf-8');
    echo TunnelManager::panelLog(120);
    exit;
}

$tunnels = TunnelManager::listAll();
$connected = count(array_filter($tunnels, fn($t) => $t['running']));
$total = count($tunnels);

// Panel / server tunnel state
$panel_token = TunnelManager::panelGetToken();
$panel_running = TunnelManager::panelIsRunning();
$panel_pid = $panel_running ? (int)file_get_contents(TunnelManager::panelPidFile()) : 0;
$gd = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
$tunnel_domain = trim((string)($gd['value'] ?? ''));
if ($tunnel_domain === '') $tunnel_domain = SITE_DOMAIN;

$nav = 'tunnels';
$page_title = 'Cloudflare Tunnels';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="cloud" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Cloudflare Tunnels</div>
        <div class="hero-desc">Manage the tunnels that route the admin panel and each customer's domain to this server.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><i data-lucide="cloud" class="lucide"></i> <?= $total ?> customer tunnel<?= $total === 1 ? '' : 's' ?></span></div>
</div>

<?php if ($flash = flash('success')): ?>
    <div class="alert alert-success"><i data-lucide="check-circle" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>
<?php if ($flash = flash('error')): ?>
    <div class="alert alert-error"><i data-lucide="alert-triangle" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>
<?php if ($flash = flash('info')): ?>
    <div class="alert alert-info"><i data-lucide="info" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>

<!-- Server (panel) tunnel -->
<div class="card fade-in" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i data-lucide="server" class="lucide"></i> Server Tunnel (admin panel)</h3>
        <?php if ($panel_token !== ''): ?>
            <span class="badge <?= $panel_running ? 'badge-active' : 'badge-suspended' ?>" style="font-size:11px"><i data-lucide="<?= $panel_running ? 'cloud' : 'cloud-off' ?>" class="lucide"></i> <?= $panel_running ? 'Connected' : 'Disconnected' ?></span>
        <?php else: ?>
            <span class="badge badge-blue" style="background:rgba(100,116,139,.1);color:var(--text4);font-size:11px">No token</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--text3);margin-bottom:16px">This tunnel exposes the admin panel to the internet through Cloudflare. Change the <strong>connection token</strong> here and the <strong>tunnel domain</strong> (the public hostname the panel answers on). Saving a token restarts cloudflared with the new credentials immediately.</p>

        <div class="grid-2" style="gap:20px;align-items:start">
            <div class="form-group" style="margin:0">
                <label>Tunnel connection token</label>
                <p style="font-size:12px;color:var(--text4);margin-bottom:8px"><?= $panel_token !== '' ? 'A token is currently saved (' . strlen($panel_token) . ' chars).' : 'No token saved yet — the tunnel cannot connect until you add one.' ?></p>
                <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="hidden" name="action" value="panel_set_token">
                    <?= csrf_field() ?>
                    <input type="password" name="panel_token" placeholder="Paste new token (leave blank to keep, just restart)" autocomplete="off" style="flex:1;min-width:220px"
                        value="<?php if (defined('DEBUG_ENABLED') && DEBUG_ENABLED) h($panel_token); ?>">
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save &amp; Restart</button>
                </form>
                <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                    <?php if ($panel_running): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="panel_tunnel_stop">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Stop the admin panel tunnel?')"><i data-lucide="square" class="lucide"></i> Stop Tunnel</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="panel_tunnel_start">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-success"><i data-lucide="play" class="lucide"></i> Start Tunnel</button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm" style="background:var(--bg4);color:var(--text2);border:1px solid var(--border);" id="panelLogBtn" onclick="showPanelLog()"><i data-lucide="scroll-text" class="lucide"></i> Tunnel Log</button>
                </div>
                <?php if ($panel_running): ?>
                    <p style="font-size:11px;color:var(--text4);margin-top:10px">cloudflared PID <?= $panel_pid ?> — public URL: <code style="color:var(--primary2)">https://<?= h($tunnel_domain !== 'localhost' ? $tunnel_domain : '(domain not set)') ?></code></p>
                <?php endif; ?>
            </div>

            <div class="form-group" style="margin:0">
                <label>Tunnel domain</label>
                <p style="font-size:12px;color:var(--text4);margin-bottom:8px">The public hostname Cloudflare routes into this panel — the same value as the Global Domain set under WHM → Domains. Saving regenerates the local cloudflared config with this domain as the ingress hostname.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="panel_set_domain">
                    <?= csrf_field() ?>
                    <div style="display:flex;gap:8px">
                        <input type="text" name="panel_domain" value="<?= $tunnel_domain !== 'localhost' ? h($tunnel_domain) : '' ?>" placeholder="you.example.com" autocomplete="off" spellcheck="false" required style="flex:1;min-width:220px">
                        <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save</button>
                    </div>
                </form>
                <?php if (defined('CF_ENABLED') && CF_ENABLED && trim((string)CF_TUNNEL_ID) !== ''): ?>
                    <p style="font-size:12px;color:var(--text4);margin-top:10px">Add a DNS record on the zone for this hostname — proxied CNAME to <code><?= h(CF_TUNNEL_ID) ?>.cfargotunnel.com</code> — so requests reach this tunnel (Cloudflare dashboard or WHM → DNS).</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.badge-active{background:rgba(34,197,94,.12);color:#16a34a}
.badge-suspended{background:rgba(239,68,68,.12);color:#dc2626}
</style>

<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="cloud" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total ?></div>
            <div class="stat-label">Total Tunnels</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="check-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $connected ?></div>
            <div class="stat-label">Connected</div>
        </div>
    </div>
    <div class="stat-card stat-red fade-in-delay-2">
        <div class="stat-icon icon-red"><i data-lucide="x-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total - $connected ?></div>
            <div class="stat-label">Disconnected</div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="list" class="lucide"></i> All Tunnels (<?= $total ?>)</h3>
    </div>
    <?php if (!empty($tunnels)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="tnSearch" placeholder="Search username or domain..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="tnCount"><?= $total ?> tunnel<?= $total === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($tunnels) ? '14px' : '16px' ?>">
        <?php if (empty($tunnels)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="cloud" class="lucide"></i></div>
                <strong>No tunnel connections configured yet</strong>
                <p>Users can connect their Cloudflare tunnels from cPanel → Cloudflare Tunnel.</p>
            </div>
        <?php else: ?>
            <div class="tn-list">
            <?php foreach ($tunnels as $t): ?>
                <div class="tn-row" data-name="<?= h($t['username'] . ' ' . $t['domain']) ?>">
                    <span class="tn-ic <?= $t['running'] ? '' : 'is-off' ?>"><i data-lucide="<?= $t['running'] ? 'cloud' : 'cloud-off' ?>" class="lucide"></i></span>
                    <div class="tn-main">
                        <div class="tn-name"><?= h($t['username']) ?></div>
                        <div class="tn-meta">
                            <span class="tn-domain"><?= h($t['domain'] ?: '-') ?></span>
                            <span class="badge <?= $t['running'] ? 'badge-active' : 'badge-suspended' ?>"><?= $t['running'] ? 'Connected' : 'Disconnected' ?></span>
                            <span class="tn-pid"><i data-lucide="cpu" class="lucide"></i> PID <?= $t['pid'] ?: 'n/a' ?></span>
                        </div>
                    </div>
                    <div class="tn-actions">
                        <?php if ($t['running']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="restart">
                                <input type="hidden" name="user_id" value="<?= $t['user_id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-primary" title="Restart"><i data-lucide="refresh-cw" class="lucide"></i> Restart</button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="stop">
                                <input type="hidden" name="user_id" value="<?= $t['user_id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger" title="Stop"><i data-lucide="square" class="lucide"></i> Stop</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="start">
                                <input type="hidden" name="user_id" value="<?= $t['user_id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-success" title="Start"><i data-lucide="play" class="lucide"></i> Start</button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm" style="background:var(--bg4);color:var(--text2);border:1px solid var(--border);" onclick="showLogs(<?= $t['user_id'] ?>, '<?= h($t['username']) ?>')" title="View Logs"><i data-lucide="scroll-text" class="lucide"></i> Logs</button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.stat-card.stat-red{background:linear-gradient(135deg,rgba(220,38,38,.12),rgba(220,38,38,.02) 70%);border-color:rgba(220,38,38,.14)}
.stat-card.stat-red::before{background:linear-gradient(90deg,#dc2626,#f87171)}
.stat-card .stat-icon.icon-red{background:var(--danger-light);color:var(--danger);border:1px solid var(--danger-border)}
.tn-list{display:flex;flex-direction:column;gap:10px}
.tn-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.tn-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.tn-ic{width:42px;height:42px;min-width:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0284c7,#0ea5e9 55%,#38bdf8);color:#fff;box-shadow:0 4px 10px rgba(14,165,233,.18)}
.tn-ic.is-off{background:linear-gradient(135deg,#64748b,#94a3b8 55%,#cbd5e1);box-shadow:0 4px 10px rgba(100,116,139,.18)}
.tn-ic .lucide{width:19px;height:19px}
.tn-main{min-width:0;flex:1}
.tn-name{font-weight:700;color:var(--text);font-size:13.5px}
.tn-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.tn-meta .badge{font-size:10px;padding:3px 8px}
.tn-domain{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--text2);font-weight:600}
.tn-pid{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--text2);background:var(--bg3);border:1px solid var(--border);padding:3px 9px;border-radius:7px;font-family:'Fira Code',monaco,consolas,monospace}
.tn-pid .lucide{width:12px;height:12px;color:var(--text4)}
.tn-actions{display:flex;gap:6px;align-items:center;flex-shrink:0;flex-wrap:wrap}
@media(max-width:640px){
  .tn-row{flex-wrap:wrap}
  .tn-main{flex-basis:100%}
  .tn-actions{width:100%}
  .tn-actions form{flex:1}
  .tn-actions form .btn{width:100%}
}
</style>

<script>
(function () {
    var input = document.getElementById('tnSearch');
    var count = document.getElementById('tnCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.tn-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' tunnel' + (shown === 1 ? '' : 's');
        });
    }
})();
</script>

<div id="logModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:var(--bg);border-radius:var(--radius);width:90%;max-width:800px;max-height:80vh;display:flex;flex-direction:column;">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:15px;" id="logModalTitle">Logs</h3>
            <button onclick="closeLogs()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text3);">&times;</button>
        </div>
        <div style="padding:16px 20px;overflow-y:auto;flex:1;">
            <pre id="logModalContent" style="background:#0f172a;color:#94a3b8;border-radius:var(--radius-sm);padding:16px;font-family:'Fira Code',monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;line-height:1.7;min-height:200px;max-height:60vh;overflow-y:auto;"></pre>
        </div>
    </div>
</div>

<script>
var logInterval = null;
var currentLogUserId = null;

function showLogs(userId, username) {
    currentLogUserId = userId;
    document.getElementById('logModalTitle').textContent = 'Tunnel Logs — ' + username;
    document.getElementById('logModal').style.display = 'flex';
    fetchLogs();
    logInterval = setInterval(fetchLogs, 4000);
}

function showPanelLog() {
    currentLogUserId = null;
    document.getElementById('logModalTitle').textContent = 'Server Tunnel Log (cloudflared)';
    document.getElementById('logModal').style.display = 'flex';
    fetch('/whm/tunnels.php?ajax=panel_log')
        .then(r => r.text())
        .then(data => {
            var el = document.getElementById('logModalContent');
            el.textContent = data || 'No log output yet. Start the tunnel first.';
            el.scrollTop = el.scrollHeight;
        });
}

function fetchLogs() {
    if (!currentLogUserId) return;
    fetch('/whm/tunnels.php?ajax=logs&user_id=' + currentLogUserId)
        .then(r => r.text())
        .then(data => {
            var el = document.getElementById('logModalContent');
            el.textContent = data || 'No logs yet...';
            el.scrollTop = el.scrollHeight;
        });
}

function closeLogs() {
    document.getElementById('logModal').style.display = 'none';
    if (logInterval) { clearInterval(logInterval); logInterval = null; }
    currentLogUserId = null;
}

document.getElementById('logModal').addEventListener('click', function(e) {
    if (e.target === this) closeLogs();
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
