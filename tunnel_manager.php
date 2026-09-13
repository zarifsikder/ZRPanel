<?php
require_once __DIR__ . '/config.php';

class TunnelManager {

    private static $tunnelDir = __DIR__ . '/tunnel_data';

    private static function userDir($userId) {
        $dir = self::$tunnelDir . '/' . (int)$userId;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    private static function pidFile($userId) {
        return self::userDir($userId) . '/cloudflared.pid';
    }

    private static function logFile($userId) {
        return self::userDir($userId) . '/cloudflared.log';
    }

    public static function isRunning($userId) {
        $pidFile = self::pidFile($userId);
        if (!file_exists($pidFile)) return false;
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid <= 0) return false;
        $result = @file_get_contents("/proc/{$pid}/cmdline");
        if ($result !== false && strpos($result, 'cloudflared') !== false) return true;
        @unlink($pidFile);
        return false;
    }

    public static function validateToken($token) {
        $token = trim($token);
        if (empty($token)) return ['valid' => false, 'error' => 'Token is empty'];
        if (strlen($token) < 20) return ['valid' => false, 'error' => 'Token too short'];
        if (!preg_match('/^[A-Za-z0-9_\-\.\/=]+$/', $token)) {
            return ['valid' => false, 'error' => 'Invalid token format'];
        }
        return ['valid' => true, 'error' => null];
    }

    public static function start($userId, $token) {
        $token = trim($token);
        $validation = self::validateToken($token);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        if (self::isRunning($userId)) {
            self::stop($userId);
        }

        $dir = self::userDir($userId);
        $logFile = self::logFile($userId);
        $pidFile = self::pidFile($userId);

        $cmd = "setsid nohup cloudflared tunnel --loglevel info --logfile " . escapeshellarg($logFile) . " run --token " . escapeshellarg($token) . " < /dev/null > /dev/null 2>&1 & echo $!";
        $pid = trim(shell_exec($cmd));

        if (empty($pid) || !ctype_digit($pid)) {
            return ['success' => false, 'error' => 'Failed to start cloudflared process'];
        }

        file_put_contents($pidFile, $pid);

        sleep(2);

        if (!self::isRunning($userId)) {
            @unlink($pidFile);
            $logContent = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
            }
            $errorMsg = 'Tunnel failed to start';
            if (!empty($logContent)) {
                if (preg_match('/error[^\n]*/i', $logContent, $m)) {
                    $errorMsg = $m[0];
                } elseif (preg_match('/ERR[^\n]*/i', $logContent, $m)) {
                    $errorMsg = $m[0];
                }
            }
            return ['success' => false, 'error' => $errorMsg];
        }

        $db = db();
        $db->prepare("UPDATE users SET tunnel_status = 'connected' WHERE id = ?")->execute([$userId]);

        return ['success' => true, 'pid' => (int)$pid];
    }

    public static function stop($userId) {
        $pidFile = self::pidFile($userId);
        if (!file_exists($pidFile)) {
            $db = db();
            $db->prepare("UPDATE users SET tunnel_status = 'disconnected' WHERE id = ?")->execute([$userId]);
            return ['success' => true];
        }

        $pid = (int)trim(file_get_contents($pidFile));

        if ($pid > 0) {
            posix_kill($pid, SIGTERM);
            usleep(500000);
            if (self::isRunning($userId)) {
                posix_kill($pid, SIGKILL);
                usleep(200000);
            }
        }

        @unlink($pidFile);

        $db = db();
        $db->prepare("UPDATE users SET tunnel_status = 'disconnected' WHERE id = ?")->execute([$userId]);

        return ['success' => true];
    }

    public static function restart($userId, $token = null) {
        self::stop($userId);
        usleep(500000);

        $db = db();
        if ($token === null) {
            $stmt = $db->prepare("SELECT tunnel_token FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            $token = $row['tunnel_token'] ?? null;
        }

        if (empty($token)) {
            return ['success' => false, 'error' => 'No tunnel token found'];
        }

        return self::start($userId, $token);
    }

    public static function getLogs($userId, $lines = 50) {
        $logFile = self::logFile($userId);
        if (!file_exists($logFile)) return '';

        $content = file_get_contents($logFile);
        $allLines = explode("\n", $content);
        $allLines = array_filter($allLines);
        $allLines = array_slice($allLines, -$lines);
        return implode("\n", $allLines);
    }

    public static function clearLogs($userId) {
        $logFile = self::logFile($userId);
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }
        return true;
    }

    public static function status($userId) {
        $db = db();
        $stmt = $db->prepare("SELECT id, username, domain, tunnel_token, tunnel_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        $running = self::isRunning($userId);
        $status = $running ? 'connected' : 'disconnected';

        if ($user['tunnel_status'] !== $status) {
            $db->prepare("UPDATE users SET tunnel_status = ? WHERE id = ?")->execute([$status, $userId]);
        }

        return [
            'user_id'    => $user['id'],
            'username'   => $user['username'],
            'domain'     => $user['domain'],
            'has_token'  => !empty($user['tunnel_token']),
            'status'     => $status,
            'running'    => $running,
            'pid'        => self::getPid($userId),
        ];
    }

    public static function getPid($userId) {
        $pidFile = self::pidFile($userId);
        if (!file_exists($pidFile)) return null;
        return (int)trim(file_get_contents($pidFile));
    }

    public static function listAll() {
        $db = db();
        $users = $db->query("SELECT id, username, domain, tunnel_token, tunnel_status FROM users WHERE role = 'cpanel' AND tunnel_token IS NOT NULL ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($users as $u) {
            $running = self::isRunning($u['id']);
            $status = $running ? 'connected' : 'disconnected';
            if ($u['tunnel_status'] !== $status) {
                $db->prepare("UPDATE users SET tunnel_status = ? WHERE id = ?")->execute([$status, $u['id']]);
            }
            $results[] = [
                'user_id'   => $u['id'],
                'username'  => $u['username'],
                'domain'    => $u['domain'],
                'has_token' => !empty($u['tunnel_token']),
                'status'    => $status,
                'running'   => $running,
                'pid'       => self::getPid($u['id']),
            ];
        }
        return $results;
    }

    public static function saveToken($userId, $token) {
        $token = trim($token);
        $validation = self::validateToken($token);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        $db = db();
        $db->prepare("UPDATE users SET tunnel_token = ? WHERE id = ?")->execute([$token, $userId]);
        return ['success' => true];
    }

    public static function removeToken($userId) {
        self::stop($userId);
        $db = db();
        $db->prepare("UPDATE users SET tunnel_token = NULL, tunnel_status = 'disconnected' WHERE id = ?")->execute([$userId]);

        $dir = self::userDir($userId);
        if (is_dir($dir)) {
            @unlink(self::logFile($userId));
            @unlink(self::pidFile($userId));
            @rmdir($dir);
        }
        return ['success' => true];
    }

    // ------------------------------------------------------------
    // Server / panel tunnel (the connector that exposes the panel itself).
    // Token lives in tunnel_data/panel/connector.token — the same file that
    // ~/start and install.sh use, so changes made here apply next start too.
    // ------------------------------------------------------------

    private static function panelDir() {
        $dir = self::$tunnelDir . '/panel';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    private static function panelTokenFile() { return self::panelDir() . '/connector.token'; }
    private static function panelPidFile()   { return self::panelDir() . '/cloudflared.pid'; }
    private static function panelLogFile()   { return self::panelDir() . '/cloudflared.log'; }

    public static function panelGetToken() {
        $f = self::panelTokenFile();
        if (!is_file($f)) return '';
        return trim((string)file_get_contents($f));
    }

    public static function panelSaveToken($token) {
        $token = trim($token);
        $v = self::validateToken($token);
        if (!$v['valid']) return $v;
        $f = self::panelTokenFile();
        $tmp = $f . '.tmp';
        $ok = @file_put_contents($tmp, $token) !== false && @chmod($tmp, 0600);
        if ($ok && !@rename($tmp, $f)) { @unlink($tmp); $ok = false; }
        if (!$ok) return ['success' => false, 'error' => 'Cannot write the tunnel token file'];
        @chmod($f, 0600);
        return ['success' => true];
    }

    private static function panelConfigFile() {
        return self::panelDir() . '/config.yml';
    }

    /**
     * Generate the local cloudflared config that maps the panel public hostname
     * (the global domain) to the local admin server. The connector token is
     * still managed separately, but this file records the ingress so a local
     * run with a credentials file is possible and the intent is inspectable.
     */
    public static function panelWriteConfig($domain) {
        $domain = trim((string)$domain);
        if ($domain === '') return ['success' => false, 'error' => 'No domain set'];
        $dir = self::panelDir();
        $yml = "# Generated by ZRPanel on " . date('c') . "\n"
            . "# Maps the panel public hostname to the local admin server.\n"
            . "# Public hostname: " . $domain . "\n"
            . "tunnel: zenpanel-panel\n"
            . "credentials-file: " . $dir . "/credentials.json\n"
            . "ingress:\n"
            . "  - hostname: " . $domain . "\n"
            . "    service: http://127.0.0.1:8080\n"
            . "  - service: http_status:404\n";
        $path = self::panelConfigFile();
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $yml) === false) {
            return ['success' => false, 'error' => 'Cannot write the cloudflared config file'];
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); return ['success' => false, 'error' => 'Cannot save the cloudflared config file']; }
        @chmod($path, 0600);
        return ['success' => true, 'domain' => $domain, 'path' => $path];
    }

    /**
     * Apply a panel domain end-to-end: persist it as the global domain, mirror
     * it into config.local.php as SITE_DOMAIN, regenerate the local cloudflared
     * config with the domain as public hostname, and restart the tunnel when a
     * connector token is already saved.
     */
    public static function panelApplyDomain($domain) {
        $domain = trim((string)$domain);
        $out = ['success' => true];

        $db = db();
        $db->prepare("REPLACE INTO config (key_name, value) VALUES ('global_domain', ?)")->execute([$domain]);

        $local = panel_local_config();
        $local['SITE_DOMAIN'] = $domain;
        $out['site_domain'] = panel_write_local_config($local);

        $cfg = self::panelWriteConfig($domain);
        if (empty($cfg['success'])) {
            $out['success'] = false;
            $out['config_error'] = $cfg['error'] ?? 'Unknown config error';
        } else {
            $out['tunnel_config'] = $cfg['path'];
        }

        if (self::panelGetToken() !== '') {
            $out['restart'] = self::panelRestart();
        } else {
            $out['restart'] = ['success' => false, 'error' => 'No tunnel token saved yet — tunnel not restarted'];
        }
        return $out;
    }

    public static function panelIsRunning() {
        $pidFile = self::panelPidFile();
        if (is_file($pidFile)) {
            $pid = (int)trim((string)file_get_contents($pidFile));
            if ($pid > 0) {
                $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
                if ($cmdline !== false && strpos($cmdline, 'cloudflared') !== false) return true;
                @unlink($pidFile);
            }
        }
        return false;
    }

    public static function panelStop() {
        $pidFile = self::panelPidFile();
        if (is_file($pidFile)) {
            $pid = (int)trim((string)file_get_contents($pidFile));
            if ($pid > 0 && function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
                usleep(500000);
                $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
                if ($cmdline !== false && strpos($cmdline, 'cloudflared') !== false) {
                    posix_kill($pid, SIGKILL);
                    usleep(200000);
                }
            }
            @unlink($pidFile);
        }
        if (function_exists('shell_exec')) {
            @shell_exec("pkill -f 'cloudflared.*--token' 2>/dev/null");
        }
        return ['success' => true];
    }

    public static function panelRestart($token = null) {
        if ($token === null) {
            $token = self::panelGetToken();
        }
        $token = trim((string)$token);
        if ($token === '') return ['success' => false, 'error' => 'No panel tunnel token saved yet'];
        $v = self::validateToken($token);
        if (!$v['valid']) return $v;
        if (!function_exists('shell_exec')) return ['success' => false, 'error' => 'shell_exec is disabled'];

        self::panelStop();
        usleep(400000);

        $log = self::panelLogFile();
        $cmd = 'setsid nohup cloudflared tunnel --no-autoupdate --loglevel info --logfile '
            . escapeshellarg($log) . ' run --token ' . escapeshellarg($token)
            . ' < /dev/null > /dev/null 2>&1 & echo $!';
        $pid = trim((string)shell_exec($cmd));

        if ($pid === '' || !ctype_digit($pid)) {
            return ['success' => false, 'error' => 'Failed to spawn cloudflared'];
        }
        file_put_contents(self::panelPidFile(), $pid);

        sleep(3);
        if (!self::panelIsRunning()) {
            $detail = '';
            if (is_file($log)) {
                $content = file_get_contents($log);
                if (preg_match('/(err|error)[^\n]*/i', $content, $m)) {
                    $detail = ' — ' . $m[0];
                }
            }
            return ['success' => false, 'error' => 'cloudflared exited immediately' . $detail];
        }
        return ['success' => true, 'pid' => (int)$pid];
    }

    public static function panelLog($lines = 80) {
        $log = self::panelLogFile();
        if (!is_file($log)) return '';
        $all = array_filter(explode("\n", (string)file_get_contents($log)));
        return implode("\n", array_slice($all, -$lines));
    }

    public static function panelRemoveToken() {
        self::panelStop();
        $dir = self::panelDir();
        foreach (['connector.token', 'cloudflared.pid', 'cloudflared.log'] as $f) {
            @unlink($dir . '/' . $f);
        }
        return ['success' => true];
    }
}
