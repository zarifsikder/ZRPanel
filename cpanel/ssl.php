<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('ssl_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'generate') {
        $domain = trim($_POST['domain'] ?? '');
        $days = (int)($_POST['days'] ?? 365);
        if (!empty($domain)) {
            $dn = "/C=US/ST=State/L=City/O=Hosting/CN={$domain}";
            $ssl_dir = sys_get_temp_dir() . '/ssl_' . $user_id . '_' . time();
            @mkdir($ssl_dir, 0700, true);
            $key_file = $ssl_dir . '/key.pem';
            $cert_file = $ssl_dir . '/cert.pem';
            exec("openssl req -x509 -nodes -days {$days} -newkey rsa:2048 -keyout " . escapeshellarg($key_file) . " -out " . escapeshellarg($cert_file) . " -subj " . escapeshellarg($dn) . " 2>&1", $output, $ret);
            if ($ret === 0 && file_exists($cert_file) && file_exists($key_file)) {
                $cert = file_get_contents($cert_file);
                $key = file_get_contents($key_file);
                $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
                $exists = $db->prepare("SELECT id FROM ssl_certs WHERE domain = ? AND user_id = ?");
                $exists->execute([$domain, $user_id]);
                if ($exists->fetch()) {
                    $db->prepare("UPDATE ssl_certs SET cert = ?, key_file = ?, expires_at = ? WHERE domain = ? AND user_id = ?")
                       ->execute([$cert, $key, $expires, $domain, $user_id]);
                } else {
                    $db->prepare("INSERT INTO ssl_certs (user_id, domain, cert, key_file, expires_at) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$user_id, $domain, $cert, $key, $expires]);
                }
                flash('success', "Self-signed SSL certificate generated for {$domain}");
                @unlink($key_file);
                @unlink($cert_file);
                @rmdir($ssl_dir);
            } else {
                flash('error', 'Failed to generate certificate: ' . implode("\n", $output));
            }
        }
    } elseif ($action === 'install') {
        $id = (int)($_POST['id'] ?? 0);
        $cert_row = $db->prepare("SELECT * FROM ssl_certs WHERE id = ? AND user_id = ?");
        $cert_row->execute([$id, $user_id]);
        $cert_data = $cert_row->fetch(PDO::FETCH_ASSOC);
        if ($cert_data && $user && !empty($user['home_dir'])) {
            $home = rtrim($user['home_dir'], '/');
            $ssl_dir = $home . '/.ssl';
            @mkdir($ssl_dir, 0700, true);
            $domain = $cert_data['domain'];
            file_put_contents($ssl_dir . "/{$domain}.crt", $cert_data['cert']);
            file_put_contents($ssl_dir . "/{$domain}.key", $cert_data['key_file']);
            @chmod($ssl_dir . "/{$domain}.key", 0600);

            $conf_dir = $home . '/.apache_conf';
            @mkdir($conf_dir, 0755, true);
            $vhost_conf = $conf_dir . "/ssl_{$domain}.conf";
            $doc_root = $home . '/public_html';
            @mkdir($doc_root, 0755, true);

            $ssl_conf = "<VirtualHost *:443>\n";
            $ssl_conf .= "    ServerName {$domain}\n";
            $ssl_conf .= "    DocumentRoot {$doc_root}\n";
            $ssl_conf .= "    SSLEngine on\n";
            $ssl_conf .= "    SSLCertificateFile {$ssl_dir}/{$domain}.crt\n";
            $ssl_conf .= "    SSLCertificateKeyFile {$ssl_dir}/{$domain}.key\n";
            $ssl_conf .= "    <Directory {$doc_root}>\n";
            $ssl_conf .= "        AllowOverride All\n";
            $ssl_conf .= "        Require all granted\n";
            $ssl_conf .= "    </Directory>\n";
            $ssl_conf .= "    ErrorLog {$home}/logs/ssl_error.log\n";
            $ssl_conf .= "    CustomLog {$home}/logs/ssl_access.log combined\n";
            $ssl_conf .= "</VirtualHost>\n";

            file_put_contents($vhost_conf, $ssl_conf);

            $db->prepare("UPDATE ssl_certs SET status = 'installed' WHERE id = ?")->execute([$id]);
            flash('success', "SSL certificate installed for {$domain}. Cert: {$ssl_dir}/{$domain}.crt | Vhost: {$vhost_conf}");
        } else {
            flash('error', 'Certificate not found or user home directory not set');
        }
    } elseif ($action === 'uninstall') {
        $id = (int)($_POST['id'] ?? 0);
        $cert_row = $db->prepare("SELECT * FROM ssl_certs WHERE id = ? AND user_id = ?");
        $cert_row->execute([$id, $user_id]);
        $cert_data = $cert_row->fetch(PDO::FETCH_ASSOC);
        if ($cert_data && $user && !empty($user['home_dir'])) {
            $home = rtrim($user['home_dir'], '/');
            $domain = $cert_data['domain'];
            @unlink($home . "/.ssl/{$domain}.crt");
            @unlink($home . "/.ssl/{$domain}.key");
            @unlink($home . "/.apache_conf/ssl_{$domain}.conf");
            $db->prepare("UPDATE ssl_certs SET status = NULL WHERE id = ?")->execute([$id]);
            flash('success', "SSL certificate uninstalled for {$domain}");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM ssl_certs WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
        flash('success', 'Certificate deleted');
    } elseif ($action === 'import') {
        $domain = trim($_POST['domain'] ?? '');
        $cert = trim($_POST['cert'] ?? '');
        $key = trim($_POST['private_key'] ?? '');
        if (!empty($domain) && !empty($cert) && !empty($key)) {
            $db->prepare("INSERT INTO ssl_certs (user_id, domain, cert, key_file, expires_at) VALUES (?, ?, ?, ?, ?)")
               ->execute([$user_id, $domain, $cert, $key, date('Y-m-d H:i:s', strtotime('+1 year'))]);
            flash('success', "Certificate imported for {$domain}");
        }
    }
    redirect('/cpanel/ssl.php');
}

$certs = $db->prepare("SELECT * FROM ssl_certs WHERE user_id = ? ORDER BY created_at DESC");
$certs->execute([$user_id]);
$certs = $certs->fetchAll(PDO::FETCH_ASSOC);

$installed_count = 0;
$expiring_soon = 0;
foreach ($certs as $c) {
    if (($c['status'] ?? '') === 'installed') $installed_count++;
    $ts = strtotime($c['expires_at']);
    if ($ts > time() && $ts < strtotime('+30 days')) $expiring_soon++;
}

$nav = 'ssl';
$page_title = 'SSL/TLS Manager';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon <?= $installed_count > 0 ? 'green' : 'blue' ?>"><i data-lucide="lock" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">SSL/TLS Manager</div>
        <div class="hero-desc">Generate or import certificates, then install them to serve your sites securely over HTTPS.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="file-key" class="lucide"></i> <?= count($certs) ?> certificate<?= count($certs) === 1 ? '' : 's' ?></span></div>
</div>

<?php if ($expiring_soon > 0): ?>
<div class="tip-card tip-orange fade-in-delay-1">
    <i data-lucide="clock" class="lucide"></i>
    <div class="tip-body"><strong><?= $expiring_soon ?> certificate<?= $expiring_soon === 1 ? '' : 's' ?> expiring within 30 days.</strong> Renew soon to avoid browser security warnings for your visitors.</div>
</div>
<?php endif; ?>

<?php
$expired_count = 0;
foreach ($certs as $c) {
    if (strtotime($c['expires_at']) <= time()) $expired_count++;
}
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="file-key" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($certs) ?></div>
            <div class="stat-label">Certificates</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">generated or imported</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="shield-check" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $installed_count ?></div>
            <div class="stat-label">Installed &amp; Active</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">serving HTTPS right now</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-2">
        <div class="stat-icon icon-orange"><i data-lucide="clock" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $expiring_soon ?></div>
            <div class="stat-label">Expiring in 30 Days</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">renew before they lapse</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-2">
        <div class="stat-icon icon-purple"><i data-lucide="shield-off" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $expired_count ?></div>
            <div class="stat-label">Expired</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">no longer trusted by browsers</div>
        </div>
    </div>
</div>

<?php if ($expired_count > 0): ?>
<div class="tip-card tip-orange fade-in-delay-1">
    <i data-lucide="alert-triangle" class="lucide"></i>
    <div class="tip-body"><strong><?= $expired_count ?> certificate<?= $expired_count === 1 ? '' : 's' ?> ha<?= $expired_count === 1 ? 's' : 've' ?> already expired.</strong> Visitors will see security warnings until a fresh certificate is installed.</div>
</div>
<?php endif; ?>

<div class="grid-2">
    <div class="card fade-in-delay-1" id="sslGenCard">
        <div class="card-header"><h3><i data-lucide="key-round" class="lucide"></i> Generate Self-Signed Certificate</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="generate">
                <div class="form-group">
                    <label>Domain Name <span class="label-req">*</span></label>
                    <div style="display:flex;gap:0">
                        <input type="text" name="domain" id="sslGenDomain" required placeholder="e.g. example.com" style="border-radius:var(--radius-sm)">
                    </div>
                    <div class="ssl-preview">
                        <span style="font-size:11px;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;font-weight:700">Will be issued to</span>
                        <code id="sslGenPreview">https://example.com</code>
                    </div>
                </div>
                <div class="form-group" style="margin-top:14px">
                    <label>Validity (Days)</label>
                    <input type="number" name="days" value="365" min="30" max="3650">
                    <div class="form-hint"><i data-lucide="info" class="lucide"></i> Self-signed certificates trigger browser warnings &mdash; use a trusted CA for public sites</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px"><i data-lucide="wand-2" class="lucide"></i> Generate Certificate</button>
            </form>
        </div>
    </div>

    <div class="card fade-in-delay-2">
        <div class="card-header"><h3><i data-lucide="upload" class="lucide"></i> Import Certificate</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="import">
                <div class="form-group">
                    <label>Domain Name <span class="label-req">*</span></label>
                    <input type="text" name="domain" required placeholder="e.g. example.com">
                </div>
                <div class="form-group" style="margin-top:14px">
                    <label>Certificate (PEM) <span class="label-req">*</span></label>
                    <textarea name="cert" rows="4" required placeholder="-----BEGIN CERTIFICATE-----" style="width:100%;font-family:monospace;font-size:12px;background:var(--bg4);color:var(--text);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:10px;resize:vertical;line-height:1.5"></textarea>
                </div>
                <div class="form-group" style="margin-top:14px">
                    <label>Private Key (PEM) <span class="label-req">*</span></label>
                    <textarea name="private_key" rows="4" required placeholder="-----BEGIN PRIVATE KEY-----" style="width:100%;font-family:monospace;font-size:12px;background:var(--bg4);color:var(--text);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:10px;resize:vertical;line-height:1.5"></textarea>
                    <div class="form-hint"><i data-lucide="shield-alert" class="lucide"></i> Private keys are stored server-side and never displayed again</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px"><i data-lucide="download" class="lucide"></i> Import Certificate</button>
            </form>
        </div>
    </div>
</div>

<div class="card fade-in-delay-2" style="margin-top:20px">
    <div class="card-header"><h3><i data-lucide="file-lock" class="lucide"></i> Certificates (<?= count($certs) ?>)</h3></div>
    <?php if (!empty($certs)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="sslq" placeholder="Search domains, status..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="sslc"><?= count($certs) ?> certificate<?= count($certs) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($certs) ? '14px' : '16px' ?>">
        <?php if (empty($certs)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="shield-off" class="lucide"></i></div>
                <strong>No certificates yet</strong>
                <p>Generate a self-signed certificate or import one from a certificate authority to enable HTTPS.</p>
                <div class="empty-state-actions">
                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('sslGenCard').scrollIntoView({behavior:'smooth'});document.getElementById('sslGenDomain').focus()"><i data-lucide="key-round" class="lucide"></i> Generate Certificate</button>
                </div>
            </div>
        <?php else: ?>
        <div class="ssl-list">
            <?php foreach ($certs as $c):
                $expired = strtotime($c['expires_at']) <= time();
                $is_installed = ($c['status'] ?? '') === 'installed';
                $status_word = $expired ? 'expired' : ($is_installed ? 'installed' : 'valid');
                $days_left = $is_installed || $expired ? null : ceil((strtotime($c['expires_at']) - time()) / 86400);
            ?>
                <div class="ssl-row" data-name="<?= h(strtolower($c['domain'] . ' ' . $status_word . ' ' . $c['expires_at'])) ?>">
                    <span class="ssl-ic <?= $is_installed ? 'is-on' : ($expired ? 'is-off' : '') ?>"><i data-lucide="<?= $is_installed ? 'shield-check' : 'lock' ?>" class="lucide"></i></span>
                    <div class="ssl-main">
                        <div class="ssl-name">
                            <code><?= h($c['domain']) ?></code>
                            <button type="button" class="ssl-copy" data-copy="<?= h($c['domain']) ?>" title="Copy domain"><i data-lucide="copy" class="lucide"></i></button>
                        </div>
                        <div class="ssl-meta">
                            <?php if ($expired): ?>
                                <span class="badge badge-suspended" style="display:inline-flex;align-items:center;gap:5px"><span class="dot red"></span> Expired</span>
                            <?php elseif ($is_installed): ?>
                                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Installed</span>
                            <?php else: ?>
                                <span class="badge badge-blue" style="display:inline-flex;align-items:center;gap:5px"><span class="dot amber"></span> Valid</span>
                            <?php endif; ?>
                            <span><i data-lucide="clock" class="lucide"></i> Expires <?= date('M d, Y', strtotime($c['expires_at'])) ?></span>
                            <?php if ($days_left !== null): ?>
                                <span><?= $days_left ?> day<?= $days_left === 1 ? '' : 's' ?> left</span>
                            <?php endif; ?>
                            <?php if ($is_installed && $user && !empty($user['home_dir'])): ?>
                                <code class="chip-mono">.ssl/<?= h($c['domain']) ?>.crt</code>
                                <code class="chip-mono">.ssl/<?= h($c['domain']) ?>.key</code>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ssl-actions">
                        <?php if (!$is_installed && !$expired): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Install this certificate and enable HTTPS for <?= h($c['domain']) ?>?')">
                            <input type="hidden" name="action" value="install">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Install"><i data-lucide="shield-check" class="lucide"></i> Install</button>
                        </form>
                        <?php elseif ($is_installed): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Uninstall this certificate? Your site will no longer be served over HTTPS.')">
                            <input type="hidden" name="action" value="uninstall">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-warning" title="Uninstall"><i data-lucide="unlock" class="lucide"></i> Uninstall</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete this certificate?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
.ssl-preview{display:flex;align-items:center;gap:10px;margin-top:10px;background:var(--bg2);border:1px dashed var(--border);border-radius:var(--radius-sm);padding:8px 10px}
.ssl-preview code{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ssl-list{display:flex;flex-direction:column;gap:10px}
.ssl-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.ssl-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.ssl-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0062bd,#0073e6 55%,#1e8ff0);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.ssl-ic.is-on{background:linear-gradient(135deg,#047857,#059669 55%,#10b981);box-shadow:0 4px 10px rgba(5,150,105,.18)}
.ssl-ic.is-off{background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444);box-shadow:0 4px 10px rgba(220,38,38,.18)}
.ssl-ic .lucide{width:19px;height:19px}
.ssl-main{min-width:0;flex:1}
.ssl-name{display:flex;align-items:center;gap:7px}
.ssl-name code{font-weight:700;font-size:13px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ssl-copy{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.ssl-copy .lucide{width:12px;height:12px}
.ssl-copy:hover{background:rgba(0,115,230,.1);color:var(--primary)}
.ssl-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.ssl-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.ssl-meta .badge{font-size:10px;padding:3px 8px}
.ssl-meta .lucide{width:12px;height:12px;vertical-align:-2px}
.ssl-actions{display:flex;gap:6px;flex-shrink:0}
@media(max-width:720px){
  .ssl-row{flex-wrap:wrap}
  .ssl-main{flex-basis:100%}
  .ssl-actions{margin-left:0;width:100%}
  .ssl-actions form{flex:1}
  .ssl-actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function () {
    var input = document.getElementById('sslq');
    var count = document.getElementById('sslc');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.ssl-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' of ' + rows.length + ' certificate' + (rows.length === 1 ? '' : 's');
        });
    }
    var domainInput = document.getElementById('sslGenDomain');
    var preview = document.getElementById('sslGenPreview');
    if (domainInput && preview) {
        var show = function () {
            var v = (domainInput.value || '').trim();
            preview.textContent = 'https://' + (v || 'example.com');
        };
        domainInput.addEventListener('input', show);
        show();
    }
    document.querySelectorAll('.ssl-copy').forEach(function (btn) {
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