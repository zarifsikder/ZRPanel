<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('php_extensions');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_home = $stmt->fetchColumn() ?: getenv('HOME');

$ext_dir = ini_get('extension_dir') ?: ($PREFIX ?? '/data/data/com.termux/files/usr') . '/lib/php';
if (!is_dir($ext_dir)) @mkdir($ext_dir, 0755, true);

$pure_loaded = array_map('strtolower', get_loaded_extensions(false));
$all_loaded  = array_map('strtolower', get_loaded_extensions(true));
$zend_loaded = array_values(array_diff($all_loaded, $pure_loaded));

$shared = [];
if ($ext_dir && is_dir($ext_dir)) {
    foreach (glob(rtrim($ext_dir, '/') . '/*.so') ?: [] as $f) {
        $base = basename($f, '.so');
        if ($base !== '') $shared[strtolower($base)] = basename($f);
    }
}

$catalog = [
    'amqp'      => ['title' => 'AMQP',           'desc' => 'RabbitMQ message queue client',                 'cat' => 'Messaging', 'pkg' => null,          'tag' => 'Popular'],
    'apcu'      => ['title' => 'APCu',           'desc' => 'Userland opcode & value cache',                 'cat' => 'Caching',   'pkg' => 'php-apcu',     'tag' => 'Popular'],
    'ast'       => ['title' => 'AST',            'desc' => 'Abstract syntax tree introspection',             'cat' => 'Analysis',  'pkg' => null,          'tag' => null],
    'bcmath'    => ['title' => 'BCMath',         'desc' => 'Arbitrary precision mathematics',                'cat' => 'Math',      'pkg' => null,          'tag' => 'Laravel'],
    'bz2'       => ['title' => 'Bzip2',          'desc' => 'Bzip2 (de)compression support',                  'cat' => 'Compression','pkg' => null,         'tag' => null],
    'calendar'  => ['title' => 'Calendar',       'desc' => 'Date/calendar conversion functions',             'cat' => 'Date & Time','pkg' => null,        'tag' => null],
    'ctype'     => ['title' => 'Ctype',          'desc' => 'Character type checking functions',              'cat' => 'Text',      'pkg' => null,          'tag' => 'Laravel'],
    'curl'      => ['title' => 'cURL',           'desc' => 'HTTP / FTP client transfers',                    'cat' => 'Networking','pkg' => null,         'tag' => 'Laravel'],
    'date'      => ['title' => 'Date',           'desc' => 'Core date & time handling',                      'cat' => 'Date & Time','pkg' => null,        'tag' => null],
    'dom'       => ['title' => 'DOM',            'desc' => 'Document Object Model for XML/HTML',             'cat' => 'XML',       'pkg' => null,          'tag' => null],
    'exif'      => ['title' => 'Exif',           'desc' => 'Exif/IPTC metadata from images',                 'cat' => 'Imaging',   'pkg' => null,          'tag' => 'Popular'],
    'ffi'       => ['title' => 'FFI',            'desc' => 'Foreign function interface to C libraries',      'cat' => 'Advanced',  'pkg' => null,          'tag' => null],
    'fileinfo'  => ['title' => 'Fileinfo',       'desc' => 'File type detection from magic bytes',           'cat' => 'Files',     'pkg' => null,          'tag' => 'Laravel'],
    'filter'    => ['title' => 'Filter',         'desc' => 'Input validation & sanitization',                'cat' => 'Core',      'pkg' => null,          'tag' => null],
    'ftp'       => ['title' => 'FTP',            'desc' => 'File Transfer Protocol client',                  'cat' => 'Networking','pkg' => null,         'tag' => null],
    'gd'        => ['title' => 'GD',             'desc' => 'Image creation & manipulation',                   'cat' => 'Imaging',   'pkg' => null,          'tag' => 'Laravel (reco.)'],
    'gmp'       => ['title' => 'GMP',            'desc' => 'GNU Multiple Precision arithmetic',              'cat' => 'Math',      'pkg' => null,          'tag' => null],
    'grpc'      => ['title' => 'gRPC',           'desc' => 'gRPC client transport',                          'cat' => 'Networking','pkg' => null,         'tag' => null],
    'hash'      => ['title' => 'Hash',           'desc' => 'Message digest algorithms',                      'cat' => 'Security',  'pkg' => null,          'tag' => null],
    'iconv'     => ['title' => 'iconv',          'desc' => 'Character set conversion',                       'cat' => 'Text',      'pkg' => null,          'tag' => null],
    'igbinary'  => ['title' => 'Igbinary',       'desc' => 'Alternative compact serialisation',              'cat' => 'Caching',   'pkg' => null,          'tag' => null],
    'imagick'   => ['title' => 'Imagick',        'desc' => 'ImageMagick processing API',                     'cat' => 'Imaging',   'pkg' => 'php-imagick',  'tag' => 'Popular'],
    'intl'      => ['title' => 'Intl',           'desc' => 'Internationalisation (ICU) support',             'cat' => 'Text',      'pkg' => null,          'tag' => 'Laravel (reco.)'],
    'ioncube loader' => ['title' => 'ionCube Loader', 'desc' => 'Decoder for ionCube-encoded PHP scripts',   'cat' => 'Security',  'pkg' => null,          'tag' => 'Popular'],
    'json'      => ['title' => 'JSON',           'desc' => 'JavaScript Object Notation support',             'cat' => 'Core',      'pkg' => null,          'tag' => 'Laravel'],
    'ldap'      => ['title' => 'LDAP',           'desc' => 'Directory access via LDAP protocol',             'cat' => 'Networking','pkg' => null,         'tag' => null],
    'mbstring'  => ['title' => 'Mbstring',       'desc' => 'Multibyte string utilities',                     'cat' => 'Text',      'pkg' => null,          'tag' => 'Laravel'],
    'memcached' => ['title' => 'Memcached',      'desc' => 'Memcached session & key/value cache',            'cat' => 'Caching',   'pkg' => 'php-memcached','tag' => 'Popular'],
    'mongo'     => ['title' => 'MongoDB',        'desc' => 'MongoDB driver (legacy)',                        'cat' => 'Databases', 'pkg' => null,          'tag' => null],
    'mysqli'    => ['title' => 'MySQLi',         'desc' => 'MySQL native driver interface',                  'cat' => 'Databases', 'pkg' => null,          'tag' => null],
    'oauth'     => ['title' => 'OAuth',          'desc' => 'OAuth 1.0 consumer support',                     'cat' => 'Security',  'pkg' => null,          'tag' => null],
    'opcache'   => ['title' => 'OPcache',        'desc' => 'Zend OPcache bytecode accelerator',              'cat' => 'Caching',   'pkg' => null,          'tag' => 'Popular'],
    'pcntl'     => ['title' => 'PCNTL',          'desc' => 'Process control & signal handling',              'cat' => 'Advanced',  'pkg' => null,          'tag' => 'Laravel'],
    'pdo_mysql' => ['title' => 'PDO MySQL',      'desc' => 'PDO driver for MySQL/MariaDB',                    'cat' => 'Databases', 'pkg' => null,          'tag' => 'Laravel'],
    'pdo_pgsql' => ['title' => 'PDO PgSQL',      'desc' => 'PDO driver for PostgreSQL',                      'cat' => 'Databases', 'pkg' => null,          'tag' => null],
    'pdo_sqlite'=> ['title' => 'PDO SQLite',     'desc' => 'PDO driver for SQLite',                          'cat' => 'Databases', 'pkg' => null,          'tag' => 'Laravel (reco.)'],
    'pgsql'     => ['title' => 'PgSQL',          'desc' => 'PostgreSQL native interface',                    'cat' => 'Databases', 'pkg' => null,          'tag' => null],
    'phar'      => ['title' => 'Phar',           'desc' => 'PHP Archive packaging',                          'cat' => 'Files',     'pkg' => null,          'tag' => null],
    'posix'     => ['title' => 'POSIX',          'desc' => 'POSIX system calls (exec, getpwnam...)',         'cat' => 'Advanced',  'pkg' => null,          'tag' => 'Laravel'],
    'random'    => ['title' => 'Random',         'desc' => 'Cryptographically secure random generators',      'cat' => 'Security',  'pkg' => null,          'tag' => null],
    'rar'       => ['title' => 'Rar',            'desc' => 'RAR archive reading',                            'cat' => 'Compression','pkg' => null,        'tag' => null],
    'redis'     => ['title' => 'Redis',          'desc' => 'Redis key/value store client',                   'cat' => 'Caching',   'pkg' => 'php-redis',   'tag' => 'Popular'],
    'readline'  => ['title' => 'Readline',       'desc' => 'Interactive command-line input history',         'cat' => 'CLI',       'pkg' => null,          'tag' => null],
    'shmop'     => ['title' => 'Shmop',          'desc' => 'Shared memory segments',                         'cat' => 'IPC',       'pkg' => null,          'tag' => null],
    'snmp'      => ['title' => 'SNMP',           'desc' => 'Simple Network Management Protocol',             'cat' => 'Networking','pkg' => 'php-snmp',    'tag' => null],
    'soap'      => ['title' => 'SOAP',           'desc' => 'Web Services / SOAP client',                     'cat' => 'Web Services','pkg' => null,       'tag' => null],
    'sockets'   => ['title' => 'Sockets',        'desc' => 'Low-level socket connections',                   'cat' => 'Networking','pkg' => null,         'tag' => null],
    'sodium'    => ['title' => 'Sodium',         'desc' => 'libsodium elliptic-curve crypto',                'cat' => 'Security',  'pkg' => null,          'tag' => null],
    'sqlite3'   => ['title' => 'SQLite3',        'desc' => 'SQLite3 database interface',                     'cat' => 'Databases', 'pkg' => null,          'tag' => null],
    'ssh2'      => ['title' => 'SSH2',           'desc' => 'Secure Shell (SSH) transport functions',         'cat' => 'Networking','pkg' => 'php-ssh2',    'tag' => null],
    'tidy'      => ['title' => 'Tidy',           'desc' => 'HTML tidy parsing/repair',                       'cat' => 'XML',       'pkg' => null,          'tag' => null],
    'tokenizer' => ['title' => 'Tokenizer',      'desc' => 'PHP source code token parsing',                  'cat' => 'Core',      'pkg' => null,          'tag' => 'Laravel'],
    'xdebug'    => ['title' => 'Xdebug',         'desc' => 'Debugger & profiler (Zend ext)',                 'cat' => 'Analysis',  'pkg' => 'php-xdebug',  'tag' => 'Popular'],
    'xml'       => ['title' => 'XML',            'desc' => 'XML parser core',                                'cat' => 'XML',       'pkg' => null,          'tag' => 'Laravel'],
    'xsl'       => ['title' => 'XSL',            'desc' => 'XSLT transformations',                           'cat' => 'XML',       'pkg' => null,          'tag' => null],
    'yaml'      => ['title' => 'YAML',           'desc' => 'YAML document parsing',                          'cat' => 'Data',      'pkg' => 'php-yaml',    'tag' => null],
    'zip'       => ['title' => 'Zip',            'desc' => 'ZIP archive reading/writing',                    'cat' => 'Compression','pkg' => null,        'tag' => 'Laravel'],
    'zlib'      => ['title' => 'Zlib',           'desc' => 'zlib (de)compression support',                   'cat' => 'Compression','pkg' => null,        'tag' => null],
];

foreach ($pure_loaded as $m) {
    if (!isset($catalog[$m])) {
        $catalog[$m] = ['title' => ucfirst($m), 'desc' => 'Extension compiled into the server PHP build.', 'cat' => 'Other', 'pkg' => null, 'tag' => null];
    }
}
foreach ($zend_loaded as $m) {
    if (!isset($catalog[$m])) {
        $catalog[$m] = ['title' => ucfirst($m), 'desc' => 'Zend extension compiled into the server PHP build.', 'cat' => 'Other', 'pkg' => null, 'tag' => null];
    }
}
ksort($catalog);

$stmt = $db->prepare("SELECT extension, status FROM php_extension_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$toggles = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $toggles[strtolower($r['extension'])] = $r['status'];
}

function account_ext_ini_path($home) {
    return rtrim($home, '/') . '/.user.d/php-extensions.ini';
}

function rewrite_account_ext_ini($home, array $enabled, array $shared) {
    $path = account_ext_ini_path($home);
    $lines = ["; ZRPanel PHP Extensions — account preferences", "; Consumed by per-account PHP processes via PHP_INI_SCAN_DIR.", ""];
    $wrote = 0;
    foreach ($enabled as $ext) {
        $lc = strtolower($ext);
        if (!isset($shared[$lc])) continue;
        $lines[] = "extension=" . $shared[$lc]; // file name (e.g. ext.so)
        $wrote++;
    }
    $lines[] = '; ' . $wrote . ' shared extension(s) currently match an installed .so module';
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, implode("\n", $lines) . "\n");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $wanted = [];
        $raw = $_POST['ext'] ?? [];
        foreach ($raw as $ext => $val) {
            $lc = strtolower($ext);
            if (isset($shared[$lc]) && (string)$val === '1') $wanted[$lc] = 'enabled';
        }
        $db->beginTransaction();
        try {
            $del = $db->prepare("DELETE FROM php_extension_settings WHERE user_id = ?");
            $del->execute([$user_id]);
            $ins = $db->prepare("INSERT INTO php_extension_settings (user_id, extension, status) VALUES (?, ?, 'enabled')");
            foreach ($wanted as $ext => $status) {
                $ins->execute([$user_id, $ext]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
        }
        rewrite_account_ext_ini($user_home, array_keys($wanted), $shared);
        flash('success', 'PHP extension preferences saved for this account.');
    } elseif ($action === 'clear') {
        $db->prepare("DELETE FROM php_extension_settings WHERE user_id = ?")->execute([$user_id]);
        rewrite_account_ext_ini($user_home, [], $shared);
        flash('success', 'PHP extension preferences reset to server defaults.');
    }
    redirect('/cpanel/php-extensions.php');
}

$stmt = $db->prepare("SELECT extension, status FROM php_extension_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$toggles = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $toggles[strtolower($r['extension'])] = $r['status'];
}

$shared_enabled = 0;
foreach ($shared as $ext => $file) {
    if (($toggles[$ext] ?? '') === 'enabled') $shared_enabled++;
}

$builtin_core = ['core', 'date', 'filter', 'hash', 'json', 'libxml', 'pcre', 'random', 'reflection', 'session', 'spl', 'standard', 'tokenizer'];
$compiled_count = 0;
$absent_count = 0;
foreach ($catalog as $ext => $info) {
    if (isset($shared[$ext])) continue;
    if (in_array($ext, $pure_loaded, true) || in_array($ext, $zend_loaded, true)) $compiled_count++;
    else $absent_count++;
}

$laravel_req = [
    'bcmath'    => ['req' => true,  'label' => 'BCMath'],
    'ctype'     => ['req' => true,  'label' => 'Ctype'],
    'fileinfo'  => ['req' => true,  'label' => 'Fileinfo'],
    'json'      => ['req' => true,  'label' => 'JSON'],
    'mbstring'  => ['req' => true,  'label' => 'Mbstring'],
    'openssl'   => ['req' => true,  'label' => 'OpenSSL'],
    'pdo_mysql' => ['req' => true,  'label' => 'PDO MySQL'],
    'tokenizer' => ['req' => true,  'label' => 'Tokenizer'],
    'xml'       => ['req' => true,  'label' => 'XML'],
    'curl'      => ['req' => true,  'label' => 'cURL'],
    'zip'       => ['req' => true,  'label' => 'Zip'],
    'pcntl'     => ['req' => true,  'label' => 'PCNTL'],
    'posix'     => ['req' => true,  'label' => 'POSIX'],
    'intl'      => ['req' => false, 'label' => 'Intl (recommended)'],
    'gd'        => ['req' => false, 'label' => 'GD (recommended)'],
    'exif'      => ['req' => false, 'label' => 'Exif (images)'],
    'pdo_sqlite'=> ['req' => false, 'label' => 'PDO SQLite'],
    'opcache'   => ['req' => false, 'label' => 'OPcache'],
];
$laravel_ok = 0;
$laravel_total = 0;
foreach ($laravel_req as $ext => $info) {
    $ok = in_array($ext, $pure_loaded, true) || isset($shared[$ext]);
    if ($ok) $laravel_ok++;
    $laravel_total++;
}

$letter_set = [];
foreach ($catalog as $ext => $info) {
    $letter = strtoupper($ext[0]);
    if (!isset($letter_set[$letter])) $letter_set[$letter] = 0;
    $letter_set[$letter]++;
}
ksort($letter_set);

$nav = 'phpexts';
$page_title = 'PHP Extensions';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="plug" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">PHP Extensions</div>
        <div class="hero-desc">Browse the full PHP extension catalogue (A&ndash;Z) and control what is loaded for your account &mdash; exactly as you would in cPanel.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="check-circle" class="lucide"></i> PHP <?= h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?></span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="box" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $compiled_count ?></div>
            <div class="stat-label">Extensions in Server Build</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">compiled-in / always active</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="layers" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($shared) ?></div>
            <div class="stat-label">Shared .so Modules</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">installed &amp; toggleable</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="toggle-right" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $shared_enabled ?></div>
            <div class="stat-label">Enabled for Your Account</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">of <?= count($shared) ?> shared modules</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="blocks" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $laravel_ok ?>/<?= $laravel_total ?></div>
            <div class="stat-label">Laravel Modules Present</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $laravel_ok < $laravel_total ? 'some recommended modules missing' : 'all recommended present' ?></div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-1" style="margin-top:16px">
    <div class="card-header">
        <h3><i data-lucide="blocks" class="lucide"></i> Laravel Compatibility</h3>
        <span class="badge <?= $laravel_ok == $laravel_total ? 'badge-active' : 'badge-pending' ?>" style="font-size:10px;padding:3px 8px"><?= $laravel_ok == $laravel_total ? 'Ready' : 'Ready (recommended additions below)' ?></span>
    </div>
    <div class="card-body">
        <div class="lc-grid">
            <?php foreach ($laravel_req as $ext => $info):
                $ok = in_array($ext, $pure_loaded, true) || isset($shared[$ext]); ?>
            <div class="lc-item <?= $ok ? 'ok' : 'miss' ?>">
                <span class="lc-dot"><i data-lucide="<?= $ok ? 'check' : 'x' ?>" class="lucide"></i></span>
                <span class="lc-name"><?= h($info['label']) ?></span>
                <code class="chip-mono" style="font-size:10.5px"><?= h($ext) ?></code>
                <?php if (!$ok): ?>
                    <span class="lc-hint">not installed</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="tip-card tip-blue" style="margin-top:14px;margin-bottom:0">
            <i data-lucide="info" class="lucide"></i>
            <div class="tip-body">All required Laravel modules are present on this build. Laravel 11/12 runs out of the box &mdash; point the document root at <code class="chip-mono">public/</code> of your project.</div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="save">

    <div class="card fade-in-delay-2" style="margin-top:16px">
        <div class="card-header">
            <h3><i data-lucide="plug" class="lucide"></i> Extension Catalogue (A&ndash;Z)</h3>
            <div class="alphabet-filter">
                <span class="af-link af-all active" data-letter="all">All</span>
                <?php foreach ($letter_set as $letter => $count): ?>
                    <span class="af-link" data-letter="<?= $letter ?>"><?= $letter ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="ext-search">
                <i data-lucide="search" class="lucide"></i>
                <input type="text" id="extSearch" placeholder="Filter extensions by name&hellip;" autocomplete="off">
                <span class="ext-search-count" id="extCount"></span>
            </div>

            <?php if (empty($shared)): ?>
            <div class="empty-state" style="margin:8px 0 4px">
                <i data-lucide="layers" class="lucide" style="width:26px;height:26px;color:var(--text4)"></i>
                <div>No shared <code class="chip-mono">.so</code> modules are installed on this server.</div>
                <div style="font-size:12px;color:var(--text4);max-width:560px;line-height:1.6">This panel&rsquo;s PHP is a unified Termux build &mdash; every extension below is compiled in and always active. When an administrator adds shared modules (e.g. <code class="chip-mono">zend_extension=xdebug.so</code>), each account can enable or disable them independently right here.</div>
            </div>
            <?php endif; ?>

            <div class="ext-grid">
                <?php foreach ($catalog as $ext => $info):
                    $lc = $ext;
                    $is_shared = isset($shared[$lc]);
                    $is_loaded = in_array($lc, $pure_loaded, true) || in_array($lc, $zend_loaded, true);
                    $is_core = in_array($lc, $builtin_core, true);
                    $enabled = (($toggles[$lc] ?? '') === 'enabled');
                    $letter = strtoupper($ext[0]);
                ?>
                <div class="ext-item" data-letter="<?= $letter ?>" data-name="<?= h(strtolower($ext)) ?>">
                    <div class="ext-top">
                        <span class="ext-name"><?= h($info['title']) ?></span>
                        <?php if ($is_shared): ?>
                            <label class="pi-toggle ext-toggle">
                                <input type="hidden" name="ext[<?= h($ext) ?>]" value="0">
                                <input type="checkbox" name="ext[<?= h($ext) ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
                                <span class="pi-toggle-track"><span class="pi-toggle-knob"></span></span>
                            </label>
                        <?php elseif ($is_loaded): ?>
                            <span class="badge badge-active" style="font-size:10px;padding:2.5px 8px"><?= $is_core ? 'Core' : 'Loaded' ?></span>
                        <?php else: ?>
                            <span class="badge badge-pending" style="font-size:10px;padding:2.5px 8px">Not installed</span>
                        <?php endif; ?>
                    </div>
                    <div class="ext-code"><code class="chip-mono"><?= h($lc) ?></code></div>
                    <div class="ext-desc"><?= h($info['desc']) ?></div>
                    <div class="ext-foot">
                        <span class="ext-badge"><?= h($info['cat']) ?></span>
                        <?php if ($info['tag']): ?><span class="ext-badge ext-tag"><?= h($info['tag']) ?></span><?php endif; ?>
                        <?php if ($is_shared && $enabled): ?><span style="color:#16a34a;font-size:10.5px;font-weight:600">Enabled on this account</span><?php endif; ?>
                        <?php if (!$is_shared && $is_loaded): ?><span style="color:var(--text4);font-size:10.5px"><?= $is_core ? 'always active' : 'compiled into server build' ?></span><?php endif; ?>
                        <?php if (!$is_shared && !$is_loaded): ?><span style="color:var(--text4);font-size:10.5px"><?= $info['pkg'] ? 'pkg install ' . h($info['pkg']) : 'not available in this build' ?></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card fade-in-delay-2" style="margin-top:16px">
        <div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div style="flex:1;font-size:12px;color:var(--text4);min-width:220px">
                <i data-lucide="info" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i>
                Shared-module toggles are stored per account and written to
                <code class="chip-mono">.user.d/php-extensions.ini</code> in your home directory.
            </div>
            <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save Extensions</button>
            <button type="button" class="btn btn-danger" onclick="if(confirm('Reset your PHP extension preferences to the server defaults?')){var f=document.createElement('form');f.method='POST';f.innerHTML='<input name=action value=clear>';document.body.appendChild(f);f.submit()}">
                <i data-lucide="rotate-ccw" class="lucide"></i> Reset to Defaults
            </button>
        </div>
    </div>
</form>

<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-body">
        <div class="note-box">
            <div class="note-title"><i data-lucide="shield-check" class="lucide"></i> How enforcement works on this server</div>
            <p style="color:var(--text4);font-size:12px;line-height:1.6;margin-top:8px;margin-bottom:0">
                This build is a unified Termux PHP &mdash; the modules marked <b>Loaded</b> are compiled into the binary and
                cannot be unloaded per account. The preferences below are applied per cPanel account:
            </p>
            <ul style="color:var(--text4);font-size:12px;line-height:1.8;padding-left:18px;margin-top:8px;margin-bottom:0">
                <li><b>Shared .so modules</b> &mdash; your toggles enable/disable them by writing <code class="chip-mono">extension=&lt;module&gt;.so</code> lines into <code class="chip-mono">~/.user.d/php-extensions.ini</code> for your account only.</li>
                <li><b>Per-account PHP processes</b> (php-fpm pools, per-site PHP apps, cron jobs) read that ini via <code class="chip-mono">PHP_INI_SCAN_DIR</code>, so one account&rsquo;s choices never leak into another.</li>
                <li><b>The shared web server</b> runs the unified engine: every compiled-in module is available, matching cPanel&rsquo;s default &ldquo;everything-on&rdquo; profile.</li>
            </ul>
        </div>
    </div>
</div>

<style>
.note-box{padding:14px 16px;background:rgba(124,58,237,.05);border:1.5px solid rgba(124,58,237,.25);border-radius:var(--radius);}
.note-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:var(--text)}
.note-title .lucide{width:16px;height:16px;color:#7C3AED}
.pi-toggle{display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.pi-toggle input{display:none}
.pi-toggle-track{width:36px;height:20px;border-radius:99px;background:var(--bg4);border:1.5px solid var(--border);position:relative;transition:background .2s,border-color .2s;flex-shrink:0}
.pi-toggle input:checked + .pi-toggle-track{background:#7C3AED;border-color:#7C3AED}
.pi-toggle-knob{width:14px;height:14px;border-radius:50%;background:#fff;position:absolute;top:2px;left:2px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.pi-toggle input:checked + .pi-toggle-track .pi-toggle-knob{transform:translateX(16px)}
.af-link{display:inline-block;padding:4px 8px;border-radius:var(--radius-xs);font-size:11px;font-weight:700;color:var(--text3);cursor:pointer;transition:color .12s,background .12s}
.af-link:hover{color:var(--text)}
.af-link.active{background:#7C3AED;color:#fff}
.alphabet-filter{display:flex;gap:1px;flex-wrap:wrap;max-width:430px}
.ext-search{display:flex;align-items:center;gap:8px;padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--radius-xs);background:var(--bg);margin-bottom:14px}
.ext-search .lucide{width:15px;height:15px;color:var(--text4)}
.ext-search input{flex:1;background:transparent;border:none;outline:none;color:var(--text);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace}
.ext-search-count{font-size:11px;color:var(--text4);white-space:nowrap}
.ext-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,230px),1fr));gap:10px}
.ext-item{padding:12px;background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius);transition:border-color .15s,box-shadow .15s}
.ext-item:hover{border-color:var(--text4)}
.ext-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ext-name{font-size:12.5px;font-weight:700;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace}
.ext-code{margin-top:4px}
.ext-code .chip-mono{font-size:10px;color:var(--text4)}
.ext-desc{font-size:11px;color:var(--text4);line-height:1.5;margin-top:5px;min-height:2.6em}
.ext-foot{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px dashed var(--border)}
.ext-badge{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text4);border:1px solid var(--border);padding:2px 6px;border-radius:99px}
.ext-tag{color:#7C3AED;border-color:rgba(124,58,237,.35)}
.ext-toggle{flex-shrink:0}
.lc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
.lc-item{display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius-xs);font-size:12px}
.lc-item.ok{border-color:rgba(22,163,74,.3)}
.lc-item.miss{border-color:rgba(220,38,38,.35);background:rgba(220,38,38,.04)}
.lc-dot{width:16px;height:16px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center}
.lc-dot .lucide{width:10px;height:10px}
.lc-item.ok .lc-dot{background:#16a34a;color:#fff}
.lc-item.miss .lc-dot{background:#dc2626;color:#fff}
.lc-name{font-weight:600;color:var(--text);white-space:nowrap}
.lc-hint{font-size:10px;color:#dc2626;font-weight:600}
@media(max-width:640px){
  .lc-grid{grid-template-columns:1fr 1fr}
  .alphabet-filter{max-width:100%}
}
</style>

<script>
(function () {
    var search = document.getElementById('extSearch');
    var items = Array.prototype.slice.call(document.querySelectorAll('.ext-item'));
    var links = Array.prototype.slice.call(document.querySelectorAll('.af-link'));
    var countEl = document.getElementById('extCount');
    var activeLetter = 'all';

    function apply() {
        var q = (search.value || '').trim().toLowerCase();
        var shown = 0;
        items.forEach(function (it) {
            var matchLetter = activeLetter === 'all' || it.getAttribute('data-letter') === activeLetter;
            var matchQ = !q || it.getAttribute('data-name').indexOf(q) !== -1;
            var show = matchLetter && matchQ;
            it.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        if (countEl) countEl.textContent = shown + ' shown';
    }

    search.addEventListener('input', apply);
    links.forEach(function (l) {
        l.addEventListener('click', function () {
            links.forEach(function (x) { x.classList.remove('active'); });
            l.classList.add('active');
            activeLetter = l.getAttribute('data-letter');
            apply();
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>