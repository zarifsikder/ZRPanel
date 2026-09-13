<?php
// ============================================================
//  KODExplorer SSO — auto-provision + auto-login panel users
// ============================================================
// Runs from router.php for /file-manager BEFORE the KOD app boots.
// Panel user => KOD account of the same name, home pointed at the
// panel user's real home directory (homePath). A matching KOD user
// is provisioned on first visit and a KOD session is minted so the
// user never sees KOD's own login screen.

if (!defined('KOD_SSO_LOADED')) {
    define('KOD_SSO_LOADED', true);

    function kod_sso_session_name() { return 'KOD_SESSION_ID_' . substr(md5('/storage/emulated/0/Download/hosting/file-manager/'), 0, 5); }
    function kod_sso_data_dir()      { return '/data/data/com.termux/files/usr/var/lib/file-manager/'; }

    function kod_sso_members_file() { return kod_sso_data_dir() . 'system/system_member.php'; }
    function kod_sso_user_dir($path) { return kod_sso_data_dir() . 'User/' . $path . '/'; }
function kod_sso_session_dir() { return kod_sso_data_dir() . 'session/'; }

    function kod_sso_members() {
        $file = kod_sso_members_file();
        if (!is_file($file)) return [];
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return [];
        $raw = preg_replace('/^<\?php exit;\?>/', '', $raw);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    function kod_sso_save_members($members) {
        $file = kod_sso_members_file();
        $json = json_encode($members, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp  = $file . '.tmp';
        if (@file_put_contents($tmp, '<?php exit;?>' . $json) === false) return false;
        return @rename($tmp, $file);
    }

    /**
     * Ensure a KOD account named $name exists for the panel user, mapped to
     * their real home directory, and return its member array.
     */
    function kod_sso_provision($name, $homeDir, $sizeMB = 0) {
        $members = kod_sso_members();
        $entry   = null;
        foreach ($members as $id => $m) {
            if (isset($m['name']) && $m['name'] === $name) { $entry = $m; $entry['userID'] = $id; break; }
        }
        $homeDir = rtrim((string)$homeDir, '/');

        if ($entry === null) {
            $maxId = 1;
            foreach ($members as $id => $m) {
                if (is_numeric($id) && (int)$id >= $maxId) $maxId = (int)$id + 1;
            }
            $path  = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '_' . $maxId;
            $entry = [
                'userID'     => (string)$maxId,
                'name'       => $name,
                'nickName'   => $name,
                'password'   => md5(bin2hex(random_bytes(16))),
                'role'       => '2',
                'config'     => ['sizeMax' => (float)$sizeMB, 'sizeUse' => 1024],
                'groupInfo'  => ['1' => 'write'],
                'path'       => $path,
                'status'     => 1,
                'lastLogin'  => '',
                'createTime' => time(),
            ];
            if ($homeDir !== '' && is_dir($homeDir)) {
                $entry['homePath'] = $homeDir;
            }
            $members[$maxId] = $entry;
            if (!kod_sso_save_members($members)) return null;
            if (!is_dir(kod_sso_user_dir($path))) {
                @mkdir(kod_sso_user_dir($path), 0755, true);
                @mkdir(kod_sso_user_dir($path) . 'data', 0755, true);
                @file_put_contents(kod_sso_user_dir($path) . 'data/index.html', '');
            }
            return $entry;
        }

        // User exists: make sure homePath points at the panel home.
        $changed = false;
        $need    = ($homeDir !== '' && is_dir($homeDir)) ? $homeDir : null;
        if ($need !== null && ($entry['homePath'] ?? null) !== $need) {
            $entry['homePath'] = $need;
            $changed = true;
        }
        if ($changed) {
            $members[$entry['userID']] = $entry;
            kod_sso_save_members($members);
        }
        return $entry;
    }

    /**
     * True when the browser already holds a KOD session we minted for this user.
     */
    function kod_sso_has_session($userID) {
        $sid = $_COOKIE[kod_sso_session_name()] ?? '';
        if ($sid === '' || preg_match('/[^A-Za-z0-9-]/', $sid)) return false;
        $file = kod_sso_session_dir() . 'sess_' . $sid;
        if (!is_file($file)) return false;
        $raw = (string)@file_get_contents($file);
        if ($raw === '' || strpos($raw, 'kodLogin') === false) return false;
        if ($userID !== '' && strpos($raw, 'kodUser') !== false && strpos($raw, (string)$userID) === false) {
            return false;
        }
        return true;
    }

    /**
     * Mint a KOD session for $member and push the cookie at the browser.
     * Writes the PHP session file by hand so the panel's own session is never
     * touched (swapping session_* mid-request breaks the panel cookie).
     */
    function kod_sso_start_session_seriously($member) {
        $dir = kod_sso_session_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $sid = md5(uniqid('kod', true));
        $sess = 'kodLogin|b:1;'
              . 'kodUser|' . serialize($member) . ';'
              . 'X-CSRF-TOKEN|s:' . strlen($tok = bin2hex(random_bytes(10))) . ':"' . $tok . '";';
        $file = $dir . 'sess_' . $sid;
        if (@file_put_contents($file, $sess) === false) return;
        setcookie(kod_sso_session_name(), $sid, 0, '/');
        if (isset($member['userID'])) {
            setcookie('kodUserID', (string)$member['userID'], time() + 3600 * 24 * 100, '/');
        }
    }

    /**
     * Main door used by router.php.
     * Returns 'good' when the request may continue into the KOD app,
     * 'redirect' when a fresh KOD session was just minted and the browser
     * must bounce once more so it carries the KOD session cookie.
     */
    function kod_sso_panel_door() {
        require_once __DIR__ . '/config.php';
        init_db();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return 'good';
        $db = db();
        $stmt = $db->prepare("SELECT u.username, u.home_dir, p.disk_quota
                              FROM users u LEFT JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
        $stmt->execute([$uid]);
        $panel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$panel || empty($panel['username'])) return 'good';

        $sizeMB = (float)($panel['disk_quota'] ?? 0);
        if ($sizeMB <= 0) $sizeMB = 1024;
        $member  = kod_sso_provision($panel['username'], $panel['home_dir'], $sizeMB);
        if (!$member) return 'good';

        if (kod_sso_has_session($member['userID'])) return 'good';

        kod_sso_start_session_seriously($member);
        return 'redirect'; // cookie just set on the wire; bounce to attach it
    }
}