<?php
require_once __DIR__ . '/../config.php';
init_db();

if (feature_flag('maintenance_mode')) {
    render_maintenance_page();
}

if (is_logged_in() && ($_SESSION['role'] ?? '') === 'whm') {
    redirect('/whm/');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND role = 'whm' AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            redirect('/whm/');
        } else {
            $error = 'Invalid credentials';
        }
    } catch (Throwable $e) {
        error_log("[WHM LOGIN] " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
    <title>WHM Login - <?= SITE_NAME ?></title>
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
                <div class="logo-icon"><i data-lucide="shield" class="lucide"></i></div>
                <h1><?= SITE_NAME ?></h1>
                <p>Web Hosting Manager</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i data-lucide="alert-circle" class="lucide"></i> <?= h($error) ?>
                </div>
            <?php endif; ?>
            <form method="POST" autocomplete="on">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus placeholder="Admin username" autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="Enter password" autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Sign In</button>
            </form>
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
