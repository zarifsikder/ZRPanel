<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_password');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        csrf_fail();
    }
    $action = strtolower(trim($_POST['action'] ?? ''));

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new_pass) || empty($confirm)) {
            flash('error', 'All password fields are required');
            redirect('/whm/password.php');
        }

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Current password is incorrect');
            redirect('/whm/password.php');
        }

        if (strlen($new_pass) < 8) {
            flash('error', 'New password must be at least 8 characters');
            redirect('/whm/password.php');
        }

        if ($new_pass === $current) {
            flash('error', 'New password must be different from current password');
            redirect('/whm/password.php');
        }

        if ($new_pass !== $confirm) {
            flash('error', 'New password and confirmation do not match');
            redirect('/whm/password.php');
        }

        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new_hash, $user_id]);

        flash('success', 'WHM password changed successfully');
        redirect('/whm/password.php');
    }

    if ($action === 'change_username') {
        $current = $_POST['current_password'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $confirm = trim($_POST['confirm_username'] ?? '');
        $old_name = $user['username'] ?? '';

        if (empty($current) || empty($username) || empty($confirm)) {
            flash('error', 'All username fields are required');
            redirect('/whm/password.php');
        }

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Current password is incorrect');
            redirect('/whm/password.php');
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            flash('error', 'Username must be 3-32 alphanumeric characters (letters, digits, underscore)');
            redirect('/whm/password.php');
        }

        if ($username === $old_name) {
            flash('error', 'New username must be different from the current username');
            redirect('/whm/password.php');
        }

        if ($username !== $confirm) {
            flash('error', 'Username and confirmation do not match');
            redirect('/whm/password.php');
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetch()) {
            flash('error', "Username '{$username}' is already taken");
            redirect('/whm/password.php');
        }

        $db->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$username, $user_id]);
        $_SESSION['username'] = $username;

        flash('success', 'WHM username changed to "' . $username . '"');
        redirect('/whm/password.php');
    }
    redirect('/whm/password.php');
}

$nav = 'password';
$page_title = 'Password Change';
require_once __DIR__ . '/../templates/header.php';
?>

<?php if ($flash = flash('success')): ?>
    <div class="alert alert-success"><i data-lucide="check-circle" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>
<?php if ($flash = flash('error')): ?>
    <div class="alert alert-error"><i data-lucide="alert-triangle" class="lucide"></i> <?= h($flash) ?></div>
<?php endif; ?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="key-round" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Password Change</div>
        <div class="hero-desc">Change the password for this WHM administrator account. Use a strong, unique password that you do not use anywhere else.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-blue"><i data-lucide="user" class="lucide"></i> <?= h($user['username']) ?></span>
    </div>
</div>

<div class="grid-2">
    <div class="card fade-in" style="grid-column:1/-1">
        <div class="card-header">
            <h3><i data-lucide="user-cog" class="lucide"></i> WHM Administrator</h3>
        </div>
        <div class="card-body">
            <div class="grid-2">
                <div>
                    <div class="info-row">
                        <span class="info-label"><i data-lucide="at-sign" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Username</span>
                        <span class="info-value"><?= h($user['username']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i data-lucide="mail" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Email</span>
                        <span class="info-value"><?= h($user['email'] ?: 'Not set') ?></span>
                    </div>
                </div>
                <div>
                    <div class="info-row">
                        <span class="info-label"><i data-lucide="shield" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Role</span>
                        <span class="info-value"><span class="badge badge-active"><?= h(ucfirst($user['role'])) ?></span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i data-lucide="calendar" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Account Created</span>
                        <span class="info-value"><?= h(date('M d, Y', strtotime($user['created_at'] ?? date('Y-m-d H:i:s')))) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card fade-in" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="key-round" class="lucide"></i> Change Password</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid" id="passwordForm">
            <input type="hidden" name="action" value="change_password">
            <?= csrf_field() ?>
            <div class="form-group" style="grid-column:1/-1">
                <label>Current Password</label>
                <input type="password" name="current_password" id="currentPassword" required placeholder="Enter current password" autocomplete="current-password">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>New Password</label>
                <div style="position:relative">
                    <input type="password" name="new_password" id="newPassword" required placeholder="Enter new password" autocomplete="new-password" oninput="checkStrength(this.value)" style="padding-right:44px">
                    <button type="button" onclick="toggleVisibility('newPassword', this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;padding:4px"><i data-lucide="eye" class="lucide" style="width:18px;height:18px"></i></button>
                </div>
                <div class="strength-bar"><div class="strength-bar-fill" id="strengthBar" style="width:0;background:transparent"></div></div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
                    <span id="strengthText" style="font-size:12px;font-weight:600;color:var(--text4)"></span>
                    <button type="button" class="gen-btn" onclick="generatePassword()"><i data-lucide="sparkles" class="lucide" style="width:13px;height:13px"></i> Generate Strong Password</button>
                </div>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Confirm New Password</label>
                <div style="position:relative">
                    <input type="password" name="confirm_password" id="confirmPassword" required placeholder="Confirm new password" autocomplete="new-password" oninput="checkMatch()" style="padding-right:44px">
                    <button type="button" onclick="toggleVisibility('confirmPassword', this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;padding:4px"><i data-lucide="eye" class="lucide" style="width:18px;height:18px"></i></button>
                </div>
                <span id="matchText" style="font-size:12px;margin-top:4px;display:block"></span>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Password Requirements</label>
                <div style="padding:12px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm)">
                    <div class="req-item" id="req-length"><i data-lucide="circle" class="lucide"></i> Minimum 8 characters</div>
                    <div class="req-item" id="req-upper"><i data-lucide="circle" class="lucide"></i> At least one uppercase letter (A-Z)</div>
                    <div class="req-item" id="req-lower"><i data-lucide="circle" class="lucide"></i> At least one lowercase letter (a-z)</div>
                    <div class="req-item" id="req-number"><i data-lucide="circle" class="lucide"></i> At least one number (0-9)</div>
                    <div class="req-item" id="req-special"><i data-lucide="circle" class="lucide"></i> At least one special character (!@#$%^&*)</div>
                </div>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <button type="submit" class="btn btn-primary" id="submitBtn"><i data-lucide="save" class="lucide"></i> Change Password</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="user-cog" class="lucide"></i> Change Username</h3>
    </div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--text3);margin-bottom:12px">Rename this WHM administrator account. Minimum 3 and maximum 32 letters, digits or underscores. Your current username is <strong><?= h($user['username']) ?></strong>.</p>
        <form method="POST" class="form-grid" style="max-width:460px">
            <input type="hidden" name="action" value="change_username">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required placeholder="Enter current password" autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>New Username</label>
                <input type="text" name="username" required placeholder="New username" autocomplete="username" minlength="3" maxlength="32" spellcheck="false">
            </div>
            <div class="form-group">
                <label>Confirm New Username</label>
                <input type="text" name="confirm_username" required placeholder="Confirm new username" autocomplete="username" minlength="3" maxlength="32" spellcheck="false">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Change Username</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="shield-check" class="lucide"></i> Security Tips</h3>
    </div>
    <div class="card-body" style="padding:0">
        <table class="table">
            <tr>
                <td style="padding:13px 16px;width:44%">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div class="rec-icon" style="background:rgba(16,185,129,.15);border-radius:8px;width:34px;height:34px;display:flex;align-items:center;justify-content:center">
                            <i data-lucide="check-circle" class="lucide" style="width:18px;height:18px;color:#10b981"></i>
                        </div>
                        <strong style="font-size:13px">Use a Strong, Unique Password</strong>
                    </div>
                </td>
                <td style="font-size:13px;color:var(--text3)">At least 12 characters with a mix of uppercase, lowercase, numbers and symbols keeps guessing attacks impractical.</td>
            </tr>
            <tr>
                <td style="padding:13px 16px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div class="rec-icon" style="background:rgba(245,158,11,.15);border-radius:8px;width:34px;height:34px;display:flex;align-items:center;justify-content:center">
                            <i data-lucide="alert-triangle" class="lucide" style="width:18px;height:18px;color:#f59e0b"></i>
                        </div>
                        <strong style="font-size:13px">Don't Reuse Passwords</strong>
                    </div>
                </td>
                <td style="font-size:13px;color:var(--text3)">Never reuse this password on other sites or customer cPanel accounts. A breach elsewhere could compromise the whole server.</td>
            </tr>
            <tr>
                <td style="padding:13px 16px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div class="rec-icon" style="background:rgba(59,130,246,.15);border-radius:8px;width:34px;height:34px;display:flex;align-items:center;justify-content:center">
                            <i data-lucide="eye-off" class="lucide" style="width:18px;height:18px;color:#3b82f6"></i>
                        </div>
                        <strong style="font-size:13px">Keep It Private</strong>
                    </div>
                </td>
                <td style="font-size:13px;color:var(--text3)">This credential grants full server control through WHM. Never share it, and log out when finished on shared devices.</td>
            </tr>
        </table>
    </div>
</div>

<style>
.req-item{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text4);padding:3px 0}
.req-item .lucide{width:13px;height:13px}
.req-item.met{color:#10b981}
.req-item.met .lucide{color:#10b981}
.strength-bar{height:6px;border-radius:99px;background:var(--border);overflow:hidden;margin-top:8px}
.strength-bar-fill{height:100%;border-radius:99px;transition:width .2s ease,background .2s ease}
.gen-btn{background:none;border:none;color:var(--accent);font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;padding:4px 8px;border-radius:8px;transition:background .15s}
.gen-btn:hover{background:rgba(99,102,241,.12)}
.info-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)}
.info-row:last-child{border-bottom:none}
.info-label{font-size:12.5px;color:var(--text3);display:flex;align-items:center;gap:7px}
.info-value{font-size:13px;font-weight:600;color:var(--text);word-break:break-all}
.badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
</style>

<script>
function checkStrength(pw) {
    var score = 0;
    if (pw.length >= 8) score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    var bar = document.getElementById('strengthBar');
    var text = document.getElementById('strengthText');

    document.getElementById('req-length').className = 'req-item' + (pw.length >= 8 ? ' met' : '');
    document.getElementById('req-upper').className = 'req-item' + (/[A-Z]/.test(pw) ? ' met' : '');
    document.getElementById('req-lower').className = 'req-item' + (/[a-z]/.test(pw) ? ' met' : '');
    document.getElementById('req-number').className = 'req-item' + (/[0-9]/.test(pw) ? ' met' : '');
    document.getElementById('req-special').className = 'req-item' + (/[^A-Za-z0-9]/.test(pw) ? ' met' : '');

    var levels = [
        { max: 2, label: 'Weak', color: '#ef4444', width: '25%' },
        { max: 3, label: 'Medium', color: '#f59e0b', width: '50%' },
        { max: 4, label: 'Strong', color: '#3b82f6', width: '75%' },
        { max: 99, label: 'Very Strong', color: '#10b981', width: '100%' }
    ];

    if (pw.length === 0) {
        bar.style.width = '0';
        bar.style.background = 'transparent';
        text.textContent = '';
        text.style.color = 'var(--text4)';
        return;
    }

    for (var i = 0; i < levels.length; i++) {
        if (score <= levels[i].max) {
            bar.style.width = levels[i].width;
            bar.style.background = levels[i].color;
            text.textContent = levels[i].label;
            text.style.color = levels[i].color;
            break;
        }
    }
}

function checkMatch() {
    var nw = document.getElementById('newPassword').value;
    var cf = document.getElementById('confirmPassword').value;
    var el = document.getElementById('matchText');
    if (cf.length === 0) { el.textContent = ''; return; }
    if (nw === cf) {
        el.textContent = 'Passwords match';
        el.style.color = '#10b981';
    } else {
        el.textContent = 'Passwords do not match';
        el.style.color = '#ef4444';
    }
}

function toggleVisibility(id, btn) {
    var inp = document.getElementById(id);
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.innerHTML = '<i data-lucide="eye-off" class="lucide" style="width:18px;height:18px"></i>';
    } else {
        inp.type = 'password';
        btn.innerHTML = '<i data-lucide="eye" class="lucide" style="width:18px;height:18px"></i>';
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function generatePassword() {
    var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';
    var pw = '';
    var arr = new Uint32Array(20);
    crypto.getRandomValues(arr);
    for (var i = 0; i < 20; i++) pw += chars[arr[i] % chars.length];
    var el = document.getElementById('newPassword');
    el.value = pw;
    el.type = 'text';
    checkStrength(pw);
    checkMatch();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>