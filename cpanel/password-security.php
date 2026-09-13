<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT id, username, email, role, home_dir, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new_pass) || empty($confirm)) {
            flash('error', 'All password fields are required');
            redirect('/cpanel/password-security.php');
        }

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Current password is incorrect');
            redirect('/cpanel/password-security.php');
        }

        if (strlen($new_pass) < 8) {
            flash('error', 'New password must be at least 8 characters');
            redirect('/cpanel/password-security.php');
        }

        if ($new_pass === $current) {
            flash('error', 'New password must be different from current password');
            redirect('/cpanel/password-security.php');
        }

        if ($new_pass !== $confirm) {
            flash('error', 'New password and confirmation do not match');
            redirect('/cpanel/password-security.php');
        }

        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new_hash, $user_id]);

        $db->prepare("UPDATE users SET created_at = created_at WHERE id = ?")->execute([$user_id]);

        flash('success', 'Password changed successfully');
        redirect('/cpanel/password-security.php');
    }
    redirect('/cpanel/password-security.php');
}

$created_at = $user['created_at'] ?? date('Y-m-d H:i:s');
$last_login = date('M d, Y \a\t g:i A', strtotime($created_at));

$nav = 'passwordsecurity';
$page_title = 'Password & Security';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="key-round" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Password &amp; Security</div>
        <div class="hero-desc">Review your account details, change your password, and harden your account against unauthorized access.</div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="user" class="lucide"></i> Account Information</h3>
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
                <div class="info-row">
                    <span class="info-label"><i data-lucide="shield" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Role</span>
                    <span class="info-value"><span class="badge badge-active"><?= h(ucfirst($user['role'])) ?></span></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label"><i data-lucide="calendar" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Account Created</span>
                    <span class="info-value"><?= h(date('M d, Y', strtotime($created_at))) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i data-lucide="clock" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Last Login</span>
                    <span class="info-value"><?= h($last_login) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i data-lucide="key" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i> Password Changed</span>
                    <span class="info-value">At account creation</span>
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

<div class="card fade-in-delay-1" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="shield-check" class="lucide"></i> Security Recommendations</h3>
    </div>
    <div class="card-body">
        <div class="rec-card">
            <div class="rec-icon" style="background:rgba(99,102,241,0.15)">
                <i data-lucide="smartphone" class="lucide" style="width:20px;height:20px;color:#6366f1"></i>
            </div>
            <div style="flex:1">
                <div class="rec-title">Enable Two-Factor Authentication</div>
                <div class="rec-desc">Add an extra layer of security by requiring a verification code from your phone in addition to your password.</div>
            </div>
            <a href="/cpanel/two-factor.php" class="btn btn-sm btn-primary">Enable</a>
        </div>
        <div class="rec-card">
            <div class="rec-icon" style="background:rgba(16,185,129,0.15)">
                <i data-lucide="check-circle" class="lucide" style="width:20px;height:20px;color:#10b981"></i>
            </div>
            <div style="flex:1">
                <div class="rec-title">Use a Strong, Unique Password</div>
                <div class="rec-desc">Use a password that is at least 12 characters long with a mix of uppercase, lowercase, numbers, and symbols.</div>
            </div>
            <span class="badge badge-active">Active</span>
        </div>
        <div class="rec-card">
            <div class="rec-icon" style="background:rgba(245,158,11,0.15)">
                <i data-lucide="alert-triangle" class="lucide" style="width:20px;height:20px;color:#f59e0b"></i>
            </div>
            <div style="flex:1">
                <div class="rec-title">Don't Reuse Passwords</div>
                <div class="rec-desc">Avoid using the same password across multiple websites. A breach on another site could compromise this account.</div>
            </div>
            <span class="badge badge-suspended">Review</span>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="monitor" class="lucide"></i> Active Sessions</h3>
    </div>
    <div class="card-body" style="padding:16px">
        <div class="sess-row">
            <span class="sess-ic"><i data-lucide="smartphone" class="lucide"></i></span>
            <div class="sess-main">
                <div class="sess-name">
                    <strong>Current Session</strong>
                    <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Active</span>
                </div>
                <div class="sess-meta">
                    <code class="chip-mono"><?= h($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></code>
                    <span><?= h(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 50)) ?>...</span>
                </div>
            </div>
            <span style="font-size:12px;color:var(--text4);font-style:italic;flex-shrink:0">This is your current session</span>
        </div>
    </div>
</div>

<style>
.sess-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius)}
.sess-ic{width:40px;height:40px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6d28d9,#7C3AED 55%,#a78bfa);color:#fff;box-shadow:0 4px 10px rgba(124,58,237,.18)}
.sess-ic .lucide{width:19px;height:19px}
.sess-main{min-width:0;flex:1}
.sess-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sess-name strong{font-weight:700;font-size:13px;color:var(--text)}
.sess-name .badge{font-size:10px;padding:3px 8px}
.sess-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text4);margin-top:4px}
.sess-meta .chip-mono{font-size:11px}
@media(max-width:640px){.sess-row{flex-wrap:wrap}.sess-main{flex-basis:100%}}
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
    var genBtn = document.querySelector('.gen-btn');
    if (genBtn) {
        var orig = genBtn.innerHTML;
        genBtn.innerHTML = '<i data-lucide="check" class="lucide" style="width:13px;height:13px"></i> Generated!';
        setTimeout(function() { genBtn.innerHTML = orig; if (typeof lucide !== 'undefined') lucide.createIcons(); }, 1500);
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
