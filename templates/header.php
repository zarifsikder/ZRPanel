<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1e2230">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= $page_title ?? SITE_NAME ?> - <?= SITE_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="/assets/style.css?v=20260825a">
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
        /* --- Mobile Responsive & Layout CSS --- */
        :root {
            --sidebar-width: 260px;
            --topbar-height: 65px;
            --primary-color: #3b82f6;
            --sidebar-bg: #151921;
            --bg-body: #f8fafc;
            --border-color: #e2e8f0;
        }

        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg-body); overflow-x: hidden; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0; top: 0;
            background: var(--sidebar-bg);
            z-index: 1100;
            transition: transform 0.3s ease;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav { flex: 1 1 auto; overflow-y: auto; min-height: 0; }
        .sidebar-footer {
            flex-shrink: 0;
            padding: 15px 20px;
            border-top: 1px solid rgba(255,255,255,.08);
            background: #151921;
        }
        .sidebar-footer .nav-item {
            border-radius: 8px;
            transition: all .2s ease;
        }
        .sidebar-footer .nav-item:hover {
            background: rgba(220,38,38,.12);
            color: #f87171 !important;
        }

        /* Topbar */
        .topbar {
            position: fixed;
            top: 0; right: 0; left: var(--sidebar-width);
            height: var(--topbar-height);
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            border-bottom: 1px solid var(--border-color);
            z-index: 1000;
            transition: left 0.3s ease;
        }

        /* Menu Button - Specifically Black */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px;
            color: #000000 !important; /* Text color black */
        }
        .mobile-menu-btn .lucide {
            stroke: #000000 !important; /* Icon stroke black */
            width: 28px;
            height: 28px;
        }

        /* Topbar Search Button */
        .topbar-search-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 10px;
            background: #f1f5f9;
            color: #334155;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .topbar-search-btn:hover { background: #e2e8f0; color: #0f172a; }
        .topbar-search-btn .lucide { width: 20px; height: 20px; stroke: #334155; }

        /* Main Content area */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
        }

        /* Tables & Grids Responsiveness */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active, .sidebar.open { transform: translateX(0); }
            .topbar { left: 0; }
            .main-content { margin-left: 0; padding: 15px; }
            .sidebar-overlay.active { display: block; }
            .mobile-menu-btn { display: block; }
            .topbar-logout-label { display: none; }
            .disk-breakdown-grid { grid-template-columns: repeat(3, 1fr) !important; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .disk-breakdown-grid { grid-template-columns: repeat(2, 1fr) !important; }
            .disk-usage-header-info { flex-direction: column; gap: 15px !important; }
        }

        /* Disk Breakdown Grid */
        .disk-breakdown-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }

        /* Pulse Animation */
        @keyframes pulse-dot {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header" style="padding: 20px; background: #1e2230; display: flex; justify-content: space-between; align-items: center;">
            <a href="#" class="sidebar-logo" style="text-decoration:none; color:#fff; display:flex; align-items:center; gap:10px;">
                <i data-lucide="layers"></i>
                <span style="font-weight:700; font-size:18px;"><?= SITE_NAME ?></span>
            </a>
            <button id="sidebarCloseBtn" style="background:none; border:none; color:#fff; font-size:24px; cursor:pointer; display:none;">&times;</button>
        </div>
        <nav class="sidebar-nav" id="sidebarNav">
            <?php if (($_SESSION['role'] ?? '') === 'whm'): ?>
                <?php $navSections = [
                    'Overview' => [
                        ['/whm/', 'layout-dashboard', 'Dashboard', 'dashboard', ''],
                    ],
                    'Accounts' => [
                        ['/whm/accounts.php', 'users', 'Accounts', 'accounts', 'whm_accounts'],
                        ['/whm/packages.php', 'package', 'Packages', 'packages', 'whm_packages'],
                        ['/whm/domains.php', 'globe', 'Domains', 'domains', 'whm_domains'],
                    ],
                    'System' => [
                        ['/whm/tunnels.php', 'cloud', 'Tunnels', 'tunnels', 'whm_tunnels'],
                        ['/whm/server.php', 'server', 'Server Info', 'server', 'whm_server'],
                        ['/whm/health.php', 'activity', 'Server Health', 'health', 'whm_health'],
                        ['/whm/api-keys.php', 'key-round', 'API Keys', 'apikeys', 'whm_api_keys'],
                        ['/whm/password.php', 'lock-keyhole', 'Password', 'password', 'whm_password'],
                    ],
                ]; ?>
            <?php else: ?>
                <?php $navSections = [
                    'Overview' => [
                        ['/cpanel/', 'layout-dashboard', 'Dashboard', 'dashboard', ''],
                    ],
                    'Files' => [
                        ['/file-manager', 'folder-open', 'File Manager', 'files', 'kod_file_manager'],
                        ['/cpanel/indexes.php', 'grid', 'Indexes', 'indexes', ''],
                        ['/cpanel/backups.php', 'archive', 'Backups', 'backups', 'backup_services'],
                        ['/cpanel/restore.php', 'refresh-cw', 'Restore', 'restore', 'backup_services'],
                        ['/cpanel/cache.php', 'zap', 'Cache', 'cache', 'cache_services'],
                        ['/cpanel/error-pages.php', 'alert-circle', 'Error Pages', 'errorpages', ''],
                    ],
                    'Domains' => [
                        ['/cpanel/domains.php', 'globe', 'Domains', 'domains', ''],
                        ['/cpanel/subdomains.php', 'git-branch', 'Subdomains', 'subdomains', 'subdomain_services'],
                        ['/cpanel/addon-domains.php', 'plus-circle', 'Addon Domains', 'addondomains', 'addon_domain_services'],
                        ['/cpanel/dns.php', 'network', 'DNS Zone Editor', 'dns', 'dns_services'],
                        ['/cpanel/track-dns.php', 'route', 'Track DNS', 'trackdns', 'dns_services'],
                    ],
                    'FTP' => [
                        ['/cpanel/ftp.php', 'hard-drive', 'FTP Accounts', 'ftp', 'ftp_services'],
                    ],
                    'Security' => [
                        ['/cpanel/ssl.php', 'lock', 'SSL/TLS', 'ssl', 'ssl_services'],
                        ['/cpanel/two-factor.php', 'shield', 'Two-Factor Auth', 'twofactor', 'two_factor_auth'],
                        ['/cpanel/password-security.php', 'key-round', 'Password &amp; Security', 'passwordsecurity', ''],
                        ['/cpanel/ip-blocker.php', 'shield-x', 'IP Blocker', 'ipblocker', 'ip_blocker'],
                        ['/cpanel/leech-protection.php', 'user-check', 'Leech Protection', 'leechprotection', ''],
                        ['/cpanel/modsecurity.php', 'shield-alert', 'ModSecurity', 'modsecurity', 'modsecurity'],
                        ['/cpanel/malware-scanner.php', 'search', 'Malware Scanner', 'malware', 'malware_scanner'],
                        ['/cpanel/privacy.php', 'shield', 'Privacy', 'privacy', ''],
                    ],
                    'Software' => [
                        ['/cpanel/php-versions.php', 'code', 'PHP Selector', 'phpversions', 'php_selector'],
                        ['/cpanel/php-ini.php', 'settings', 'PHP INI Editor', 'phpini', 'php_ini_editor'],
                        ['/cpanel/php-extensions.php', 'plug', 'PHP Extensions', 'phpexts', 'php_extensions'],
                        ['/cpanel/web-apps.php', 'sparkles', 'Web Apps', 'webapps', 'web_apps'],
                        ['/cpanel/python-apps.php', 'code', 'Python Apps', 'pythonapps', 'python_apps'],
                        ['/cpanel/nodejs-apps.php', 'terminal', 'Node.js Apps', 'nodejsapps', 'nodejs_apps'],
                        ['/cpanel/wordpress.php', 'globe', 'WordPress', 'wordpress', 'wordpress_toolkit'],
                    ],
                    'Advanced' => [
                        ['/cpanel/metrics.php', 'bar-chart-3', 'Metrics', 'metrics', 'metrics_services'],
                        ['/cpanel/bandwidth.php', 'activity', 'Bandwidth', 'bandwidth', 'bandwidth_services'],
                        ['/cpanel/resource-usage.php', 'activity', 'Resource Usage', 'resourceusage', ''],
                        ['/cpanel/contact-info.php', 'contact', 'Contact Info', 'contactinfo', ''],
                        ['/cpanel/mime-types.php', 'file-text', 'MIME Types', 'mimetypes', ''],
                    ],
                ]; ?>
            <?php endif; ?>
            <?php foreach ($navSections as $sectionTitle => $sectionItems): ?>
                <?php $visibleItems = array_values(array_filter($sectionItems, function ($it) {
                    return !isset($it[4]) || $it[4] === '' || feature_flag($it[4]);
                })); ?>
                <?php if (!$visibleItems) continue; ?>
                <div class="nav-section" style="padding:15px 20px 5px; color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;"><?= $sectionTitle ?></div>
                <?php foreach ($visibleItems as $navItem): ?>
                    <a href="<?= $navItem[0] ?>" class="nav-item <?= $navItem[3] !== '' && ($nav ?? '') === $navItem[3] ? 'active' : '' ?>" style="display:flex; align-items:center; gap:12px; padding:12px 20px; color:#cbd5e1; text-decoration:none;">
                        <i data-lucide="<?= $navItem[1] ?>" style="width:18px;"></i> <?= $navItem[2] ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php unset($sectionItems, $visibleItems, $navItem); ?>
        </nav>
        <div class="sidebar-footer">
            <a href="/logout" class="nav-item" style="display:flex; align-items:center; gap:12px; padding:12px 14px; color:#cbd5e1; text-decoration:none;">
                <i data-lucide="log-out" style="width:18px; height:18px;"></i> <span style="font-weight:600;">Log Out</span>
            </a>
        </div>
    </aside>

    <div class="topbar">
        <div class="topbar-left" style="display:flex; align-items:center; gap:15px;">
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i data-lucide="menu"></i>
            </button>
            <span class="page-title" style="font-weight:700; font-size:18px; color:#1e293b;"><?= $page_title ?? 'Dashboard' ?></span>
        </div>
        <div class="topbar-right" style="display:flex; align-items:center; gap:15px;">
            <?php if (feature_flag('tool_search')): ?>
            <button class="topbar-search-btn" id="topbarSearchBtn" aria-label="Search tools" title="Search tools">
                <i data-lucide="search"></i>
            </button>
            <?php endif; ?>
            <a href="/logout" class="topbar-logout" title="Log Out">
                <i data-lucide="log-out"></i>
                <span class="topbar-logout-label">Log Out</span>
            </a>
        </div>
    </div>

    <main class="main-content">
