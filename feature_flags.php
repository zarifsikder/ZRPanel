<?php
/**
 * ZRPanel Feature Flags — enable/disable site features from a secret
 * admin page (/features). Flags are persisted as JSON so they survive
 * restarts and don't depend on the database.
 *
 * API:
 *   feature_flag($key)            -> bool (stored value or registry default)
 *   feature_flag_set($key, $bool) -> persist a value
 *   feature_flags_registry()      -> full ordered registry array
 *   feature_flags_state()         -> key => computed bool (registry defaults applied)
 */

if (!function_exists('feature_flags_file')) {
    function feature_flags_file() {
        return __DIR__ . '/feature_flags.json';
    }
}

if (!function_exists('feature_flags_registry')) {
    function feature_flags_registry() {
        static $registry = null;
        if ($registry !== null) {
            return $registry;
        }
        $registry = [
            // ------------------------------------------------- General
            'maintenance_mode'      => ['label' => 'Maintenance Mode',        'cat' => 'General',   'icon' => 'wrench',       'default' => false, 'desc' => 'Put the panel into maintenance. New logins are blocked and the public site shows a maintenance page.'],
            'signup_enabled'        => ['label' => 'New Signups',             'cat' => 'General',   'icon' => 'user-plus',    'default' => true,  'desc' => 'Allow new customer accounts to be created.'],
            'debug_mode'            => ['label' => 'Debug Mode',              'cat' => 'General',   'icon' => 'bug',          'default' => false, 'desc' => 'Show PHP errors, query logs and the debug panel.'],
            'tool_search'           => ['label' => 'Tool Search (Ctrl+K)',    'cat' => 'General',   'icon' => 'search',       'default' => true,  'desc' => 'Show the search bar overlay to find tools quickly.'],
            'glass_theme'           => ['label' => 'Glass UI Theme',          'cat' => 'General',   'icon' => 'sparkles',     'default' => true,  'desc' => 'Use the liquid-glass visual theme for the panel.'],

            // ------------------------------------------------- Access
            'cpanel_access'         => ['label' => 'cPanel Client Access',    'cat' => 'Access',    'icon' => 'layout-dashboard', 'default' => true, 'desc' => 'Allow customers to log in to the client control panel.'],
            'whm_access'            => ['label' => 'WHM Admin Access',        'cat' => 'Access',    'icon' => 'shield',       'default' => true,  'desc' => 'Allow administrators to log in to WHM.'],
            
            'api_access'            => ['label' => 'Public API Access',       'cat' => 'Access',    'icon' => 'key-round',    'default' => true,  'desc' => 'Allow access to the panel API endpoints.'],
            'two_factor_auth'       => ['label' => 'Two-Factor Authentication', 'cat' => 'Access', 'icon' => 'shield-check', 'default' => true,  'desc' => 'Require/allow 2FA for panel accounts.'],
            'secret_terminal'       => ['label' => 'Secret Terminal',         'cat' => 'Access',    'icon' => 'terminal',     'default' => true,  'desc' => 'Enable the hidden shell terminal access page.'],

            // ------------------------------------------------- cPanel services
            'kod_file_manager'       => ['label' => 'File Manager',            'cat' => 'cPanel',    'icon' => 'folder-open',  'default' => true,  'desc' => 'Serve the KODExplorer file manager at /file-manager.'],
            'ftp_services'          => ['label' => 'FTP Services',            'cat' => 'cPanel',    'icon' => 'hard-drive',   'default' => true,  'desc' => 'FTP accounts and file transfer.'],
            'dns_services'          => ['label' => 'DNS Zone Editor',         'cat' => 'cPanel',    'icon' => 'network',      'default' => true,  'desc' => 'Manage DNS records for the account.'],
            'subdomain_services'    => ['label' => 'Subdomains',              'cat' => 'cPanel',    'icon' => 'git-branch',   'default' => true,  'desc' => 'Create and manage subdomains.'],
            'addon_domain_services' => ['label' => 'Addon Domains',           'cat' => 'cPanel',    'icon' => 'plus-circle',  'default' => true,  'desc' => 'Add additional domains to the account.'],
            'ssl_services'          => ['label' => 'SSL/TLS',                 'cat' => 'cPanel',    'icon' => 'lock',         'default' => true,  'desc' => 'Issue and manage SSL certificates.'],
            'cron_services'         => ['label' => 'Cron Jobs',               'cat' => 'cPanel',    'icon' => 'clock',        'default' => true,  'desc' => 'Schedule automated tasks.'],
            'backup_services'       => ['label' => 'Backups',                 'cat' => 'cPanel',    'icon' => 'archive',      'default' => true,  'desc' => 'Create, download and restore backups.'],
            'php_selector'          => ['label' => 'PHP Version Selector',    'cat' => 'cPanel',    'icon' => 'code',         'default' => true,  'desc' => 'Switch the PHP version for the account.'],
            'php_ini_editor'        => ['label' => 'PHP INI Editor',          'cat' => 'cPanel',    'icon' => 'settings',     'default' => true,  'desc' => 'Edit php.ini settings.'],
            'php_extensions'        => ['label' => 'PHP Extensions',          'cat' => 'cPanel',    'icon' => 'plug',         'default' => true,  'desc' => 'Enable or disable PHP extensions (A-Z) for the account.'],
            'web_apps'              => ['label' => 'Web Apps',                'cat' => 'cPanel',    'icon' => 'sparkles',     'default' => true,  'desc' => 'One-click web application installer.'],
            'wordpress_toolkit'     => ['label' => 'WordPress Toolkit',       'cat' => 'cPanel',    'icon' => 'globe',        'default' => true,  'desc' => 'Install and manage WordPress sites.'],
            'nodejs_apps'           => ['label' => 'Node.js Apps',            'cat' => 'cPanel',    'icon' => 'terminal',     'default' => true,  'desc' => 'Deploy Node.js applications.'],
            'python_apps'           => ['label' => 'Python Apps',             'cat' => 'cPanel',    'icon' => 'code',         'default' => true,  'desc' => 'Deploy Python applications.'],
            'malware_scanner'       => ['label' => 'Malware Scanner',         'cat' => 'cPanel',    'icon' => 'search',       'default' => true,  'desc' => 'Scan the account for malware.'],
            'modsecurity'           => ['label' => 'ModSecurity',             'cat' => 'cPanel',    'icon' => 'shield-alert', 'default' => true,  'desc' => 'Web application firewall rules.'],
            'ip_blocker'            => ['label' => 'IP Blocker',              'cat' => 'cPanel',    'icon' => 'shield-x',     'default' => true,  'desc' => 'Block access from specific IP addresses.'],
            'cache_services'        => ['label' => 'Cache',                   'cat' => 'cPanel',    'icon' => 'zap',          'default' => true,  'desc' => 'Cache management for the account.'],
            'metrics_services'      => ['label' => 'Metrics & Logs',          'cat' => 'cPanel',    'icon' => 'bar-chart-3',  'default' => true,  'desc' => 'Traffic analytics and log viewing.'],
            'bandwidth_services'    => ['label' => 'Bandwidth',               'cat' => 'cPanel',    'icon' => 'activity',     'default' => true,  'desc' => 'Bandwidth usage monitoring.'],
            'terminal_services'     => ['label' => 'Terminal',                'cat' => 'cPanel',    'icon' => 'terminal',     'default' => true,  'desc' => 'Web-based terminal access for the account.'],

            // ------------------------------------------------- Infrastructure
            'cloudflare_integration' => ['label' => 'Cloudflare Integration', 'cat' => 'Infrastructure', 'icon' => 'cloud',   'default' => true, 'desc' => 'Enable DNS/tunnel management through the Cloudflare API.'],
            'cloudflare_tunnel'     => ['label' => 'Cloudflare Tunnels',      'cat' => 'Infrastructure', 'icon' => 'route',     'default' => true, 'desc' => 'Expose sites through Cloudflare Tunnels.'],
            'local_tls'             => ['label' => 'Local TLS (stunnel)',     'cat' => 'Infrastructure', 'icon' => 'lock-keyhole', 'default' => true, 'desc' => 'Terminate HTTPS locally with the Let\'s Encrypt wildcard cert.'],

            // ------------------------------------------------- WHM
            'whm_accounts'          => ['label' => 'WHM Accounts',            'cat' => 'WHM',       'icon' => 'users',        'default' => true,  'desc' => 'Create and manage customer accounts.'],
            'whm_packages'          => ['label' => 'WHM Packages',            'cat' => 'WHM',       'icon' => 'package',      'default' => true,  'desc' => 'Hosting plans and package configuration.'],
            
            'whm_domains'           => ['label' => 'WHM Domains',             'cat' => 'WHM',       'icon' => 'globe',        'default' => true,  'desc' => 'Manage all domains across accounts.'],
            'whm_tunnels'           => ['label' => 'WHM Tunnels',             'cat' => 'WHM',       'icon' => 'cloud',        'default' => true,  'desc' => 'Manage Cloudflare tunnels for all accounts.'],
            'whm_server'            => ['label' => 'WHM Server Info',         'cat' => 'WHM',       'icon' => 'server',       'default' => true,  'desc' => 'Server details and statistics.'],
            
            'whm_health'            => ['label' => 'WHM Server Health',       'cat' => 'WHM',       'icon' => 'activity',     'default' => true,  'desc' => 'Monitor server resources and health.'],
            'whm_api_keys'          => ['label' => 'WHM API Keys',            'cat' => 'WHM',       'icon' => 'key-round',    'default' => true,  'desc' => 'Issue API keys for external integrations.'],
            'whm_password'          => ['label' => 'WHM Password Change',     'cat' => 'WHM',       'icon' => 'lock-keyhole', 'default' => true,  'desc' => 'Change the WHM administrator password.'],
            'whm_editor'            => ['label' => 'WHM System Editor',       'cat' => 'WHM',       'icon' => 'file-edit',    'default' => true,  'desc' => 'Edit server configuration files.'],
        ];
        return $registry;
    }
}

if (!function_exists('feature_flags_load')) {
    function feature_flags_load() {
        if (isset($GLOBALS['__ff_stored'])) {
            return $GLOBALS['__ff_stored'];
        }
        $stored = [];
        $file = feature_flags_file();
        if (is_file($file) && is_readable($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            }
        }
        $GLOBALS['__ff_stored'] = $stored;
        return $stored;
    }
}

if (!function_exists('feature_flag')) {
    function feature_flag($key) {
        $registry = feature_flags_registry();
        if (!array_key_exists($key, $registry)) {
            return true;
        }
        $stored = feature_flags_load();
        if (array_key_exists($key, $stored)) {
            return (bool)$stored[$key];
        }
        return (bool)$registry[$key]['default'];
    }
}

if (!function_exists('feature_flag_set')) {
    function feature_flag_set($key, $on) {
        $registry = feature_flags_registry();
        if (!array_key_exists($key, $registry)) {
            return false;
        }
        $stored = feature_flags_load();
        $stored[$key] = (bool)$on;
        $file = feature_flags_file();
        $tmp = $file . '.tmp';
        $ok = @file_put_contents($tmp, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false
              && @rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
            return false;
        }
        unset($GLOBALS['__ff_stored']);
        return true;
    }
}

if (!function_exists('feature_flags_state')) {
    function feature_flags_state() {
        $state = [];
        foreach (feature_flags_registry() as $key => $meta) {
            $state[$key] = feature_flag($key);
        }
        return $state;
    }
}

if (!function_exists('feature_flags_categories')) {
    function feature_flags_categories() {
        $cats = [];
        foreach (feature_flags_registry() as $key => $meta) {
            $cats[$meta['cat']] = true;
        }
        return array_keys($cats);
    }
}

if (!function_exists('feature_disabled_page')) {
    function feature_disabled_page($key) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $site  = defined('SITE_NAME') ? SITE_NAME : 'ZRPanel';
        $label = 'This feature';
        if (function_exists('feature_flags_registry')) {
            $reg = feature_flags_registry();
            if (isset($reg[$key]['label'])) {
                $label = $reg[$key]['label'];
            }
        }
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $site  = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow"><title>Feature Disabled - ' . $site . '</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#334155}.box{text-align:center;padding:40px;max-width:480px}.icon{font-size:46px;margin-bottom:14px;opacity:.5}.box h1{font-size:22px;margin:0 0 8px;color:#0f172a}.box p{color:#64748b;font-size:15px;line-height:1.6;margin:0}.btn{display:inline-block;margin-top:22px;padding:10px 20px;border-radius:10px;background:#3b82f6;color:#fff;text-decoration:none;font-size:14px;font-weight:600}.btn:hover{background:#2563eb}</style></head><body><div class="box"><div class="icon">&#128683;</div><h1>' . $label . ' is disabled</h1><p>This feature is currently turned off by the administrator. Please check back later.</p><a class="btn" href="/cpanel/">Back to Dashboard</a></div></body></html>';
        exit;
    }
}

if (!function_exists('require_feature')) {
    function require_feature($key) {
        if (feature_flag($key)) {
            return true;
        }
        feature_disabled_page($key);
    }
}

if (!function_exists('render_maintenance_page')) {
    function render_maintenance_page() {
        http_response_code(503);
        header('Retry-After: 3600');
        $site = defined('SITE_NAME') ? SITE_NAME : 'ZRPanel';
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Maintenance - ' . htmlspecialchars($site) . '</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#334155}
        .box{text-align:center;padding:40px;max-width:520px}
        .icon{font-size:52px;margin-bottom:16px}
        .pulse{display:inline-block;animation:pulse 1.6s infinite}
        @keyframes pulse{0%,100%{opacity:.35}50%{opacity:1}}
        .box h1{font-size:26px;margin:0 0 10px;color:#0f172a}
        .box p{color:#64748b;font-size:15px;line-height:1.6;margin:0 0 24px}
        .bar{width:220px;height:4px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:0 auto}
        .bar span{display:block;height:100%;width:40%;background:#6366f1;border-radius:99px;animation:slide 1.2s ease-in-out infinite}
        @keyframes slide{0%{transform:translateX(-100%)}100%{transform:translateX(250%)}}
    </style>
</head>
<body>
    <div class="box">
        <div class="icon"><span class="pulse">&#128295;</span></div>
        <h1>We\'re under maintenance</h1>
        <p>' . htmlspecialchars($site) . ' is temporarily offline for scheduled maintenance and upgrades. We\'ll be back shortly — please check back in a few minutes.</p>
        <div class="bar"><span></span></div>
    </div>
</body>
</html>';
        exit;
    }
}
