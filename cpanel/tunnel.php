<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../tunnel_manager.php';
require_login();
require_feature('cloudflare_tunnel');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'logs') {
    header('Content-Type: text/plain; charset=utf-8');
    echo TunnelManager::getLogs($user_id, 100);
    exit;
}

$stmt = $db->prepare("SELECT id, username, domain, tunnel_token, tunnel_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'connect') {
        $token = trim($_POST['tunnel_token'] ?? '');
        if (empty($token)) {
            flash('error', 'Please enter a Cloudflare Tunnel token');
        } else {
            $save = TunnelManager::saveToken($user_id, $token);
            if ($save['success']) {
                $start = TunnelManager::start($user_id, $token);
                if ($start['success']) {
                    flash('success', 'Tunnel connected successfully! Your domain is now live via Cloudflare Tunnel.');
                } else {
                    flash('error', 'Token saved but tunnel failed to start: ' . ($start['error'] ?? 'Unknown error'));
                }
            } else {
                flash('error', 'Invalid token: ' . ($save['error'] ?? 'Unknown error'));
            }
        }
    } elseif ($action === 'disconnect') {
        TunnelManager::stop($user_id);
        TunnelManager::clearLogs($user_id);
        $db->prepare("UPDATE users SET tunnel_status = 'disconnected' WHERE id = ?")->execute([$user_id]);
        flash('success', 'Tunnel disconnected');
    } elseif ($action === 'restart') {
        $restart = TunnelManager::restart($user_id);
        if ($restart['success']) {
            flash('success', 'Tunnel restarted successfully');
        } else {
            flash('error', 'Restart failed: ' . ($restart['error'] ?? 'Unknown error'));
        }
    } elseif ($action === 'clear_logs') {
        TunnelManager::clearLogs($user_id);
        flash('success', 'Logs cleared');
    }

    redirect('/cpanel/tunnel.php');
}

$status = TunnelManager::status($user_id);
$logs = '';
if ($status && $status['running']) {
    $logs = TunnelManager::getLogs($user_id, 80);
}

$tunnel_connected = !empty($status['running']);
$token_saved = !empty($status['has_token']);

$nav = 'tunnel';
$page_title = 'Cloudflare Tunnel';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon <?= $tunnel_connected ? 'green' : 'blue' ?>"><i data-lucide="cloud" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Cloudflare Tunnel</div>
        <div class="hero-desc">Connect a custom domain securely to this server with Cloudflare&rsquo;s Zero Trust tunnel.</div>
    </div>
    <div class="hero-actions">
        <span class="badge <?= $tunnel_connected ? 'badge-active' : ($token_saved ? 'badge-pending' : 'badge-suspended') ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px">
            <span class="dot <?= $tunnel_connected ? 'green' : ($token_saved ? 'amber' : 'red') ?>"></span>
            <?= $tunnel_connected ? 'Connected' : ($token_saved ? 'Token saved' : 'Not configured') ?>
        </span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card <?= $tunnel_connected ? 'stat-green' : 'stat-orange' ?> fade-in">
        <div class="stat-icon <?= $tunnel_connected ? 'icon-green' : 'icon-orange' ?>"><i data-lucide="activity" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $tunnel_connected ? 'Live' : 'Offline' ?></div>
            <div class="stat-label">Tunnel Status</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $tunnel_connected ? 'routing traffic via Cloudflare' : 'not routing traffic' ?></div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="globe" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" style="font-size:clamp(14px,2.2vw,20px)"><?= h($user['domain'] ?? SITE_DOMAIN) ?></div>
            <div class="stat-label">Connected Domain</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">public hostname target</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="cpu" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= isset($status['pid']) ? $status['pid'] : ($token_saved ? 'Saved' : '&mdash;') ?></div>
            <div class="stat-label">Process</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $token_saved ? 'cloudflared PID' : 'no token stored' ?></div>
        </div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="cloud" class="lucide"></i> Cloudflare Tunnel</h3>
    </div>
    <div class="card-body">
        <p style="color:var(--text3);margin-bottom:20px;font-size:14px;">
            Connect your custom domain to this server using Cloudflare Tunnel. Traffic from your domain will be securely routed through Cloudflare to this server.
        </p>

        <?php if ($status && ($status['has_token'] || $status['running'])): ?>
            <div style="background:var(--bg4);border:1.5px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div>
                        <div style="font-size:13px;color:var(--text4);margin-bottom:4px;">Connection Status</div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="width:10px;height:10px;border-radius:50%;display:inline-block;background:<?= $status['running'] ? '#22c55e' : '#ef4444' ?>;"></span>
                            <strong style="font-size:16px;"><?= $status['running'] ? 'Connected' : 'Disconnected' ?></strong>
                            <?php if ($status['pid']): ?>
                                <span style="font-size:11px;color:var(--text4);font-family:monospace;">PID: <?= $status['pid'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <?php if ($status['running']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="restart">
                                <button type="submit" class="btn btn-sm btn-primary"><i data-lucide="refresh-cw" class="lucide"></i> Restart</button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Disconnect this tunnel? Your domain will stop working.')">
                                <input type="hidden" name="action" value="disconnect">
                                <button type="submit" class="btn btn-sm btn-danger"><i data-lucide="x" class="lucide"></i> Disconnect</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="connect">
                                <input type="hidden" name="tunnel_token" value="<?= htmlspecialchars($user['tunnel_token'] ?? '') ?>">
                                <button type="submit" class="btn btn-sm btn-success"><i data-lucide="play" class="lucide"></i> Reconnect</button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Remove tunnel token and all data?')">
                                <input type="hidden" name="action" value="disconnect">
                                <button type="submit" class="btn btn-sm btn-danger"><i data-lucide="trash-2" class="lucide"></i> Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$status || !$status['has_token']): ?>
            <div style="background:var(--bg4);border:1.5px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:20px;">
                <h4 style="margin-bottom:12px;font-size:15px;">How to Connect</h4>
                <ol style="padding-left:20px;font-size:13px;color:var(--text3);line-height:2;">
                    <li>Go to <a href="https://one.dash.cloudflare.com/" target="_blank" style="color:var(--primary)">Cloudflare Zero Trust</a> → Networks → Tunnels</li>
                    <li>Create a new tunnel or select an existing one</li>
                    <li>Add a <strong>Public Hostname</strong>:
                        <ul style="padding-left:18px;margin-top:4px;">
                            <li>Subdomain: <code style="background:var(--bg2);padding:2px 6px;border-radius:4px;font-size:12px;">@</code> (leave empty for root domain)</li>
                            <li>Domain: <code style="background:var(--bg2);padding:2px 6px;border-radius:4px;font-size:12px;"><?= htmlspecialchars($user['domain'] ?? 'your-domain.com') ?></code></li>
                            <li>Type: <code style="background:var(--bg2);padding:2px 6px;border-radius:4px;font-size:12px;">HTTP</code></li>
                            <li>URL: <code style="background:var(--bg2);padding:2px 6px;border-radius:4px;font-size:12px;">localhost:8080</code></li>
                        </ul>
                    </li>
                    <li>Copy the tunnel token from the <code style="background:var(--bg2);padding:2px 6px;border-radius:4px;font-size:12px;">cloudflared</code> install command</li>
                    <li>Paste the token below and click Connect</li>
                </ol>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="connect">
            <div class="form-group" style="grid-column:1/-1;">
                <label>Cloudflare Tunnel Token (Connect Code)</label>
                <input type="text" name="tunnel_token" 
                       placeholder="eyJhIjoi..." 
                       value="<?= htmlspecialchars($user['tunnel_token'] ?? '') ?>"
                       style="font-family:monospace;font-size:13px;<?= ($status && $status['has_token']) ? 'color:var(--text4);' : '' ?>"
                       <?= ($status && $status['has_token']) ? 'readonly' : '' ?>
                       required>
                <div style="font-size:11px;color:var(--text4);margin-top:4px;">
                    The token from your Cloudflare tunnel's install command. Starts with <code>eyJ</code>
                </div>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <?php if ($status && $status['has_token']): ?>
                    <button type="button" onclick="document.querySelector('[name=tunnel_token]').removeAttribute('readonly');document.querySelector('[name=tunnel_token]').style.color='';this.textContent='Token Editable'" class="btn btn-secondary" style="background:var(--bg4);color:var(--text2);border:1.5px solid var(--border);">Edit Token</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary"><i data-lucide="plug" class="lucide"></i> Connect Tunnel</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($status && $status['has_token']): ?>
<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="scroll-text" class="lucide"></i> Tunnel Logs</h3>
        <div style="display:flex;gap:8px;">
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="btn btn-sm btn-secondary" style="background:var(--bg4);color:var(--text2);border:1px solid var(--border);">Clear Logs</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div id="logContainer" style="background:#0f172a;color:#94a3b8;border-radius:var(--radius-sm);padding:16px;font-family:'Fira Code',monospace;font-size:12px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;line-height:1.7;">
            <?= htmlspecialchars($logs ?: 'No logs yet. Start the tunnel to see logs.') ?>
        </div>
        <?php if ($status && $status['running']): ?>
        <script>
            setInterval(function() {
                fetch('/cpanel/tunnel.php?ajax=logs&t=' + Date.now())
                    .then(r => r.text())
                    .then(data => {
                        var el = document.getElementById('logContainer');
                        if (data.trim()) {
                            el.textContent = data;
                            el.scrollTop = el.scrollHeight;
                        }
                    });
            }, 5000);
        </script>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in-delay-2">
    <div class="card-header">
        <h3><i data-lucide="info" class="lucide"></i> How It Works</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
            <div style="padding:16px;background:var(--bg4);border-radius:var(--radius-sm);">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;">1. Create Tunnel</div>
                <div style="font-size:12px;color:var(--text3);line-height:1.6;">Create a Cloudflare Tunnel in your Cloudflare Zero Trust dashboard and add your domain as a public hostname pointing to <code>localhost:8080</code>.</div>
            </div>
            <div style="padding:16px;background:var(--bg4);border-radius:var(--radius-sm);">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;">2. Copy Token</div>
                <div style="font-size:12px;color:var(--text3);line-height:1.6;">Copy the tunnel token from the cloudflared install command in your Cloudflare dashboard.</div>
            </div>
            <div style="padding:16px;background:var(--bg4);border-radius:var(--radius-sm);">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px;">3. Connect Here</div>
                <div style="font-size:12px;color:var(--text3);line-height:1.6;">Paste the token above and click Connect. The server will run cloudflared to route traffic from your domain to your hosting account.</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
