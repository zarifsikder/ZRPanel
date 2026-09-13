<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('two_factor_auth');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

function base32_encode($data) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits . '00000', $i, 5);
        $encoded .= $chars[bindec($chunk)];
    }
    return $encoded;
}

function base32_decode($input) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(rtrim($input, '='));
    $bits = '';
    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($chars, $input[$i]);
        if ($val === false) continue;
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    $data = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $data .= chr(bindec(substr($bits, $i, 8)));
    }
    return $data;
}

function generate_totp_secret($length = 20) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $secret;
}

function generate_backup_codes($count = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
    }
    return $codes;
}

function verify_totp($secret, $code) {
    $key = base32_decode($secret);
    $time = floor(time() / 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $counter = $time + $offset;
        $counter_bytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counter_bytes, $key, true);
        $offset_val = ord($hash[19]) & 0x0F;
        $truncate = ((ord($hash[$offset_val]) & 0x7F) << 24) |
                    ((ord($hash[$offset_val + 1]) & 0xFF) << 16) |
                    ((ord($hash[$offset_val + 2]) & 0xFF) << 8) |
                    (ord($hash[$offset_val + 3]) & 0xFF);
        $otp = $truncate % 1000000;
        if (str_pad((string)$otp, 6, '0', STR_PAD_LEFT) === $code) {
            return true;
        }
    }
    return false;
}

$stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$email = $stmt->fetchColumn() ?: 'user@zenpanel.com';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'enable_start') {
        $secret = generate_totp_secret();
        $backup_codes = generate_backup_codes();
        $hashed_codes = array_map(function($c) { return password_hash($c, PASSWORD_BCRYPT); }, $backup_codes);
        $codes_json = json_encode($hashed_codes);
        $stmt = $db->prepare("SELECT id FROM user_2fa WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ($stmt->fetch()) {
            $db->prepare("UPDATE user_2fa SET secret = ?, backup_codes = ?, status = 'disabled' WHERE user_id = ?")
               ->execute([$secret, $codes_json, $user_id]);
        } else {
            $db->prepare("INSERT INTO user_2fa (user_id, secret, backup_codes, status) VALUES (?, ?, ?, 'disabled')")
               ->execute([$user_id, $secret, $codes_json]);
        }
        $_SESSION['2fa_pending_secret'] = $secret;
        $_SESSION['2fa_pending_codes'] = $backup_codes;
        flash('success', 'Scan the QR code below with your authenticator app, then verify.');
        redirect('/cpanel/two-factor.php?setup=1');

    } elseif ($action === 'verify_enable') {
        $code = trim($_POST['totp_code'] ?? '');
        $secret = $_SESSION['2fa_pending_secret'] ?? null;
        $pending_codes = $_SESSION['2fa_pending_codes'] ?? null;
        if (!$secret) {
            flash('error', 'Setup session expired. Please start over.');
            redirect('/cpanel/two-factor.php');
        }
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            flash('error', 'Please enter a valid 6-digit code.');
            redirect('/cpanel/two-factor.php?setup=1');
        }
        if (!verify_totp($secret, $code)) {
            flash('error', 'Invalid code. Please check your authenticator app.');
            redirect('/cpanel/two-factor.php?setup=1');
        }
        $hashed_codes = array_map(function($c) { return password_hash($c, PASSWORD_BCRYPT); }, $pending_codes);
        $codes_json = json_encode($hashed_codes);
        $db->prepare("UPDATE user_2fa SET status = 'enabled', backup_codes = ? WHERE user_id = ?")
           ->execute([$codes_json, $user_id]);
        unset($_SESSION['2fa_pending_secret'], $_SESSION['2fa_pending_codes']);
        flash('success', 'Two-factor authentication has been enabled successfully!');
        redirect('/cpanel/two-factor.php');

    } elseif ($action === 'disable') {
        $code = trim($_POST['totp_code'] ?? '');
        $stmt = $db->prepare("SELECT secret FROM user_2fa WHERE user_id = ? AND status = 'enabled'");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            flash('error', 'Two-factor authentication is not enabled.');
            redirect('/cpanel/two-factor.php');
        }
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            flash('error', 'Please enter a valid 6-digit code.');
            redirect('/cpanel/two-factor.php');
        }
        if (!verify_totp($row['secret'], $code)) {
            flash('error', 'Invalid code. Cannot disable 2FA without verification.');
            redirect('/cpanel/two-factor.php');
        }
        $db->prepare("UPDATE user_2fa SET status = 'disabled' WHERE user_id = ?")
           ->execute([$user_id]);
        flash('success', 'Two-factor authentication has been disabled.');
        redirect('/cpanel/two-factor.php');

    } elseif ($action === 'regenerate_backup') {
        $code = trim($_POST['totp_code'] ?? '');
        $stmt = $db->prepare("SELECT secret FROM user_2fa WHERE user_id = ? AND status = 'enabled'");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            flash('error', 'Two-factor authentication is not enabled.');
            redirect('/cpanel/two-factor.php');
        }
        if (!verify_totp($row['secret'], $code)) {
            flash('error', 'Invalid TOTP code.');
            redirect('/cpanel/two-factor.php');
        }
        $new_codes = generate_backup_codes();
        $hashed = array_map(function($c) { return password_hash($c, PASSWORD_BCRYPT); }, $new_codes);
        $db->prepare("UPDATE user_2fa SET backup_codes = ? WHERE user_id = ?")
           ->execute([json_encode($hashed), $user_id]);
        $_SESSION['2fa_new_codes'] = $new_codes;
        flash('success', 'Backup codes regenerated. Save them now!');
        redirect('/cpanel/two-factor.php?newcodes=1');
    }
    redirect('/cpanel/two-factor.php');
}

$stmt = $db->prepare("SELECT * FROM user_2fa WHERE user_id = ?");
$stmt->execute([$user_id]);
$tfa = $stmt->fetch(PDO::FETCH_ASSOC);

$show_setup = isset($_GET['setup']) && $tfa && $tfa['status'] === 'disabled';
$show_newcodes = isset($_GET['newcodes']) && !empty($_SESSION['2fa_new_codes']);
$new_codes = $_SESSION['2fa_new_codes'] ?? null;
if ($show_newcodes) {
    unset($_SESSION['2fa_new_codes']);
}

$pending_secret = $_SESSION['2fa_pending_secret'] ?? null;

$nav = 'twofactor';
$page_title = 'Two-Factor Authentication';
require_once __DIR__ . '/../templates/header.php';

$tfa_enabled = $tfa && $tfa['status'] === 'enabled';
?>

<?php if (!$show_setup && !$show_newcodes): ?>
<div class="page-hero fade-in">
    <div class="hero-icon <?= $tfa_enabled ? 'green' : 'red' ?>"><i data-lucide="<?= $tfa_enabled ? 'shield-check' : 'shield-off' ?>" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Two-Factor Authentication</div>
        <div class="hero-desc">Add a second layer of protection to your login with a time-based one-time password (TOTP).</div>
    </div>
    <div class="hero-actions"><span class="badge <?= $tfa_enabled ? 'badge-active' : 'badge-suspended' ?>"><?= $tfa_enabled ? 'Protected' : 'Not Protected' ?></span></div>
</div>
<?php endif; ?>

<?php if ($show_setup && $pending_secret): ?>
<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="smartphone" class="lucide"></i> Enable Two-Factor Authentication</h3>
    </div>
    <div class="card-body">
        <div style="display:flex;gap:32px;flex-wrap:wrap;align-items:flex-start">
            <div style="text-align:center;flex-shrink:0">
                <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px">Step 1: Scan QR Code</p>
                <div id="qrcode" style="display:inline-block;padding:12px;background:#fff;border-radius:var(--radius);border:1px solid var(--border)"></div>
                <p style="font-size:11px;color:var(--text4);margin-top:8px">Use Google Authenticator, Authy, or similar</p>
            </div>
            <div style="flex:1;min-width:200px">
                <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:8px">Or enter this secret manually:</p>
                <div style="font-family:monospace;font-size:16px;font-weight:700;letter-spacing:2px;padding:12px 16px;background:var(--bg3);border:1.5px solid var(--border);border-radius:var(--radius-sm);word-break:break-all;color:var(--text);margin-bottom:16px">
                    <?= h(chunk_split($pending_secret, 4, ' ')) ?>
                </div>

                <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:8px">Step 2: Enter verification code</p>
                <form method="POST">
                    <input type="hidden" name="action" value="verify_enable">
                    <div class="form-group">
                        <label>6-Digit Code</label>
                        <input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="off" style="max-width:200px;font-size:20px;font-family:monospace;letter-spacing:4px;text-align:center">
                    </div>
                    <button type="submit" class="btn btn-success">Verify &amp; Enable</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($show_newcodes && $new_codes): ?>
<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="key" class="lucide"></i> New Backup Codes</h3>
    </div>
    <div class="card-body">
        <div class="tip-card tip-orange" style="margin-bottom:18px">
            <i data-lucide="alert-triangle" class="lucide"></i>
            <div class="tip-body"><strong>Important.</strong> Save these codes now &mdash; they are shown only once. Each code works exactly once if you lose your authenticator device.</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,140px),1fr));gap:8px;margin-bottom:16px">
            <?php foreach ($new_codes as $code): ?>
                <div style="font-family:monospace;font-size:15px;font-weight:600;text-align:center;padding:10px 8px;background:var(--bg3);border:1.5px solid var(--border);border-radius:var(--radius-sm);color:var(--text);letter-spacing:1px" class="backup-code"><?= h($code) ?></div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px">
            <button type="button" class="btn btn-primary" onclick="copyBackupCodes()"><i data-lucide="copy" class="lucide"></i> Copy All Codes</button>
            <button type="button" class="btn btn-success" onclick="downloadBackupCodes()"><i data-lucide="download" class="lucide"></i> Download</button>
            <a href="/cpanel/two-factor.php" class="btn btn-sm" style="margin-left:auto">Done</a>
        </div>
    </div>
</div>

<script>
function copyBackupCodes() {
    var codes = document.querySelectorAll('.backup-code');
    var text = '';
    codes.forEach(function(el) { text += el.textContent.trim() + '\n'; });
    navigator.clipboard.writeText(text.trim()).then(function() {
        alert('Backup codes copied to clipboard!');
    });
}
function downloadBackupCodes() {
    var codes = document.querySelectorAll('.backup-code');
    var text = 'ZRPanel Backup Codes\n====================\n\n';
    codes.forEach(function(el) { text += el.textContent.trim() + '\n'; });
    text += '\nGenerated: <?= date('Y-m-d H:i:s') ?>\n';
    var blob = new Blob([text], {type: 'text/plain'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'zenpanel-backup-codes.txt';
    a.click();
}
</script>

<?php else: ?>
<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="shield-check" class="lucide"></i> Two-Factor Authentication Status</h3>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap">
            <div style="width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:<?= $tfa_enabled ? 'var(--success-light)' : 'var(--danger-light)' ?>;border:1px solid <?= $tfa_enabled ? 'var(--success-border)' : 'var(--danger-border)' ?>">
                <i data-lucide="<?= $tfa_enabled ? 'shield-check' : 'shield-off' ?>" class="lucide" style="width:26px;height:26px;color:<?= $tfa_enabled ? '#059669' : '#dc2626' ?>"></i>
            </div>
            <div style="flex:1;min-width:200px">
                <p style="font-size:16px;font-weight:700;color:var(--text);margin:0">2FA is <?= $tfa_enabled ? 'Enabled' : 'Disabled' ?></p>
                <p style="font-size:13px;color:var(--text3);margin:4px 0 0">
                    <?php if ($tfa_enabled): ?>
                        Your account is protected with two-factor authentication.
                    <?php else: ?>
                        Your account only uses a password. Enable 2FA for an extra layer of security.
                    <?php endif; ?>
                </p>
            </div>
            <span class="dot <?= $tfa_enabled ? 'green' : 'red' ?>"></span>
        </div>

        <?php if ($tfa_enabled): ?>
            <div style="border-top:1px solid var(--border);padding-top:20px">
                <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px"><i data-lucide="shield-off" class="lucide" style="width:14px;height:14px;color:#dc2626;vertical-align:-2px"></i> Disable Two-Factor Authentication</p>
                <form method="POST" style="max-width:400px" onsubmit="return confirm('Are you sure you want to disable 2FA? This will make your account less secure.')">
                    <input type="hidden" name="action" value="disable">
                    <div class="form-group">
                        <label>Enter your TOTP code to confirm</label>
                        <input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="off" inputmode="numeric" style="max-width:200px;font-family:'Fira Code',monospace;font-size:16px;letter-spacing:3px;text-align:center">
                        <div class="form-hint"><i data-lucide="info" class="lucide"></i> Disabling requires a valid code &mdash; this proves it is really you.</div>
                    </div>
                    <button type="submit" class="btn btn-danger"><i data-lucide="shield-off" class="lucide"></i> Disable 2FA</button>
                </form>
            </div>
        <?php else: ?>
            <div style="border-top:1px solid var(--border);padding-top:20px">
                <div class="tip-card tip-green" style="margin-bottom:16px">
                    <i data-lucide="smartphone" class="lucide"></i>
                    <div class="tip-body"><strong>Works with any authenticator app.</strong> Google Authenticator, Authy, Microsoft Authenticator, 1Password and more.</div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="enable_start">
                    <button type="submit" class="btn btn-success"><i data-lucide="shield-plus" class="lucide"></i> Enable Two-Factor Authentication</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($tfa_enabled): ?>
<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="key-round" class="lucide"></i> Backup Codes</h3></div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--text3);margin-bottom:16px">Backup codes let you log in if you lose access to your authenticator app. Each code works only once.</p>
        <form method="POST">
            <input type="hidden" name="action" value="regenerate_backup">
            <div class="form-group">
                <label>Enter your TOTP code to regenerate backup codes</label>
                <input type="text" name="totp_code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="off" inputmode="numeric" style="max-width:200px;font-family:'Fira Code',monospace;font-size:16px;letter-spacing:3px;text-align:center">
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Regenerating invalidates all previously issued codes.</div>
            </div>
            <button type="submit" class="btn btn-warning" onsubmit="return confirm('Regenerate backup codes? Old codes will stop working.')"><i data-lucide="refresh-cw" class="lucide"></i> Regenerate Backup Codes</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('qrcode');
    if (el) {
        new QRCode(el, {
            text: 'otpauth://totp/ZRPanel:<?= urlencode($email) ?>?secret=<?= h($pending_secret) ?>&issuer=ZRPanel',
            width: 200,
            height: 200,
            colorDark: '#1e2230',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
