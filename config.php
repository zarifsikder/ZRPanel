<?php
// Static asset requests (CSS/JS/images/fonts) never need a session. Defining
// NO_SESSION before this file is included skips session_start() so asset
// responses carry no Set-Cookie header and write no session files.
if (!defined('NO_SESSION')) {
    session_name('zenpanel');
    @session_save_path('/data/data/com.termux/files/usr/tmp');
    session_start();
}

require_once __DIR__ . '/feature_flags.php';

// ============================================================
// Debug Mode
// ============================================================

define('DEBUG_ENABLED', feature_flag('debug_mode'));
define('DEBUG_LOG_FILE', __DIR__ . '/debug.log');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log("[PHP] " . $errstr . " in " . $errfile . ":" . $errline);
    return true;
});

require_once __DIR__ . '/Debug.php';
Debug::init();

// ============================================================
// Local MariaDB Configuration (Termux Socket)
// ============================================================

define('PANEL_DB_HOST',     'localhost');
define('PANEL_DB_PORT',     '3306');
define('PANEL_DB_NAME',     'panel');
define('PANEL_DB_USER',     'root');
define('PANEL_DB_PASS',     '');
define('PANEL_DB_CHARSET',  'utf8mb4');
define('PANEL_DB_SOCKET',   '/data/data/com.termux/files/usr/var/run/mysqld/mysqld.sock');

// ============================================================
// Site Constants
// ============================================================

define('SITE_NAME',    'ZRPanel');
define('SITE_TAGLINE', 'Fast, Reliable Web Hosting');

// ============================================================
// Local secrets (Cloudflare, panel auth, shell, site domain)
// ============================================================

$__cfg = [];
if (is_file(__DIR__ . '/config.local.php')) {
    $__cfg = (array) require __DIR__ . '/config.local.php';
}
function panel_secret($name, $def = '') {
    global $__cfg;
    if (!empty($__cfg[$name]) && is_scalar($__cfg[$name])) {
        return (string)$__cfg[$name];
    }
    $env = getenv($name);
    return ($env === false || $env === '') ? $def : $env;
}

/**
 * Return the whole local config array (config.local.php) as read from disk
 */
function panel_local_config() {
    $cfg = [];
    $path = __DIR__ . '/config.local.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }
    return $cfg;
}

/**
 * Merge $new over the existing local config and atomically rewrite config.local.php
 */
function panel_write_local_config(array $new) {
    $existing = panel_local_config();
    $merged = array_merge($existing, $new);
    $export = var_export($merged, true);
    $php = "<?php\n// ZRPanel local secrets - GIT IGNORED. Managed by the panel and install.sh.\nreturn " . $export . ";\n";
    $path = __DIR__ . '/config.local.php';
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $php) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    return true;
}

define('SITE_DOMAIN', panel_secret('SITE_DOMAIN', 'localhost'));

// ============================================================
// Cloudflare DNS Integration
// ============================================================

define('CF_API_TOKEN', panel_secret('CF_API_TOKEN'));
define('CF_ZONE_ID',   panel_secret('CF_ZONE_ID'));
define('CF_TUNNEL_ID', panel_secret('CF_TUNNEL_ID'));
define('CF_ENABLED',   feature_flag('cloudflare_integration') && CF_API_TOKEN !== '' && CF_ZONE_ID !== '');

define('SERVER_IP', panel_secret('SERVER_IP', '127.0.0.1'));

// ============================================================
// Secret Feature-Flags Admin (username: admin / password: admin123)
// ============================================================

define('FEATURES_USERNAME', panel_secret('FEATURES_USERNAME', 'admin'));
// Password Hash for 'admin123'
define('FEATURES_PASSWORD_HASH', panel_secret('FEATURES_PASSWORD_HASH', '$2y$10$v7g8tqL8N7z.o9eHwK79n.oD87e9QfXk9Rz8lX3wWJ5y3Z/JjSKe6'));

// ============================================================
// Secret Terminal Access
// ============================================================

define('SECRET_SHELL_KEY', panel_secret('SECRET_SHELL_KEY'));

unset($__cfg);

// ============================================================
// PDO Connection
// ============================================================

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s;unix_socket=%s',
            PANEL_DB_HOST, PANEL_DB_PORT, PANEL_DB_NAME, PANEL_DB_CHARSET, PANEL_DB_SOCKET
        );
        $start = microtime(true);
        $pdo = new PDO($dsn, PANEL_DB_USER, PANEL_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        Debug::logQuery('[CONNECT] ' . PANEL_DB_HOST . '/' . PANEL_DB_NAME, [], microtime(true) - $start, 1);
    }
    return $pdo;
}

// ============================================================
// API Key Encryption Helpers
// ============================================================

function panel_api_crypto_key() {
    $stmt = db()->prepare("SELECT `value` FROM `config` WHERE `key_name` = 'api_key_crypto_key'");
    $stmt->execute();
    $k = $stmt->fetchColumn();
    if ($k) {
        return $k;
    }
    $k = base64_encode(random_bytes(32));
    $ins = db()->prepare("REPLACE INTO `config` (`key_name`, `value`) VALUES ('api_key_crypto_key', ?)");
    $ins->execute([$k]);
    return $k;
}

function panel_api_encrypt($plain) {
    $key = base64_decode(panel_api_crypto_key());
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return null;
    }
    return base64_encode($iv . $tag . $cipher);
}

function panel_api_decrypt($stored) {
    try {
        $raw = base64_decode($stored);
        if (!$raw || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', base64_decode(panel_api_crypto_key()), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    } catch (Throwable $e) {
        return null;
    }
}

// ============================================================
// Database Initialization
// ============================================================

function init_db() {
    try {
        $schema = panel_schema_defs();
        $expected = count($schema);
        $cacheDir = __DIR__ . '/.cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $schema_ok = ((int)@file_get_contents($cacheDir . '/schema_count') === $expected);
        $migrated  = is_file($cacheDir . '/schema_mailer_v2');
        $dbset     = is_file($cacheDir . '/schema_dbset');
        $apikeySec = is_file($cacheDir . '/schema_apikey_secret');
        
        if ($schema_ok && $migrated && $dbset && $apikeySec) {
            panel_heal_column_migrations();
            panel_ensure_admin_user(); // Ensure admin user exists
            return;
        }

        if (!$schema_ok) {
            $serverDsn = sprintf(
                'mysql:host=%s;port=%s;charset=%s;unix_socket=%s',
                PANEL_DB_HOST, PANEL_DB_PORT, PANEL_DB_CHARSET, PANEL_DB_SOCKET
            );
            $srv = new PDO($serverDsn, PANEL_DB_USER, PANEL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $srv->exec("CREATE DATABASE IF NOT EXISTS `" . PANEL_DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $srv = null;
        }

        $pdo = db();
        if (!$schema_ok) {
            $pdo->exec("USE `" . PANEL_DB_NAME . "`");
            foreach ($schema as $name => $sql) {
                $pdo->exec($sql);
            }
            @file_put_contents($cacheDir . '/schema_count', (string)$expected);
        }

        $dropped_tables = [
            'email_logs', 'email_accounts', 'email_forwarders', 'autoresponders',
            'email_filters', 'default_address', 'email_routing',
            'mailing_lists', 'spam_config',
            'databases', 'database_users', 'database_user_assignments',
            'database_servers', 'database_usage', 'database_audit_logs', 'database_imports',
            'reseller_packages', 'reseller_info',
        ];
        foreach ($dropped_tables as $drop_t) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$drop_t`");
            } catch (PDOException $e) {
                Debug::logError('drop ' . $drop_t . ' failed: ' . $e->getMessage());
            }
        }
        @file_put_contents($cacheDir . '/schema_cleanup_v1', '1');

        panel_heal_column_migrations();
        panel_ensure_admin_user(); // Create admin user if not exists
        @file_put_contents($cacheDir . '/schema_mailer_v2', '1');
        Debug::log('init_db', 'All tables created/verified');
    } catch (PDOException $e) {
        Debug::logError('init_db failed: ' . $e->getMessage());
    }
}

/**
 * Ensures that the admin user (admin / admin123) exists in the database.
 */
function panel_ensure_admin_user() {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT `id` FROM `users` WHERE `username` = 'admin'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `email`, `role`, `status`) VALUES ('admin', ?, 'admin@localhost', 'whm', 'active')");
            $ins->execute([$hash]);
            Debug::log('init_db', 'Default admin user created successfully.');
        }
    } catch (Throwable $e) {
        Debug::logError('panel_ensure_admin_user failed: ' . $e->getMessage());
    }
}

/**
 * Self-healing column migrations.
 */
function panel_heal_column_migrations() {
    $cacheDir = __DIR__ . '/.cache';
    try {
        $pdo = db();
    } catch (PDOException $e) {
        Debug::logError('column migration: cannot connect: ' . $e->getMessage());
        return;
    }
    $migrations = [
        [
            'marker'  => 'schema_dbset',
            'check'   => "SHOW COLUMNS FROM `packages` LIKE 'max_db_users'",
            'alter'   => "ALTER TABLE `packages` ADD COLUMN `max_db_users` INT UNSIGNED NOT NULL DEFAULT 5 AFTER `max_databases`",
            'comment' => 'packages.max_db_users (max DB users per package)',
        ],
        [
            'marker'  => 'schema_apikey_secret',
            'check'   => "SHOW COLUMNS FROM `api_keys` LIKE 'key_secret'",
            'alter'   => "ALTER TABLE `api_keys` ADD COLUMN `key_secret` TEXT NULL AFTER `key_hash`",
            'comment' => 'api_keys.key_secret (encrypted raw key)',
        ],
    ];
    foreach ($migrations as $m) {
        try {
            if (!$pdo->query($m['check'])->fetch()) {
                $pdo->exec($m['alter']);
                Debug::log('column migration', $m['comment'] . ': added missing column');
            }
            @file_put_contents($cacheDir . '/' . $m['marker'], '1');
        } catch (PDOException $e) {
            @unlink($cacheDir . '/' . $m['marker']);
            Debug::logError('column migration failed (' . $m['comment'] . '): ' . $e->getMessage());
        }
    }
}

function panel_schema_defs() {
    $schema = [];

    $schema['config'] = "CREATE TABLE IF NOT EXISTS `config` (
            `key_name` VARCHAR(255) NOT NULL,
            `value` TEXT,
            PRIMARY KEY (`key_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['users'] = "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(32) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `domain` VARCHAR(255) DEFAULT NULL,
            `role` ENUM('whm','cpanel') NOT NULL DEFAULT 'cpanel',
            `package_id` INT UNSIGNED DEFAULT NULL,
            `home_dir` VARCHAR(500) DEFAULT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `php_version` VARCHAR(10) DEFAULT '8.5',
            `tunnel_token` TEXT DEFAULT NULL,
            `tunnel_status` VARCHAR(20) DEFAULT 'disconnected',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`),
            KEY `idx_domain` (`domain`),
            KEY `idx_role` (`role`),
            KEY `idx_package_id` (`package_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['packages'] = "CREATE TABLE IF NOT EXISTS `packages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `disk_quota` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `bandwidth` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `max_domains` INT UNSIGNED NOT NULL DEFAULT 5,
            `max_databases` INT UNSIGNED NOT NULL DEFAULT 5,
            `max_db_users` INT UNSIGNED NOT NULL DEFAULT 5,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['orders'] = "CREATE TABLE IF NOT EXISTS `orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `package_id` INT UNSIGNED DEFAULT NULL,
            `customer_name` VARCHAR(255) NOT NULL,
            `customer_email` VARCHAR(255) NOT NULL,
            `customer_phone` VARCHAR(50) DEFAULT NULL,
            `domain_name` VARCHAR(255) DEFAULT NULL,
            `period` ENUM('monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('pending','approved','completed','cancelled') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_package_id` (`package_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['nameservers'] = "CREATE TABLE IF NOT EXISTS `nameservers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ns_name` VARCHAR(50) NOT NULL,
            `ns_domain` VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['dns_records'] = "CREATE TABLE IF NOT EXISTS `dns_records` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `domain` VARCHAR(255) NOT NULL,
            `name` VARCHAR(255) NOT NULL DEFAULT '@',
            `type` VARCHAR(10) NOT NULL,
            `content` TEXT NOT NULL,
            `ttl` INT UNSIGNED NOT NULL DEFAULT 3600,
            `priority` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_domain` (`domain`),
            KEY `idx_type` (`type`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['subdomains'] = "CREATE TABLE IF NOT EXISTS `subdomains` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `subdomain` VARCHAR(255) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `document_root` VARCHAR(500) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_subdomain_domain` (`subdomain`, `domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['ftp_accounts'] = "CREATE TABLE IF NOT EXISTS `ftp_accounts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `username` VARCHAR(255) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `home_dir` VARCHAR(500) NOT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_username_user` (`username`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['cron_jobs'] = "CREATE TABLE IF NOT EXISTS `cron_jobs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `command` TEXT NOT NULL,
            `minute` VARCHAR(5) NOT NULL DEFAULT '*',
            `hour` VARCHAR(5) NOT NULL DEFAULT '*',
            `day` VARCHAR(5) NOT NULL DEFAULT '*',
            `month` VARCHAR(5) NOT NULL DEFAULT '*',
            `weekday` VARCHAR(5) NOT NULL DEFAULT '*',
            `status` ENUM('active','paused') NOT NULL DEFAULT 'active',
            `last_run` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['backups'] = "CREATE TABLE IF NOT EXISTS `backups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `type` ENUM('full','files','database') NOT NULL DEFAULT 'full',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['ssl_certs'] = "CREATE TABLE IF NOT EXISTS `ssl_certs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `cert` TEXT NOT NULL,
            `key_file` TEXT NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `status` VARCHAR(20) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_domain` (`domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['error_pages'] = "CREATE TABLE IF NOT EXISTS `error_pages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `error_code` INT UNSIGNED NOT NULL,
            `content` TEXT,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_error` (`user_id`, `error_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['redirects'] = "CREATE TABLE IF NOT EXISTS `redirects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `source` VARCHAR(500) NOT NULL,
            `destination` VARCHAR(500) NOT NULL,
            `type` ENUM('301','302','307','308') NOT NULL DEFAULT '301',
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['ip_blocklist'] = "CREATE TABLE IF NOT EXISTS `ip_blocklist` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `ip_address` VARCHAR(50) NOT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_ip_user` (`ip_address`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['directory_protection'] = "CREATE TABLE IF NOT EXISTS `directory_protection` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `directory_path` VARCHAR(500) NOT NULL,
            `protect_username` VARCHAR(255) NOT NULL,
            `protect_password` VARCHAR(255) NOT NULL,
            `realm` VARCHAR(255) NOT NULL DEFAULT 'Restricted Area',
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_dir_user` (`directory_path`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['mime_types'] = "CREATE TABLE IF NOT EXISTS `mime_types` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `extension` VARCHAR(50) NOT NULL,
            `mime_type` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_ext_user` (`extension`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['addon_domains'] = "CREATE TABLE IF NOT EXISTS `addon_domains` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `document_root` VARCHAR(500) NOT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_domain_user` (`domain`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['hotlink_protection'] = "CREATE TABLE IF NOT EXISTS `hotlink_protection` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `allowed_domains` TEXT,
            `block_extensions` TEXT DEFAULT '.jpg,.jpeg,.png,.gif,.bmp,.svg,.webp,.mp4,.mp3,.pdf,.zip,.rar',
            `allow_empty_referrer` TINYINT(1) NOT NULL DEFAULT 0,
            `redirect_url` VARCHAR(500) DEFAULT NULL,
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // --- Phase 2: Security Features ---
    $schema['user_2fa'] = "CREATE TABLE IF NOT EXISTS `user_2fa` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `secret` VARCHAR(255) NOT NULL,
            `backup_codes` TEXT,
            `status` ENUM('enabled','disabled') NOT NULL DEFAULT 'disabled',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['leech_protection'] = "CREATE TABLE IF NOT EXISTS `leech_protection` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `directory_path` VARCHAR(500) NOT NULL,
            `max_logins` INT UNSIGNED NOT NULL DEFAULT 5,
            `time_window` INT UNSIGNED NOT NULL DEFAULT 7200,
            `action` ENUM('block','redirect','notify') NOT NULL DEFAULT 'block',
            `redirect_url` VARCHAR(500) DEFAULT NULL,
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['modsecurity_rules'] = "CREATE TABLE IF NOT EXISTS `modsecurity_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `rule_id` VARCHAR(50) NOT NULL,
            `description` TEXT,
            `action` ENUM('on','off','detectonly') NOT NULL DEFAULT 'on',
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['malware_scans'] = "CREATE TABLE IF NOT EXISTS `malware_scans` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `files_scanned` INT UNSIGNED NOT NULL DEFAULT 0,
            `threats_found` INT UNSIGNED NOT NULL DEFAULT 0,
            `scan_path` VARCHAR(500) DEFAULT '/',
            `status` ENUM('clean','threats_found','running') NOT NULL DEFAULT 'clean',
            `details` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // --- Phase 3: Advanced Features ---
    $schema['directory_indexes'] = "CREATE TABLE IF NOT EXISTS `directory_indexes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `directory_path` VARCHAR(500) NOT NULL,
            `style` ENUM('default','fancying','icons','none','description') NOT NULL DEFAULT 'default',
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['apache_handlers'] = "CREATE TABLE IF NOT EXISTS `apache_handlers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `extension` VARCHAR(50) NOT NULL,
            `handler` VARCHAR(255) NOT NULL,
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_ext_user` (`extension`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['php_extension_settings'] = "CREATE TABLE IF NOT EXISTS `php_extension_settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `extension` VARCHAR(80) NOT NULL,
            `status` ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_ext_user` (`extension`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['resource_limits'] = "CREATE TABLE IF NOT EXISTS `resource_limits` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `cpu_limit` INT UNSIGNED NOT NULL DEFAULT 100,
            `memory_limit` INT UNSIGNED NOT NULL DEFAULT 512,
            `io_limit` INT UNSIGNED NOT NULL DEFAULT 10240,
            `entry_processes` INT UNSIGNED NOT NULL DEFAULT 20,
            `processes` INT UNSIGNED NOT NULL DEFAULT 100,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // --- Phase 4: Application Managers ---
    $schema['nodejs_apps'] = "CREATE TABLE IF NOT EXISTS `nodejs_apps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `app_name` VARCHAR(255) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `port` INT UNSIGNED NOT NULL DEFAULT 3000,
            `node_version` VARCHAR(10) NOT NULL DEFAULT '18',
            `root_dir` VARCHAR(500) NOT NULL,
            `status` ENUM('running','stopped','error') NOT NULL DEFAULT 'stopped',
            `start_command` VARCHAR(500) DEFAULT 'node index.js',
            `env_vars` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['python_apps'] = "CREATE TABLE IF NOT EXISTS `python_apps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `app_name` VARCHAR(255) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `port` INT UNSIGNED NOT NULL DEFAULT 8000,
            `python_version` VARCHAR(10) NOT NULL DEFAULT '3.11',
            `root_dir` VARCHAR(500) NOT NULL,
            `status` ENUM('running','stopped','error') NOT NULL DEFAULT 'stopped',
            `wsgi_file` VARCHAR(255) DEFAULT 'app.py',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['web_apps'] = "CREATE TABLE IF NOT EXISTS `web_apps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `app_name` VARCHAR(255) NOT NULL,
            `base_path` VARCHAR(255) NOT NULL,
            `deploy_type` ENUM('zip','git','dir') NOT NULL DEFAULT 'zip',
            `repo_url` VARCHAR(500) DEFAULT NULL,
            `framework` VARCHAR(50) DEFAULT NULL,
            `mode` ENUM('static','server') NOT NULL DEFAULT 'static',
            `package_manager` VARCHAR(20) NOT NULL DEFAULT 'npm',
            `install_command` VARCHAR(500) DEFAULT NULL,
            `build_command` VARCHAR(500) DEFAULT NULL,
            `start_command` VARCHAR(500) DEFAULT NULL,
            `static_dir` VARCHAR(255) NOT NULL DEFAULT 'dist',
            `node_version` VARCHAR(20) DEFAULT NULL,
            `port` INT UNSIGNED NOT NULL DEFAULT 3000,
            `env_vars` TEXT,
            `build_status` ENUM('none','installing','building','done','failed') NOT NULL DEFAULT 'none',
            `build_log` VARCHAR(500) DEFAULT NULL,
            `proxy_strip_base` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('running','stopped','error') NOT NULL DEFAULT 'stopped',
            `pm2_name` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            UNIQUE KEY `uk_user_base` (`user_id`, `base_path`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['wordpress_sites'] = "CREATE TABLE IF NOT EXISTS `wordpress_sites` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `install_path` VARCHAR(500) NOT NULL,
            `wp_version` VARCHAR(20) DEFAULT NULL,
            `db_name` VARCHAR(255) DEFAULT NULL,
            `db_user` VARCHAR(255) DEFAULT NULL,
            `admin_user` VARCHAR(255) DEFAULT NULL,
            `admin_email` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('active','inactive','updating') NOT NULL DEFAULT 'active',
            `last_scan` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['bandwidth_usage'] = "CREATE TABLE IF NOT EXISTS `bandwidth_usage` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `month` VARCHAR(7) NOT NULL,
            `bytes_used` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_domain_month` (`domain`, `month`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // --- API Keys ---
    $schema['api_keys'] = "CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key_hash` VARCHAR(64) NOT NULL,
            `prefix` VARCHAR(10) NOT NULL,
            `label` VARCHAR(255) NOT NULL,
            `permissions` VARCHAR(500) NOT NULL DEFAULT '*',
            `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
            `last_used_at` DATETIME DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_key_hash` (`key_hash`),
            KEY `idx_created_by` (`created_by`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $schema['sso_tokens'] = "CREATE TABLE IF NOT EXISTS `sso_tokens` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `token_hash` VARCHAR(64) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `used` TINYINT(1) NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_token_hash` (`token_hash`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // --- Phase 6: Preferences ---
    $schema['subaccounts'] = "CREATE TABLE IF NOT EXISTS `subaccounts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `sub_username` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(255) DEFAULT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `roles` TEXT,
            `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return $schema;
}

// ============================================================
// Helper Functions
// ============================================================

function h($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_whm() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'whm';
}

function require_login() {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function require_whm() {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'whm') {
        redirect('/cpanel/');
    }
}

function flash($type = null, $message = null) {
    if ($type === null) {
        if (empty($_SESSION['flash'])) return null;
        $all = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $all;
    }
    if ($message === null) {
        return $_SESSION['flash'][$type] ?? null;
    }
    $_SESSION['flash'][$type] = $message;
}

function format_size($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $bytes = max(0, (int)$bytes);
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify() {
    $t = $_POST['csrf_token'] ?? '';
    if (!is_string($t) || $t === '') return false;
    return hash_equals(csrf_token(), $t);
}

function csrf_fail() {
    http_response_code(403);
    echo 'Invalid or missing CSRF token. Please go back and try again.';
    exit;
}

function cached_dir_stats($home, $ttl = 60, $force = false) {
    $cacheDir = __DIR__ . '/.cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $key = md5($home);
    $file = $cacheDir . '/dirstats_' . $key;
    $now = time();

    if (!$force && is_file($file)) {
        $raw = @file_get_contents($file);
        $data = json_decode($raw, true);
        if (is_array($data)
            && isset($data['home'], $data['ts'], $data['total_files'], $data['total_size'], $data['breakdown'])
            && $data['home'] === $home
            && ($now - (int)$data['ts']) < $ttl) {
            return $data;
        }
    }

    $total_files = 0;
    $total_size = 0;
    $breakdown = ['html' => 0, 'images' => 0, 'scripts' => 0, 'data' => 0, 'other' => 0];
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
    $scriptExts = ['php', 'js', 'py', 'rb', 'pl', 'sh'];
    $dataExts = ['sql', 'db', 'sqlite', 'json', 'xml', 'csv'];
    $docExts = ['html', 'htm', 'css', 'txt', 'md', 'pdf', 'doc', 'docx'];

    if (is_dir($home)) {
        try {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($rii as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $total_files++;
                $size = $fileInfo->getSize();
                $total_size += $size;
                $ext = strtolower((string)$fileInfo->getExtension());
                if (in_array($ext, $imageExts, true)) {
                    $breakdown['images'] += $size;
                } elseif (in_array($ext, $scriptExts, true)) {
                    $breakdown['scripts'] += $size;
                } elseif (in_array($ext, $dataExts, true)) {
                    $breakdown['data'] += $size;
                } elseif (in_array($ext, $docExts, true)) {
                    $breakdown['html'] += $size;
                } else {
                    $breakdown['other'] += $size;
                }
            }
        } catch (Throwable $e) {}
    }

    $data = [
        'home'         => $home,
        'ts'           => $now,
        'total_files'  => $total_files,
        'total_size'   => $total_size,
        'breakdown'    => $breakdown,
    ];

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($data)) !== false) {
        @rename($tmp, $file);
    }
    return $data;
}

function resolve_customer_site($host, $ttl = 30, $force = false) {
    $cacheDir = __DIR__ . '/.cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $file = $cacheDir . '/site_' . md5($host);
    $now = time();

    if (!$force && is_file($file)) {
        $raw = @file_get_contents($file);
        $data = json_decode($raw, true);
        if (is_array($data)
            && isset($data['ts'], $data['matched'], $data['doc_root'], $data['suspended'])
            && ($now - (int)$data['ts']) < $ttl) {
            return $data;
        }
    }

    $result = ['matched' => false, 'doc_root' => '', 'suspended' => false, 'ts' => $now];
    $is_ip = preg_match('/^(\d+\.){3}\d+$/', $host);
    $parts = explode('.', $host);

    try {
        $db = db();
        if (!$is_ip && $host !== 'localhost' && $host !== '' && count($parts) >= 3) {
            $subdomain = $parts[0];
            $domain = implode('.', array_slice($parts, 1));

            $stmt = $db->prepare("SELECT s.*, u.home_dir, u.status AS user_status FROM subdomains s JOIN users u ON s.user_id = u.id WHERE s.subdomain = ? AND s.domain = ?");
            $stmt->execute([$subdomain, $domain]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sub) {
                if ($sub['status'] === 'suspended' || $sub['user_status'] === 'suspended') {
                    $result = ['matched' => true, 'doc_root' => '', 'suspended' => true, 'ts' => $now];
                } else {
                    $result = ['matched' => true, 'doc_root' => $sub['document_root'], 'suspended' => false, 'ts' => $now];
                }
            } else {
                $stmt2 = $db->prepare("SELECT home_dir, domain, status FROM users WHERE domain = ? AND role = 'cpanel'");
                $stmt2->execute([$host]);
                $user = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($user && $user['status'] === 'suspended') {
                    $result = ['matched' => true, 'doc_root' => '', 'suspended' => true, 'ts' => $now];
                } elseif ($user) {
                    $result = ['matched' => true, 'doc_root' => rtrim($user['home_dir'], '/') . '/public_html', 'suspended' => false, 'ts' => $now];
                }
            }
        } elseif ($host !== '' && $host !== 'localhost' && !$is_ip) {
            $stmt = $db->prepare("SELECT home_dir, domain, status FROM users WHERE domain = ? AND role = 'cpanel'");
            $stmt->execute([$host]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && $user['status'] === 'suspended') {
                $result = ['matched' => true, 'doc_root' => '', 'suspended' => true, 'ts' => $now];
            } elseif ($user) {
                $result = ['matched' => true, 'doc_root' => rtrim($user['home_dir'], '/') . '/public_html', 'suspended' => false, 'ts' => $now];
            }
        }
    } catch (Throwable $e) {
        return $result;
    }

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($result)) !== false) {
        @rename($tmp, $file);
    }
    return $result;
}

function clear_customer_site_cache() {
    $dir = __DIR__ . '/.cache';
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/site_*') as $f) @unlink($f);
    foreach (glob($dir . '/webapps_basepaths*') as $f) @unlink($f);
}

function cached_web_app_base_paths() {
    $cacheDir = __DIR__ . '/.cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $file = $cacheDir . '/webapps_basepaths';
    $now = time();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['ts']) && is_array($data['paths']) && ($now - (int)$data['ts']) < 10) {
            return $data['paths'];
        }
    }
    $paths = [];
    try {
        $stmt = db()->query("SELECT base_path FROM web_apps");
        $paths = array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'base_path'));
    } catch (Throwable $e) {}
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode(['ts' => $now, 'paths' => $paths])) !== false) {
        @rename($tmp, $file);
    }
    return $paths;
}

function server_hostname() {
    static $hn = false;
    if ($hn !== false) return $hn;
    $hn = 'localhost';

    $cacheFile = __DIR__ . '/.cache/servername';
    $raw = @file_get_contents($cacheFile);
    if (is_string($raw) && $raw !== '') {
        $c = json_decode($raw, true);
        if (is_array($c) && isset($c['ts'], $c['value'])
            && (time() - (int)$c['ts']) < 10
            && trim((string)$c['value']) !== '') {
            $hn = strtolower(trim((string)$c['value']));
            return $hn;
        }
    }

    try {
        $stmt = db()->query("SELECT value FROM config WHERE key_name = 'server_hostname'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string)$row['value']) !== '') {
            $hn = strtolower(trim((string)$row['value']));
        }
    } catch (Throwable $e) {}

    $tmp = $cacheFile . '.tmp';
    if (@file_put_contents($tmp, json_encode(['ts' => time(), 'value' => $hn])) !== false) {
        @rename($tmp, $cacheFile);
    }
    return $hn;
}

function valid_hostname($hn) {
    $hn = strtolower(trim((string)$hn));
    if ($hn === '' || $hn === 'localhost' || strlen($hn) > 253) return null;
    if (preg_match('/^(\d+\.){3}\d+$/', $hn)) return null;
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hn)) return null;
    return $hn;
}

function get_server_stats() {
    $load = [0, 0, 0];
    $uptime_line = @shell_exec('uptime 2>/dev/null');
    if (preg_match('/load average:\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)/', $uptime_line, $lm)) {
        $load = [(float)$lm[1], (float)$lm[2], (float)$lm[3]];
    } elseif (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
    }

    $storage_paths = ['/storage/emulated/0', '/data', '/'];
    $disk_total = 0;
    $disk_free = 0;
    foreach ($storage_paths as $path) {
        $t = @disk_total_space($path);
        $f = @disk_free_space($path);
        if ($t && $t > $disk_total) {
            $disk_total = $t;
            $disk_free = $f ?: 0;
        }
    }
    if ($disk_total === 0) {
        $disk_total = @disk_total_space('/') ?: 1;
        $disk_free = @disk_free_space('/') ?: 0;
    }
    $disk_used = $disk_total - $disk_free;

    $mem_used = 0;
    $mem_total = 0;
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m)) $mem_total = $m[1] * 1024;
        if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) {
            $mem_used = $mem_total - ($m[1] * 1024);
        } elseif (preg_match('/^MemFree:\s+(\d+)/m', $meminfo, $m)) {
            $mem_used = $mem_total - ($m[1] * 1024);
        }
    }

    $uptime = 0;
    if (is_readable('/proc/uptime')) {
        $up = @file_get_contents('/proc/uptime');
        if ($up) $uptime = (float)(explode(' ', $up)[0]);
    }
    if ($uptime <= 0) {
        $up_line = @shell_exec('uptime 2>/dev/null');
        if (preg_match('/up\s+(\d+)\s+days?,\s*(\d+):(\d+)/', $up_line, $um)) {
            $uptime = (int)$um[1] * 86400 + (int)$um[2] * 3600 + (int)$um[3] * 60;
        }
    }

    return [
        'php_version'     => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
        'cpu'             => array_map(function($v) { return number_format($v, 2); }, $load),
        'disk_used'       => $disk_used,
        'disk_total'      => $disk_total,
        'memory'          => ['used' => $mem_used, 'total' => $mem_total],
        'uptime'          => $uptime,
    ];
}

function format_uptime($seconds) {
    $hours = floor($seconds / 3600);
    $mins = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
}

function post_login_redirect_target() {
    $next = $_GET['next'] ?? '';
    if (is_string($next) && $next !== '' && strpos($next, '/') === 0 && strpos($next, '//') !== 0) {
        return $next;
    }
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'whm') return '/whm/';
    return '/cpanel/';
}

// ============================================================
// Cloudflare DNS Helper
// ============================================================

function cloudflare_create_dns($type, $name, $content, $ttl = 3600, $proxied = true) {
    if (!CF_ENABLED || empty(CF_API_TOKEN) || empty(CF_ZONE_ID)) {
        return ['success' => false, 'error' => 'Cloudflare integration is disabled or not configured'];
    }

    $url = 'https://api.cloudflare.com/client/v4/zones/' . CF_ZONE_ID . '/dns_records';

    $data = [
        'type'    => $type,
        'name'    => $name,
        'content' => $content,
        'ttl'     => $ttl,
        'proxied' => $proxied,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CF_API_TOKEN,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        Debug::logError("Cloudflare cURL error: {$curlError}");
        return ['success' => false, 'error' => $curlError];
    }

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($result['success']) && $result['success']) {
        return ['success' => true, 'record_id' => $result['result']['id'] ?? null];
    }

    $errorMsg = $result['errors'][0]['message'] ?? "HTTP {$httpCode}";
    Debug::logError("Cloudflare API error: {$errorMsg}");
    return ['success' => false, 'error' => $errorMsg];
}

function cloudflare_delete_dns_by_name($name) {
    if (!CF_ENABLED || empty(CF_API_TOKEN) || empty(CF_ZONE_ID)) return [];

    $records = cloudflare_list_dns($name);
    foreach ($records as $record) {
        cloudflare_delete_dns($record['id']);
    }
    return $records;
}

function cloudflare_list_dns($name = null) {
    if (!CF_ENABLED || empty(CF_API_TOKEN) || empty(CF_ZONE_ID)) return [];

    $url = 'https://api.cloudflare.com/client/v4/zones/' . CF_ZONE_ID . '/dns_records?per_page=100';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CF_API_TOKEN,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $records = $result['result'] ?? [];

    if ($name) {
        $name = strtolower($name);
        $records = array_filter($records, function($r) use ($name) {
            return strtolower($r['name']) === $name;
        });
    }

    return array_values($records);
}

function cloudflare_get_zone_id($domain) {
    if (!CF_ENABLED || empty(CF_API_TOKEN)) return null;

    $url = 'https://api.cloudflare.com/client/v4/zones?name=' . urlencode($domain) . '&status=active';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CF_API_TOKEN,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['result'][0]['id'] ?? null;
}

function cloudflare_create_dns_in_zone($zone_id, $type, $name, $content, $ttl = 3600, $proxied = false) {
    if (!CF_ENABLED || empty(CF_API_TOKEN) || empty($zone_id)) {
        return ['success' => false, 'error' => 'Cloudflare not configured'];
    }

    $url = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/dns_records';
    $data = [
        'type'    => $type,
        'name'    => $name,
        'content' => $content,
        'ttl'     => $ttl,
        'proxied' => $proxied,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CF_API_TOKEN,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($result['success']) && $result['success']) {
        return ['success' => true, 'record_id' => $result['result']['id'] ?? null];
    }

    return ['success' => false, 'error' => $result['errors'][0]['message'] ?? "HTTP {$httpCode}"];
}

function cloudflare_delete_dns_by_name_in_zone($zone_id, $name) {
    if (!CF_ENABLED || empty(CF_API_TOKEN) || empty($zone_id)) return;

    $url = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/dns_records?per_page=100';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CF_API_TOKEN,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $records = $result['result'] ?? [];
    $name = strtolower($name);

    foreach ($records as $record) {
        if (strtolower($record['name']) === $name) {
            $del_url = 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/dns_records/' . $record['id'];
            $ch = curl_init($del_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CF_API_TOKEN],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

function cloudflare_delete_dns($record_id) {
    if (!CF_ENABLED || empty(CF_API_TOKEN) || empty(CF_ZONE_ID)) return false;

    $url = 'https://api.cloudflare.com/client/v4/zones/' . CF_ZONE_ID . '/dns_records/' . $record_id;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CF_API_TOKEN,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['success'] ?? false;
}
