<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_accounts');
init_db();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $package_id = (int)($_POST['package_id'] ?? 0);
        $custom_domain = strtolower(trim($_POST['custom_domain'] ?? ''));
        $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));

        $gd = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
        $server_domain = $gd['value'] ?? SITE_DOMAIN;

        if ($custom_domain) {
            $domain = $custom_domain;
        } elseif ($subdomain) {
            $domain = $subdomain . '.' . $server_domain;
        } else {
            $domain = strtolower($username) . '.' . $server_domain;
        }

        if (empty($username) || empty($password)) {
            flash('error', 'Username and password are required');
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            flash('error', 'Username must be 3-32 alphanumeric characters');
        } elseif ($custom_domain && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,}$/', $custom_domain)) {
            flash('error', 'Invalid custom domain format');
        } elseif ($subdomain && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $subdomain)) {
            flash('error', 'Invalid subdomain format');
        } else {
            $existing = $db->prepare("SELECT id FROM users WHERE username = ?");
            $existing->execute([$username]);
            if ($existing->fetch()) {
                flash('error', 'Username already exists');
            } else {
                $existing_domain = $db->prepare("SELECT id FROM users WHERE domain = ?");
                $existing_domain->execute([$domain]);
                if ($existing_domain->fetch()) {
                    flash('error', "Domain '{$domain}' is already assigned");
                } else {
                    $home = "/sdcard/Download/Hosting/user_data/{$username}";
                    if (!is_dir($home)) mkdir($home, 0755, true);
                    if (!is_dir("{$home}/public_html")) mkdir("{$home}/public_html", 0755, true);
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, password, email, domain, role, package_id, home_dir) VALUES (?, ?, ?, ?, 'cpanel', ?, ?)");
                    $stmt->execute([$username, $hash, $email, $domain, $package_id ?: null, $home]);
                    file_put_contents("{$home}/public_html/index.php", "<!DOCTYPE html>\n<html><head><title>Welcome to {$domain}</title></head><body>\n<h1>Welcome, " . htmlspecialchars($username) . "!</h1>\n<p>Your hosting account <strong>" . htmlspecialchars($domain) . "</strong> is ready.</p>\n</body></html>");

                    $symlink = __DIR__ . '/../user_data/' . $username;
                    if (!is_link($symlink) && !is_dir($symlink)) {
                        @symlink($home . '/public_html', $symlink);
                    }

                    $server_ip = SERVER_IP;
                    if ($server_ip && $server_ip !== '127.0.0.1') {
                        $db->prepare("INSERT INTO dns_records (user_id, domain, name, type, content, ttl) VALUES (0, ?, '@', 'A', ?, 3600)")
                           ->execute([$domain, $server_ip]);
                    }

                    $msg = "Account '{$username}' created — domain: {$domain}";

                    if (CF_ENABLED) {
                        $cf_errors = [];
                        $cf_success = [];

                        if ($custom_domain) {
                            $tunnel_cname = CF_TUNNEL_ID . '.cfargotunnel.com';
                            $cf_zone_id = cloudflare_get_zone_id($custom_domain);

                            if ($cf_zone_id) {
                                $cf_a = cloudflare_create_dns_in_zone($cf_zone_id, 'CNAME', $custom_domain, $tunnel_cname, 3600, true);
                                $cf_cname = cloudflare_create_dns_in_zone($cf_zone_id, 'CNAME', "www.{$custom_domain}", $custom_domain, 3600, true);

                                if ($cf_a['success']) $cf_success[] = 'CNAME';
                                else $cf_errors[] = "CNAME: " . ($cf_a['error'] ?? 'Unknown');
                                if ($cf_cname['success']) $cf_success[] = 'www CNAME';
                                else $cf_errors[] = "www CNAME: " . ($cf_cname['error'] ?? 'Unknown');
                            } else {
                                $msg .= " — DNS not configured: add {$custom_domain} to Cloudflare first, then create an A record pointing to " . SERVER_IP;
                            }
                        } else {
                            $tunnel_cname = CF_TUNNEL_ID . '.cfargotunnel.com';
                            $cf_a = cloudflare_create_dns('CNAME', $domain, $tunnel_cname, 3600, true);
                            $cf_cname = cloudflare_create_dns('CNAME', "www.{$domain}", $domain, 3600, true);

                            if ($cf_a['success']) $cf_success[] = 'CNAME';
                            else $cf_errors[] = "CNAME: " . ($cf_a['error'] ?? 'Unknown');
                            if ($cf_cname['success']) $cf_success[] = 'www CNAME';
                            else $cf_errors[] = "www CNAME: " . ($cf_cname['error'] ?? 'Unknown');
                        }

                        if (!empty($cf_errors)) {
                            $msg .= " — Cloudflare: " . implode('; ', $cf_errors);
                            if (!empty($cf_success)) $msg .= " (" . implode(', ', $cf_success) . " created)";
                            flash('error', $msg);
                        } elseif (!empty($cf_success)) {
                            $msg .= " — Cloudflare DNS synced (" . implode(', ', $cf_success) . ")";
                            flash('success', $msg);
                        } else {
                            flash('success', $msg);
                        }
                    } else {
                        flash('success', $msg);
                    }
                    clear_customer_site_cache();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $user = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'cpanel'");
            $user->execute([$id]);
            $user = $user->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $home = $user['home_dir'] ?? '';
                $username = $user['username'];
                $domain = $user['domain'] ?? '';

                try {
                    cloudflare_delete_dns_by_name($username . '.dzhost.shop');
                    if ($domain && strpos($domain, 'dzhost.shop') === false) {
                        $cf_zone_id = cloudflare_get_zone_id($domain);
                        if ($cf_zone_id) {
                            cloudflare_delete_dns_by_name_in_zone($cf_zone_id, $domain);
                            cloudflare_delete_dns_by_name_in_zone($cf_zone_id, "www.{$domain}");
                        }
                    }
                } catch (Exception $e) {
                    Debug::logError("Cloudflare DNS cleanup failed: " . $e->getMessage());
                }

                $db->prepare("DELETE FROM subdomains WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM ftp_accounts WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM cron_jobs WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM redirects WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM ssl_certs WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM dns_records WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM error_pages WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM backups WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                clear_customer_site_cache();
                if ($home && is_dir($home)) {
                    exec("rm -rf " . escapeshellarg($home));
                }
                $symlink = __DIR__ . '/../user_data/' . $username;
                if (is_link($symlink)) @unlink($symlink);
                $db->prepare("DELETE FROM dns_records WHERE domain = ?")->execute([$domain]);
                flash('success', "Account '{$username}' permanently deleted");
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'cpanel'");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $new_status = $user['status'] === 'active' ? 'suspended' : 'active';
            $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            clear_customer_site_cache();
            flash('success', "Account '{$user['username']}' {$new_status}");
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $new_pass = $_POST['new_password'] ?? '';
        if ($id > 0 && !empty($new_pass)) {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $id]);
            flash('success', 'Password reset successfully');
        }
    } elseif ($action === 'login_as') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $target = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'cpanel'");
            $target->execute([$id]);
            $target = $target->fetch(PDO::FETCH_ASSOC);
            if ($target) {
                $_SESSION['impersonate_whm'] = $_SESSION['user_id'];
                $_SESSION['user_id'] = $target['id'];
                $_SESSION['username'] = $target['username'];
                $_SESSION['role'] = $target['role'];
                $_SESSION['package_id'] = $target['package_id'];
                redirect('/cpanel/');
            }
        }
    } elseif ($action === 'stop_impersonate') {
        if (isset($_SESSION['impersonate_whm'])) {
            $whm_id = $_SESSION['impersonate_whm'];
            unset($_SESSION['impersonate_whm']);
            $whm_user = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'whm'");
            $whm_user->execute([$whm_id]);
            $whm_user = $whm_user->fetch(PDO::FETCH_ASSOC);
            if ($whm_user) {
                $_SESSION['user_id'] = $whm_user['id'];
                $_SESSION['username'] = $whm_user['username'];
                $_SESSION['role'] = $whm_user['role'];
                unset($_SESSION['package_id']);
            }
            redirect('/whm/accounts.php');
        }
        redirect('/whm/');
    } elseif ($action === 'change_package') {
        $id = (int)($_POST['id'] ?? 0);
        $new_package_id = (int)($_POST['new_package_id'] ?? 0);
        if ($id > 0) {
            $user = $db->prepare("SELECT u.username, p.name as old_pkg FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.id = ? AND u.role = 'cpanel'");
            $user->execute([$id]);
            $info = $user->fetch(PDO::FETCH_ASSOC);
            if ($info) {
                $pkg_name = null;
                if ($new_package_id > 0) {
                    $pkg = $db->prepare("SELECT name FROM packages WHERE id = ?");
                    $pkg->execute([$new_package_id]);
                    $pkg_name = $pkg->fetchColumn();
                }
                $db->prepare("UPDATE users SET package_id = ? WHERE id = ?")->execute([$new_package_id ?: null, $id]);
                $old = $info['old_pkg'] ?: 'Unlimited';
                $new = $pkg_name ?: 'Unlimited';
                flash('success', "Package changed for '{$info['username']}': {$old} → {$new}");
            }
        }
    }
    redirect('/whm/accounts.php');
}

$gd = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
$server_domain = $gd['value'] ?? SITE_DOMAIN;

$accounts = $db->query("SELECT u.*, p.name as package_name, p.disk_quota FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.role = 'cpanel' ORDER BY u.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$packages = $db->query("SELECT * FROM packages ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$total_accounts = count($accounts);
$active_accounts = 0;
$suspended_accounts = 0;
foreach ($accounts as $a) {
    if ($a['status'] === 'active') $active_accounts++;
    elseif ($a['status'] === 'suspended') $suspended_accounts++;
}

$nav = 'accounts';
$page_title = 'Manage Accounts';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="users" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Accounts</div>
        <div class="hero-desc">Create and manage cPanel hosting accounts. Assign packages, suspend access, reset passwords or jump into a session in one click.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue"><i data-lucide="users" class="lucide"></i> <?= $total_accounts ?> account<?= $total_accounts === 1 ? '' : 's' ?></span></div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_accounts ?></div>
            <div class="stat-label">Total Accounts</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">cPanel accounts</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="check-circle" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $active_accounts ?></div>
            <div class="stat-label">Active Accounts</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">live on this server</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="shield-off" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $suspended_accounts ?></div>
            <div class="stat-label">Suspended Accounts</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">temporarily disabled</div>
        </div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="plus" class="lucide"></i> Create New Account</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="createUsername" required pattern="[a-zA-Z0-9_]{3,32}" placeholder="e.g. john" oninput="updatePreview()">
            </div>
            <div class="form-group">
                <label>Domain Type</label>
                <div style="display:flex;gap:0;border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid var(--border)">
                    <button type="button" id="btnSubdomain" onclick="setDomainType('subdomain')" style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-family:var(--font);font-weight:500;background:var(--text);color:#fff;transition:all .2s">Subdomain</button>
                    <button type="button" id="btnCustom" onclick="setDomainType('custom')" style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-family:var(--font);font-weight:500;background:var(--bg4);color:var(--text3);border-left:1.5px solid var(--border);transition:all .2s">Custom Domain</button>
                </div>
                <input type="hidden" name="domain_type" id="domainType" value="subdomain">
            </div>
            <div class="form-group" id="subdomainField">
                <label>Subdomain</label>
                <div style="display:flex;gap:0">
                    <input type="text" name="subdomain" id="createSubdomain" placeholder="e.g. blog" oninput="updatePreview()" style="border-radius:var(--radius-sm) 0 0 var(--radius-sm)">
                    <span style="display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-left:0;border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-size:13px;color:var(--text3);white-space:nowrap">.<?= h($server_domain) ?></span>
                </div>
            </div>
            <div class="form-group" id="customDomainField" style="display:none">
                <label>Custom Domain</label>
                <input type="text" name="custom_domain" id="createCustomDomain" placeholder="e.g. example.com" oninput="updatePreview()">
            </div>
            <div style="font-size:11px;color:var(--text4);margin-top:-8px;margin-bottom:4px">Will be created as: <span id="domainPreview" style="font-family:monospace;color:var(--text2)">john.<?= h($server_domain) ?></span></div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required minlength="6" placeholder="Min 6 characters">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="user@example.com">
            </div>
            <div class="form-group">
                <label>Package</label>
                <select name="package_id">
                    <option value="0">-- Unlimited --</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Create Account</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header">
        <h3><i data-lucide="users" class="lucide"></i> All Accounts (<?= count($accounts) ?>)</h3>
    </div>
    <?php if (!empty($accounts)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="accSearch" placeholder="Search username, domain or email..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="accCount"><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($accounts) ? '14px' : '16px' ?>">
        <?php if (empty($accounts)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="user" class="lucide"></i></div>
                <strong>No accounts created yet</strong>
                <p>Use the form above to create your first hosting account.</p>
            </div>
        <?php else: ?>
            <div class="acc-list">
            <?php foreach ($accounts as $a): ?>
                <div class="acc-row" data-name="<?= h($a['username'] . ' ' . $a['domain'] . ' ' . ($a['email'] ?? '')) ?>">
                    <span class="acc-ic"><i data-lucide="user" class="lucide"></i></span>
                    <div class="acc-main">
                        <div class="acc-name">
                            <?= h($a['username']) ?>
                        </div>
                        <div class="acc-meta">
                            <span class="acc-domain"><?= h($a['domain'] ?: 'No domain') ?></span>
                            <span class="acc-status <?= $a['status'] === 'active' ? 'is-active' : ($a['status'] === 'suspended' ? 'is-suspended' : '') ?>"><span class="dot"></span> <?= h(ucfirst($a['status'])) ?></span>
                            <span><?= h($a['email'] ?? '-') ?></span>
                            <span class="badge badge-purple"><?= h($a['package_name'] ?? 'Unlimited') ?></span>
                            <span><?= $a['disk_quota'] ? format_size($a['disk_quota']) : '&#8734;' ?> disk</span>
                            <span>Created <?= date('M d, Y', strtotime($a['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="acc-actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="login_as">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Login to cPanel"><i data-lucide="log-in" class="lucide"></i> Login</button>
                        </form>
                        <form method="POST" style="display:inline" class="acc-pkg-form">
                            <input type="hidden" name="action" value="change_package">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <select name="new_package_id" class="input-sm" style="width:auto;display:inline-block;vertical-align:middle">
                                <option value="0" <?= !$a['package_id'] ? 'selected' : '' ?>>Unlimited</option>
                                <?php foreach ($packages as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $a['package_id'] == $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= format_size($p['disk_quota']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Change</button>
                        </form>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $a['status'] === 'active' ? 'btn-warning' : 'btn-success' ?>">
                                <i data-lucide="<?= $a['status'] === 'active' ? 'shield-off' : 'shield-check' ?>" class="lucide"></i> <?= $a['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                            </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-info" onclick="openResetPass(<?= $a['id'] ?>,'<?= h($a['username']) ?>')"><i data-lucide="key-round" class="lucide"></i> Reset</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('⚠️ WARNING: This will PERMANENTLY delete the account \'<?= h($a['username']) ?>\' and ALL its data (files, databases, emails, subdomains). This cannot be undone. Are you sure?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="resetPassModal">
    <div class="modal-box">
        <div class="modal-top">
            <h3><i data-lucide="key-round" class="lucide"></i> Reset Password</h3>
            <button class="modal-x" onclick="document.getElementById('resetPassModal').classList.remove('open')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="resetPassId">
                <p style="font-size:13px;color:var(--text3);margin-bottom:14px">Set a new password for <strong id="resetPassUser"></strong></p>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="resetPassInput" required minlength="6" placeholder="Min 6 characters">
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('resetPassModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save Password</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<style>
.acc-list{display:flex;flex-direction:column;gap:10px}
.acc-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.acc-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.acc-ic{width:42px;height:42px;min-width:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0062bd,#0073e6 55%,#1e8ff0);color:#fff;box-shadow:0 4px 10px rgba(0,115,230,.18)}
.acc-ic .lucide{width:19px;height:19px}
.acc-main{min-width:0;flex:1}
.acc-name{font-weight:700;color:var(--text);font-size:13.5px;display:flex;align-items:center;gap:8px}
.acc-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.acc-meta .badge{font-size:10px;padding:3px 8px}
.acc-domain{font-family:'Fira Code',monaco,consolas,monospace;font-size:12px;color:var(--text2);font-weight:600}
.acc-status{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700}
.acc-status .dot{width:7px;height:7px;border-radius:50%}
.acc-status.is-active{color:var(--success)}
.acc-status.is-active .dot{background:var(--success);box-shadow:0 0 5px rgba(5,150,105,.45)}
.acc-status.is-suspended{color:var(--danger)}
.acc-status.is-suspended .dot{background:var(--danger);box-shadow:0 0 5px rgba(220,38,38,.45)}
.acc-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;flex-shrink:0}
@media(max-width:720px){
  .acc-row{flex-wrap:wrap}
  .acc-main{flex-basis:100%}
  .acc-actions{margin-left:0;width:100%}
}
</style>

<script>
(function () {
    var input = document.getElementById('accSearch');
    var count = document.getElementById('accCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.acc-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' account' + (shown === 1 ? '' : 's');
        });
    }
})();
</script>

<script>
function openResetPass(id, username) {
    document.getElementById('resetPassId').value = id;
    document.getElementById('resetPassUser').textContent = username;
    document.getElementById('resetPassInput').value = '';
    document.getElementById('resetPassModal').classList.add('open');
    if (typeof lucide !== 'undefined') lucide.createIcons();
    setTimeout(function(){ document.getElementById('resetPassInput').focus(); }, 100);
}
</script>

<script>
function setDomainType(type) {
    document.getElementById('domainType').value = type;
    var sub = document.getElementById('subdomainField');
    var custom = document.getElementById('customDomainField');
    var btnSub = document.getElementById('btnSubdomain');
    var btnCus = document.getElementById('btnCustom');
    if (type === 'subdomain') {
        sub.style.display = '';
        custom.style.display = 'none';
        document.getElementById('createCustomDomain').value = '';
        btnSub.style.background = 'var(--text)'; btnSub.style.color = '#fff';
        btnCus.style.background = 'var(--bg4)'; btnCus.style.color = 'var(--text3)';
    } else {
        sub.style.display = 'none';
        custom.style.display = '';
        document.getElementById('createSubdomain').value = '';
        btnCus.style.background = 'var(--text)'; btnCus.style.color = '#fff';
        btnSub.style.background = 'var(--bg4)'; btnSub.style.color = 'var(--text3)';
    }
    updatePreview();
}
function updatePreview() {
    var u = document.getElementById('createUsername').value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '') || 'username';
    var type = document.getElementById('domainType').value;
    if (type === 'custom') {
        var cd = document.getElementById('createCustomDomain').value.trim().toLowerCase().replace(/[^a-z0-9.\-]/g, '');
        document.getElementById('domainPreview').textContent = cd || 'example.com';
    } else {
        var s = document.getElementById('createSubdomain').value.trim().toLowerCase().replace(/[^a-z0-9\-]/g, '');
        document.getElementById('domainPreview').textContent = (s || u) + '.<?= h($server_domain) ?>';
    }
}
</script>
