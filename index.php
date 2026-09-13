<?php
// Static assets (the bulk of customer traffic) need neither a session nor DB
// access when the host->docroot mapping is cached. Signal config.php to skip
// session_start() so these responses carry no Set-Cookie and write no files.
$__asset_ext = strtolower(pathinfo(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), PATHINFO_EXTENSION));
if (!defined('NO_SESSION') && in_array($__asset_ext, ['css','js','png','jpg','jpeg','gif','svg','ico','webp','avif','woff','woff2','ttf','eot','txt','map','json','pdf'], true)) {
    define('NO_SESSION', true);
}

require_once __DIR__ . '/config.php';
init_db();

if (feature_flag('maintenance_mode')) {
    render_maintenance_page();
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\d+$/', '', $host);
$host = preg_replace('/^www\./', '', $host);

// System pages (login, cpanel, whm, api, ...) may only ever be served on the
// panel's own host (localhost, an IP, or the configured global/server domain).
// Customer domains must never be able to reach them.
$is_panel_host = false;
if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || preg_match('/^(\d+\.){3}\d+$/', $host)) {
    $is_panel_host = true;
} else {
    $global_domain = 'dzhost.shop';
    try {
        $gd = db()->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch(PDO::FETCH_ASSOC);
        $gd_value = strtolower(trim((string)($gd['value'] ?? '')));
        if ($gd_value !== '') {
            $global_domain = $gd_value;
        }
    } catch (Throwable $e) {}
    $is_panel_host = ($host === $global_domain);
    // The server hostname (WHM → Hostname) is also a panel host, so the
    // admin panel stays reachable through it.
    if (!$is_panel_host) {
        $srv_host = server_hostname();
        $is_panel_host = ($srv_host !== 'localhost' && $srv_host !== '' && $host === $srv_host);
    }
}

// Customer domain/subdomain resolution. The host->document root mapping is
// cached (see resolve_customer_site) so static assets don't hit the database
// on every request; PHP pages still resolve correctly on each load.
$is_customer_host = !$is_panel_host && $host !== '' && $host !== 'localhost'
    && !preg_match('/^(\d+\.){3}\d+$/', $host);

if ($is_customer_host) {
    $site = resolve_customer_site($host);

    if ($site['suspended']) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Account Suspended</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#334155}.box{text-align:center;padding:40px;max-width:500px}.box h1{font-size:24px;margin-bottom:8px;color:#0f172a}.box p{color:#64748b;font-size:15px;line-height:1.6}.icon{font-size:48px;margin-bottom:16px;opacity:.5}</style></head><body><div class="box"><div class="icon">&#128683;</div><h1>Account Suspended</h1><p>The domain <strong>' . htmlspecialchars($host) . '</strong> has been suspended. Please contact the hosting administrator.</p></div></body></html>';
        exit;
    }

    if ($site['matched']) {
        $doc_root = $site['doc_root'];
        $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $request_uri = rtrim($request_uri, '/') ?: '/';

        // Customer hosts serve ONLY their own document root. System paths
        // (cpanel, whm, login.php, ...) must never resolve to the panel.
        $file = rtrim($doc_root, '/') . $request_uri;

        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/index.php';
            if (!is_file($file)) {
                $file = rtrim($doc_root, '/') . $request_uri . '/index.html';
            }
        }

        if (!is_file($file)) {
            $file = rtrim($doc_root, '/') . '/index.php';
        }

        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $_SERVER['DOCUMENT_ROOT'] = $doc_root;
            $_SERVER['SCRIPT_FILENAME'] = $file;
            chdir($doc_root);

            if ($ext === 'php') {
                require $file;
            } else {
                $mime_map = [
                    'html' => 'text/html', 'htm' => 'text/html',
                    'css' => 'text/css', 'js' => 'application/javascript',
                    'json' => 'application/json', 'png' => 'image/png',
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif', 'svg' => 'image/svg+xml',
                    'ico' => 'image/x-icon', 'webp' => 'image/webp',
                    'woff' => 'font/woff', 'woff2' => 'font/woff2',
                    'ttf' => 'font/ttf', 'txt' => 'text/plain',
                ];
                $etag = '"' . md5_file($file) . '"';
                header('ETag: ' . $etag);
                if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                    http_response_code(304);
                    exit;
                }
                header('Content-Type: ' . ($mime_map[$ext] ?? 'application/octet-stream'));
                header('Cache-Control: public, max-age=86400');
                readfile($file);
            }
            exit;
        }

        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px"><h1>404 Not Found</h1><p>The requested file was not found on <strong>' . htmlspecialchars($host) . '</strong>.</p></body></html>';
        exit;
    }
}

// System pages (login, cpanel, whm, api, ...) are only reachable on the
// panel's own host. A customer domain that matched no hosted content must
// never be able to reach the login page or any other panel endpoint.
if ($is_panel_host) {
    $__path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $__path = rtrim($__path, '/') ?: '/';
    if ($__path === '/') {
        redirect('/login.php');
    }
    if ($__path === '/login.php' || $__path === '/login' || preg_match('#^/(cpanel|whm|api)(/|$)#', $__path)) {
        $__file = __DIR__ . $__path;
        if (is_dir($__file)) { $__file = rtrim($__file, '/') . '/index.php'; }
        if (is_file($__file)) { require $__file; exit; }
        // File doesn't exist — redirect to base path
        if (preg_match('#^/(cpanel|whm|api)#', $__path, $m)) {
            redirect('/' . $m[1] . '/');
        }
        redirect('/login.php');
    }
} elseif ($host !== '') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px"><h1>Site Not Found</h1><p>The domain <strong>' . htmlspecialchars($host) . '</strong> is not configured on this server.</p></body></html>';
    exit;
}

$telegram_link = "https://t.me/websanapps";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - Premium Hosting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
    <script src="/assets/lucide.min.js" defer></script>
    <script>
        /* Icons render once the deferred icon library is available. */
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
    <style>
        :root {
            --primary: #6366f1;
            --primary-dim: rgba(99, 102, 241, 0.08);
            --primary-glow: rgba(99, 102, 241, 0.35);
            --accent: #06b6d4;
            --accent-dim: rgba(6, 182, 212, 0.08);
            --dark: #0f172a;
            --slate: #64748b;
            --muted: #94a3b8;
            --bg: #fafbff;
            --card-bg: rgba(255,255,255,0.72);
            --glass-border: rgba(255,255,255,0.45);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 32px rgba(0,0,0,0.06);
            --shadow-lg: 0 24px 64px rgba(0,0,0,0.08);
            --radius: 24px;
            --radius-xl: 36px;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth;scroll-padding-top:90px}
        body{
            font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;
            background:var(--bg);color:var(--dark);overflow-x:hidden;line-height:1.65;
        }

        /* --- Scroll Reveal --- */
        .reveal{opacity:0;transform:translateY(32px);transition:opacity .7s cubic-bezier(.22,1,.36,1),transform .7s cubic-bezier(.22,1,.36,1)}
        .reveal.visible{opacity:1;transform:translateY(0)}
        .reveal-delay-1{transition-delay:.1s}.reveal-delay-2{transition-delay:.2s}.reveal-delay-3{transition-delay:.3s}.reveal-delay-4{transition-delay:.4s}

        /* --- Background --- */
        .mesh-bg{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
        .mesh-circle{position:absolute;border-radius:50%;filter:blur(100px);opacity:.12;will-change:transform}
        .c1{width:700px;height:700px;background:var(--primary);top:-250px;right:-150px;animation:float1 24s ease-in-out infinite alternate}
        .c2{width:550px;height:550px;background:var(--accent);bottom:-150px;left:-120px;animation:float2 20s ease-in-out infinite alternate}
        .c3{width:400px;height:400px;background:#a78bfa;top:40%;left:50%;animation:float1 18s ease-in-out infinite alternate-reverse}
        @keyframes float1{0%{transform:translate(0,0) scale(1)}50%{transform:translate(40px,80px) scale(1.08)}100%{transform:translate(-20px,50px) scale(.95)}}
        @keyframes float2{0%{transform:translate(0,0) scale(1)}50%{transform:translate(-60px,-40px) scale(1.1)}100%{transform:translate(30px,-80px) scale(.9)}}

        /* --- Grid Texture Overlay --- */
        body::before{
            content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;
            background-image:radial-gradient(rgba(99,102,241,.04) 1px,transparent 1px);
            background-size:28px 28px;
        }

        /* --- Navigation --- */
        nav{
            position:fixed;top:0;width:100%;z-index:1000;
            padding:16px 5%;display:flex;justify-content:space-between;align-items:center;
            background:rgba(250,251,255,0.65);backdrop-filter:blur(20px) saturate(1.8);
            border-bottom:1px solid rgba(0,0,0,0.04);
            transition:background .3s,box-shadow .3s,padding .3s;
        }
        nav.scrolled{
            background:rgba(250,251,255,0.88);box-shadow:0 1px 24px rgba(0,0,0,0.06);padding:12px 5%;
        }
        .logo{font-weight:800;font-size:21px;color:var(--dark);text-decoration:none;display:flex;align-items:center;gap:10px;letter-spacing:-.5px}
        .logo i{color:var(--primary);width:24px;height:24px}
        .nav-btns{display:flex;gap:10px;align-items:center}

        /* --- Buttons --- */
        .btn{
            padding:12px 28px;border-radius:14px;font-weight:700;font-size:14.5px;
            text-decoration:none;transition:all .35s cubic-bezier(.4,0,.2,1);
            display:inline-flex;align-items:center;gap:8px;cursor:pointer;border:none;
            position:relative;overflow:hidden;
        }
        .btn-primary{background:var(--dark);color:#fff;border:1.5px solid var(--dark)}
        .btn-primary:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(15,23,42,.18);background:#1e293b}
        .btn-primary:active{transform:translateY(-1px)}
        .btn-glass{background:var(--primary-dim);color:var(--primary);border:1.5px solid transparent}
        .btn-glass:hover{background:rgba(99,102,241,.14);border-color:rgba(99,102,241,.15)}
        .btn-gradient{background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;border:none}
        .btn-gradient:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(99,102,241,.3)}
        .btn-white{background:#fff;color:var(--dark);border:1.5px solid rgba(255,255,255,.2)}
        .btn-white:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(255,255,255,.25);background:#f8fafc}

        /* --- Hero --- */
        header{padding:180px 5% 110px;text-align:center;max-width:1050px;margin:0 auto;position:relative}
        .badge{
            display:inline-flex;align-items:center;gap:8px;
            padding:8px 20px;background:var(--primary-dim);border:1px solid rgba(99,102,241,.1);
            color:var(--primary);border-radius:100px;font-size:13px;font-weight:700;margin-bottom:28px;
            animation:badgeIn .8s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes badgeIn{from{opacity:0;transform:translateY(12px) scale(.95)}to{opacity:1;transform:none}}
        header h1{
            font-size:clamp(2.4rem,7.5vw,4.6rem);font-weight:800;line-height:1.08;letter-spacing:-2.5px;margin-bottom:24px;
            animation:heroIn .9s cubic-bezier(.22,1,.36,1) .1s both;
        }
        @keyframes heroIn{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}
        header h1 span{
            background:linear-gradient(135deg,var(--primary),var(--accent));
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
            background-size:200% auto;animation:gradientShift 4s ease infinite;
        }
        @keyframes gradientShift{0%,100%{background-position:0% center}50%{background-position:100% center}}
        header>p{
            font-size:clamp(1.05rem,1.8vw,1.25rem);color:var(--slate);max-width:600px;margin:0 auto 44px;
            animation:heroIn .9s cubic-bezier(.22,1,.36,1) .2s both;
        }
        .hero-actions{
            display:flex;gap:14px;justify-content:center;flex-wrap:wrap;
            animation:heroIn .9s cubic-bezier(.22,1,.36,1) .3s both;
        }
        .hero-actions .btn{padding:16px 36px;font-size:16px;border-radius:16px}

        /* --- Floating Orbs near Hero --- */
        .hero-orb{
            position:absolute;border-radius:50%;pointer-events:none;
        }
        .hero-orb-1{width:80px;height:80px;background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(6,182,212,.1));top:20%;right:5%;animation:orbFloat 6s ease-in-out infinite alternate}
        .hero-orb-2{width:50px;height:50px;background:linear-gradient(135deg,rgba(6,182,212,.12),rgba(167,139,250,.1));bottom:15%;left:4%;animation:orbFloat 8s ease-in-out infinite alternate-reverse}
        @keyframes orbFloat{0%{transform:translateY(0) rotate(0deg)}100%{transform:translateY(-24px) rotate(12deg)}}

        /* --- Stats Bar --- */
        .stats{
            display:flex;justify-content:center;gap:48px;flex-wrap:wrap;
            padding:60px 5%;max-width:1000px;margin:0 auto;
        }
        .stat-item{text-align:center}
        .stat-num{
            font-size:clamp(2rem,4vw,3rem);font-weight:800;letter-spacing:-1px;
            background:linear-gradient(135deg,var(--primary),var(--accent));
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
        }
        .stat-label{font-size:14px;color:var(--slate);font-weight:600;margin-top:4px}

        /* --- Section Titles --- */
        .section-header{text-align:center;margin-bottom:56px}
        .section-header h2{font-size:clamp(1.8rem,4vw,2.8rem);font-weight:800;letter-spacing:-1.5px;margin-bottom:12px}
        .section-header p{color:var(--slate);font-size:1.05rem;max-width:520px;margin:0 auto}

        /* --- Bento Features --- */
        .features{padding:80px 5% 100px;max-width:1200px;margin:0 auto}
        .grid-bento{display:grid;grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(2,260px);gap:20px}
        .bento-item{
            background:var(--card-bg);border:1px solid rgba(0,0,0,0.04);border-radius:var(--radius-xl);
            padding:36px 40px;position:relative;overflow:hidden;
            backdrop-filter:blur(12px);transition:all .45s cubic-bezier(.22,1,.36,1);
        }
        .bento-item::before{
            content:'';position:absolute;inset:0;border-radius:inherit;
            background:linear-gradient(135deg,rgba(99,102,241,.06),rgba(6,182,212,.04));
            opacity:0;transition:opacity .4s;
        }
        .bento-item:hover::before{opacity:1}
        .bento-item:hover{border-color:var(--primary-glow);transform:translateY(-4px);box-shadow:var(--shadow-lg)}
        .bento-icon{
            width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;
            background:var(--primary-dim);margin-bottom:20px;transition:all .3s;
        }
        .bento-item:hover .bento-icon{background:var(--primary);transform:scale(1.08)}
        .bento-icon i{color:var(--primary);width:28px;height:28px;transition:color .3s}
        .bento-item:hover .bento-icon i{color:#fff}
        .bento-item h3{font-size:20px;font-weight:700;margin-bottom:8px;letter-spacing:-.3px}
        .bento-item p{color:var(--slate);font-size:15px;line-height:1.6}
        .bento-1{grid-column:1/3}
        .bento-4{grid-column:2/4}

        /* --- Pricing --- */
        .pricing-section{padding:100px 5%;position:relative}
        .pricing-section::before{
            content:'';position:absolute;top:0;left:0;right:0;bottom:0;
            background:linear-gradient(180deg,transparent 0%,rgba(99,102,241,.02) 50%,transparent 100%);
            pointer-events:none;
        }
        .pricing-grid{
            display:grid;grid-template-columns:repeat(3,1fr);
            gap:24px;max-width:1100px;margin:0 auto;
        }
        .price-card{
            background:#fff;padding:44px 40px;border-radius:var(--radius-xl);
            border:1.5px solid #e8ecf1;display:flex;flex-direction:column;
            transition:all .45s cubic-bezier(.22,1,.36,1);position:relative;
        }
        .price-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg)}
        .price-card.featured{
            background:var(--dark);color:#fff;
            transform:scale(1.04);box-shadow:0 32px 64px rgba(15,23,42,.18);
            border-color:rgba(99,102,241,.2);z-index:2;
        }
        .price-card.featured:hover{transform:scale(1.04) translateY(-4px)}
        .price-card.featured .popular-badge{
            position:absolute;top:-14px;left:50%;transform:translateX(-50%);
            background:linear-gradient(135deg,var(--primary),var(--accent));
            color:#fff;padding:6px 20px;border-radius:100px;font-size:12px;font-weight:700;
            letter-spacing:.5px;text-transform:uppercase;
        }
        .price-card h3{font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:1.2px;font-size:13px;color:var(--muted)}
        .price-card.featured h3{color:#94a3b8}
        .amount{font-size:52px;font-weight:800;margin:20px 0 4px;letter-spacing:-2px}
        .amount span{font-size:15px;font-weight:500;color:var(--slate);letter-spacing:0}
        .price-card.featured .amount{color:#fff}
        .price-card.featured .amount span{color:#64748b}
        .price-card .desc{color:var(--slate);font-size:14.5px;margin-bottom:8px}
        .price-card.featured .desc{color:#94a3b8}
        .price-card ul{list-style:none;margin:28px 0;border-top:1px solid #f1f5f9;padding-top:8px}
        .price-card ul li{padding:13px 0;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;font-size:14.5px}
        .price-card ul li i{color:#22c55e;flex-shrink:0}
        .price-card.featured ul{border-color:rgba(255,255,255,.07)}
        .price-card.featured ul li{color:#cbd5e1;border-color:rgba(255,255,255,.07)}
        .btn-full{width:100%;justify-content:center;margin-top:auto;padding:16px}
        .btn-featured{background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;border:none}
        .btn-featured:hover{box-shadow:0 8px 24px rgba(99,102,241,.3);transform:translateY(-2px)}

        /* --- CTA --- */
        .cta-section{padding:80px 5% 100px;text-align:center}
        .cta-card{
            background:linear-gradient(135deg,#1e1b4b,var(--dark) 40%,#0c4a6e);
            padding:80px 48px;border-radius:var(--radius-xl);color:#fff;max-width:1060px;margin:0 auto;
            position:relative;overflow:hidden;
        }
        .cta-card::before{
            content:'';position:absolute;width:400px;height:400px;border-radius:50%;
            background:rgba(99,102,241,.15);filter:blur(80px);top:-100px;right:-80px;
        }
        .cta-card::after{
            content:'';position:absolute;width:300px;height:300px;border-radius:50%;
            background:rgba(6,182,212,.1);filter:blur(60px);bottom:-80px;left:-60px;
        }
        .cta-card>*{position:relative;z-index:1}
        .cta-card h2{font-size:clamp(1.8rem,4.5vw,3.2rem);font-weight:800;margin-bottom:16px;letter-spacing:-1.5px}
        .cta-card p{opacity:.75;margin-bottom:40px;font-size:1.1rem;max-width:500px;margin-left:auto;margin-right:auto}

        /* --- Footer --- */
        footer{padding:48px 5% 40px;border-top:1px solid rgba(0,0,0,0.04);text-align:center}
        .f-links{display:flex;justify-content:center;gap:32px;margin-bottom:24px;flex-wrap:wrap}
        .f-links a{color:var(--slate);text-decoration:none;font-weight:600;font-size:14px;transition:color .3s}
        .f-links a:hover{color:var(--primary)}
        footer p{color:var(--muted);font-size:13px}

        /* --- Responsive --- */
        @media(max-width:1024px){
            .pricing-grid{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
            .price-card.featured{transform:none}
            .price-card.featured:hover{transform:translateY(-4px)}
        }
        @media(max-width:992px){
            .grid-bento{grid-template-columns:1fr 1fr;grid-template-rows:auto}
            .bento-1,.bento-4{grid-column:span 2}
        }
        @media(max-width:768px){
            .stats{gap:28px}
            .stat-item{min-width:120px}
        }
        @media(max-width:640px){
            .grid-bento{grid-template-columns:1fr}
            .bento-1,.bento-4{grid-column:span 1}
            .bento-item{padding:32px 28px}
            nav{padding:14px 5%}
            nav.scrolled{padding:10px 5%}
            header{padding-top:130px;padding-bottom:70px}
            .hero-actions .btn{padding:14px 28px;font-size:15px}
            .nav-btns a:first-child{display:none}
            .cta-card{padding:56px 28px}
            .price-card{padding:36px 28px}
            .hero-orb{display:none}
        }
    </style>
</head>
<body>

    <div class="mesh-bg">
        <div class="mesh-circle c1"></div>
        <div class="mesh-circle c2"></div>
        <div class="mesh-circle c3"></div>
    </div>

    <nav id="main-nav">
        <a href="/" class="logo"><i data-lucide="zap"></i> <?= SITE_NAME ?></a>
        <div class="nav-btns">
            <a href="/login.php" class="btn btn-glass">Client Portal</a>
            <a href="<?= $telegram_link ?>" target="_blank" class="btn btn-primary">Get Started</a>
        </div>
    </nav>

    <header>
        <div class="hero-orb hero-orb-1"></div>
        <div class="hero-orb hero-orb-2"></div>
        <div class="badge"><i data-lucide="sparkles" style="width:14px;height:14px"></i> Next-Gen Hosting Platform</div>
        <h1>Hosting Built for<br><span>Speed & Scale.</span></h1>
        <p>Deploy instantly on ultra-fast NVMe infrastructure. Enterprise security, 24/7 human support, and pricing that grows with you.</p>
        <div class="hero-actions">
            <a href="#pricing" class="btn btn-primary">View Plans <i data-lucide="arrow-down" style="width:18px;height:18px"></i></a>
            <a href="<?= $telegram_link ?>" target="_blank" class="btn btn-glass">Talk to Sales</a>
        </div>
    </header>

    <div class="stats reveal">
        <div class="stat-item">
            <div class="stat-num">99.9%</div>
            <div class="stat-label">Uptime SLA</div>
        </div>
        <div class="stat-item">
            <div class="stat-num">&lt;50ms</div>
            <div class="stat-label">Avg. Response</div>
        </div>
        <div class="stat-item">
            <div class="stat-num">5K+</div>
            <div class="stat-label">Happy Clients</div>
        </div>
        <div class="stat-item">
            <div class="stat-num">24/7</div>
            <div class="stat-label">Expert Support</div>
        </div>
    </div>

    <section class="features" id="features">
        <div class="section-header reveal">
            <h2>Why Choose Us</h2>
            <p>Everything you need to run high-performance websites, all in one platform.</p>
        </div>
        <div class="grid-bento">
            <div class="bento-item bento-1 reveal">
                <div class="bento-icon"><i data-lucide="rocket"></i></div>
                <h3>Turbocharged Performance</h3>
                <p>LiteSpeed Web Server with NVMe SSDs and built-in caching ensure sub-second page loads for your visitors worldwide.</p>
            </div>
            <div class="bento-item reveal reveal-delay-1">
                <div class="bento-icon"><i data-lucide="shield-check"></i></div>
                <h3>Ironclad Security</h3>
                <p>Advanced DDoS mitigation, free SSL certificates, and automated daily backups keep your data safe.</p>
            </div>
            <div class="bento-item reveal reveal-delay-2">
                <div class="bento-icon"><i data-lucide="cpu"></i></div>
                <h3>Dedicated Resources</h3>
                <p>Guaranteed CPU, RAM, and I/O — no overselling, no noisy-neighbor effects, just consistent performance.</p>
            </div>
            <div class="bento-item bento-4 reveal reveal-delay-1">
                <div class="bento-icon"><i data-lucide="headphones"></i></div>
                <h3>24/7 Human Support</h3>
                <p>Real engineers, not bots. Our team is on Telegram and live chat around the clock to help you solve issues fast.</p>
            </div>
        </div>
    </section>

    <section class="pricing-section" id="pricing">
        <div class="section-header reveal">
            <h2>Simple, Transparent Pricing</h2>
            <p>No hidden fees. Pick a plan and scale as you grow.</p>
        </div>
        
        <div class="pricing-grid">
                <div class="price-card featured reveal">
                    <div class="popular-badge">Most Popular</div>
                    <h3>Enterprise Plan</h3>
                    <div class="amount">$19.99<span>/mo</span></div>
                    <ul>
                        <li><i data-lucide="check-circle-2" style="width:18px;height:18px"></i> Unlimited NVMe Storage</li>
                        <li><i data-lucide="check-circle-2" style="width:18px;height:18px"></i> Unlimited Bandwidth</li>
                        <li><i data-lucide="check-circle-2" style="width:18px;height:18px"></i> Free Domain Name</li>
                    </ul>
                    <a href="<?= $telegram_link ?>" target="_blank" class="btn btn-full btn-featured">Get Started</a>
                </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="cta-card reveal">
            <h2>Ready to launch something<br>extraordinary?</h2>
            <p>Join thousands of developers and businesses who trust us with their web infrastructure.</p>
            <a href="<?= $telegram_link ?>" target="_blank" class="btn btn-white" style="padding:18px 44px;border-radius:16px;font-size:16px">Create Free Account <i data-lucide="arrow-right" style="width:18px;height:18px"></i></a>
        </div>
    </section>

    <footer>
        <div class="f-links">
            <a href="#features">Features</a>
            <a href="#pricing">Pricing</a>
            <a href="/login.php">Client Portal</a>
            <a href="<?= $telegram_link ?>" target="_blank">Contact</a>
        </div>
        <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
    </footer>

    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();

        /* Navbar scroll effect */
        (function(){
            var nav = document.getElementById('main-nav');
            if(!nav) return;
            var onScroll = function(){
                if(window.scrollY > 40){ nav.classList.add('scrolled'); }
                else { nav.classList.remove('scrolled'); }
            };
            window.addEventListener('scroll', onScroll, {passive:true});
            onScroll();
        })();

        /* Scroll reveal observer */
        (function(){
            var els = document.querySelectorAll('.reveal');
            if(!els.length) return;
            if(!('IntersectionObserver' in window)){
                els.forEach(function(e){ e.classList.add('visible'); });
                return;
            }
            var obs = new IntersectionObserver(function(entries){
                entries.forEach(function(entry){
                    if(entry.isIntersecting){
                        entry.target.classList.add('visible');
                        obs.unobserve(entry.target);
                    }
                });
            }, {threshold:0.12, rootMargin:'0px 0px -40px 0px'});
            els.forEach(function(e){ obs.observe(e); });
        })();

        /* Smooth number count-up for stats */
        (function(){
            var nums = document.querySelectorAll('.stat-num');
            if(!nums.length) return;
            var animated = false;
            function animate(){
                if(animated) return;
                animated = true;
                nums.forEach(function(el){
                    var text = el.textContent.trim();
                    if(text.indexOf('+') !== -1) text = text.replace('+','');
                    if(text.indexOf('%') !== -1) return;
                    if(text.indexOf('ms') !== -1 || text.indexOf('K') !== -1) return;
                });
            }
            if('IntersectionObserver' in window){
                var obs2 = new IntersectionObserver(function(entries){
                    if(entries[0].isIntersecting){ animate(); obs2.disconnect(); }
                },{threshold:0.5});
                var statsEl = document.querySelector('.stats');
                if(statsEl) obs2.observe(statsEl);
            }
        })();
    </script>
</body>
</html>

