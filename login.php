<?php
require_once __DIR__ . '/config.php';
init_db();

function base32_decode_2fa($input) {
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

if (is_logged_in()) {
    redirect(post_login_redirect_target());
}

$error = '';
$pending_2fa = $_SESSION['2fa_pending_user'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    try {
    if ($action === 'verify_2fa' && $pending_2fa) {
        $code = trim($_POST['totp_code'] ?? '');
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            $error = 'Please enter a valid 6-digit code.';
        } else {
            $stmt = db()->prepare("SELECT secret FROM user_2fa WHERE user_id = ? AND status = 'enabled'");
            $stmt->execute([$pending_2fa['id']]);
            $secret = $stmt->fetchColumn();
            if (!$secret) {
                unset($_SESSION['2fa_pending_user']);
                $error = '2FA is not enabled for this account.';
            } else {
                $key = base32_decode_2fa($secret);
                $time = floor(time() / 30);
                $valid = false;
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
                        $valid = true;
                        break;
                    }
                }
                if ($valid) {
                    $u = $pending_2fa;
                    unset($_SESSION['2fa_pending_user']);
                    $_SESSION['user_id'] = $u['id'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['role'] = $u['role'];
                    $_SESSION['package_id'] = $u['package_id'];
                    redirect(post_login_redirect_target());
                } else {
                    $error = 'Invalid verification code. Please try again.';
                }
            }
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (feature_flag('maintenance_mode')) {
            $error = 'The panel is currently in maintenance mode. Please try again later.';
        } elseif ($user && password_verify($password, $user['password'])) {
            if ($user['role'] === 'whm' && !feature_flag('whm_access')) {
                $error = 'WHM access is currently disabled by the administrator.';
            } elseif (($user['role'] ?? '') !== 'whm' && !feature_flag('cpanel_access')) {
                $error = 'Client panel access is currently disabled by the administrator.';
            } else {
                $stmt2fa = db()->prepare("SELECT status FROM user_2fa WHERE user_id = ? AND status = 'enabled'");
                $stmt2fa->execute([$user['id']]);
                if ($stmt2fa->fetchColumn()) {
                    $_SESSION['2fa_pending_user'] = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role'],
                        'package_id' => $user['package_id'],
                    ];
                    $q = '2fa=1';
                    $next = $_GET['next'] ?? '';
                    if (is_string($next) && $next !== '' && strpos($next, '/') === 0 && strpos($next, '//') !== 0) {
                        $q .= '&next=' . rawurlencode($next);
                    }
                    redirect('/login.php?' . $q);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['package_id'] = $user['package_id'];
                redirect(post_login_redirect_target());
            }
        } else {
            $error = 'Invalid username or password';
        }
    }
    } catch (Throwable $e) {
        error_log("[LOGIN] " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $error = 'Login service unavailable. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1e2230">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Login - <?= SITE_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="/assets/style.css?v=20260731b">
    <script defer src="/assets/lucide.min.js"></script>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-icon logo-icon-lg"><i data-lucide="layers" class="lucide"></i></div>
                <h1><?= SITE_NAME ?></h1>
                <p>Client Control Panel</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i data-lucide="alert-circle" class="lucide"></i> <?= h($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($pending_2fa && isset($_GET['2fa'])): ?>
                <div style="text-align:center;margin-bottom:16px">
                    <div style="width:48px;height:48px;border-radius:50%;background:rgba(99,102,241,0.15);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px">
                        <i data-lucide="shield-check" class="lucide" style="width:24px;height:24px;color:#6366f1"></i>
                    </div>
                    <p style="font-size:14px;color:var(--text3);margin:0">Signed in as <strong><?= h($pending_2fa['username']) ?></strong></p>
                    <p style="font-size:13px;color:var(--text4);margin:4px 0 0">Enter the 6-digit code from your authenticator app</p>
                </div>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="verify_2fa">
                    <div class="form-group">
                        <label for="totp_code">Verification Code</label>
                        <input type="text" id="totp_code" name="totp_code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code" autofocus style="font-size:24px;font-family:monospace;letter-spacing:6px;text-align:center;max-width:240px;margin:0 auto">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Verify</button>
                </form>
                <div style="text-align:center;margin-top:12px">
                    <a href="/login.php" style="font-size:13px;color:var(--text4)">Sign in as different user</a>
                </div>
            <?php else: ?>
                <?php if (feature_flag('maintenance_mode')): ?>
                    <div class="alert alert-error">
                        <i data-lucide="wrench" class="lucide"></i> Maintenance mode is active — logins are paused.
                    </div>
                <?php endif; ?>
                <form method="POST" autocomplete="on">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autofocus placeholder="Enter your username" autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Sign In</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        (function () {
            function init() {
                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                    return true;
                }
                return false;
            }
            if (!init()) {
                var t = setInterval(function () { if (init()) clearInterval(t); }, 50);
                setTimeout(function () { clearInterval(t); }, 8000);
            }
        })();
    </script>
</body>
</html>
