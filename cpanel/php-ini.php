<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('php_ini_editor');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT username, home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$home_dir = $user['home_dir'] ?? getenv('HOME');
$user_ini_path = rtrim($home_dir, '/') . '/.user.ini';

$settings_config = [
    'max_execution_time'   => ['label' => 'Max Execution Time',       'default' => '30',      'type' => 'seconds'],
    'max_input_time'       => ['label' => 'Max Input Time',           'default' => '60',      'type' => 'seconds'],
    'display_errors'       => ['label' => 'Display Errors',           'default' => 'Off',     'type' => 'toggle'],
    'error_reporting'      => ['label' => 'Error Reporting',          'default' => 'E_ALL & ~E_DEPRECATED & ~E_STRICT', 'type' => 'text'],
    'date.timezone'        => ['label' => 'Date Timezone',            'default' => 'UTC',     'type' => 'timezone'],
    'session.gc_maxlifetime' => ['label' => 'Session Max Lifetime',   'default' => '1440',    'type' => 'seconds'],
    'opcache.enable'       => ['label' => 'OPcache Enable',           'default' => '1',       'type' => 'toggle'],
    'allow_url_fopen'      => ['label' => 'Allow URL fopen',          'default' => '1',       'type' => 'toggle'],
    'allow_url_include'    => ['label' => 'Allow URL Include',        'default' => '0',       'type' => 'toggle'],
];

$existing_settings = [];
if (file_exists($user_ini_path)) {
    $ini_content = file_get_contents($user_ini_path);
    $lines = preg_split('/\r?\n/', $ini_content);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === ';' || $line[0] === '[') continue;
        if (preg_match('/^(\S+)\s*=\s*(.*)$/', $line, $m)) {
            $existing_settings[$m[1]] = trim($m[2]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $raw = $_POST['php_settings'] ?? [];
        $new_settings = [];
        foreach ($raw as $key => $value) {
            if (str_ends_with($key, '_val')) {
                $base = substr($key, 0, -4);
                $unit = $raw[$base . '_unit'] ?? 'M';
                $new_settings[$base] = $value . $unit;
            } elseif (!str_ends_with($key, '_unit')) {
                $new_settings[$key] = $value;
            }
        }
        $lines = ['; ZRPanel PHP User Settings', '; Do not edit manually - use the PHP INI Editor', ''];
        foreach ($new_settings as $key => $value) {
            if (!array_key_exists($key, $settings_config)) continue;
            $config = $settings_config[$key];
            $value = trim($value);
            if ($value === '' || $value === $config['default']) continue;
            $lines[] = "{$key} = {$value}";
        }
        $dir = dirname($user_ini_path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($user_ini_path, implode("\n", $lines) . "\n");
        flash('success', 'PHP settings saved to .user.ini');
    } elseif ($action === 'restore') {
        if (file_exists($user_ini_path)) @unlink($user_ini_path);
        flash('success', 'PHP settings restored to defaults (.user.ini removed)');
    }
    redirect('/cpanel/php-ini.php');
}

$current_values = [];
foreach ($settings_config as $key => $config) {
    $current_values[$key] = $existing_settings[$key] ?? ini_get($key) ?: $config['default'];
}

$custom_count = 0;
$toggles_on = 0;
foreach ($settings_config as $key => $config) {
    $cv = $current_values[$key];
    if ($cv != $config['default']) $custom_count++;
    if ($config['type'] === 'toggle' && ($cv == '1' || strtolower($cv) === 'on')) $toggles_on++;
}

$ini_sections = [
    ['title' => 'Execution Limits', 'icon' => 'clock', 'keys' => ['max_execution_time', 'max_input_time', 'session.gc_maxlifetime']],
    ['title' => 'Errors & Logging', 'icon' => 'alert-triangle', 'keys' => ['display_errors', 'error_reporting']],
    ['title' => 'Performance', 'icon' => 'zap', 'keys' => ['opcache.enable']],
    ['title' => 'Network & Security', 'icon' => 'shield-check', 'keys' => ['allow_url_fopen', 'allow_url_include']],
    ['title' => 'Date & Time', 'icon' => 'globe', 'keys' => ['date.timezone']],
];

$nav = 'phpini';
$page_title = 'PHP INI Editor';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="file-code" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">PHP INI Editor</div>
        <div class="hero-desc">Tune runtime directives for your account. Changes take effect after PHP processes restart, and some settings may be restricted by the server administrator.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-purple" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="file-text" class="lucide"></i> .user.ini</span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="file-code" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($settings_config) ?></div>
            <div class="stat-label">Managed Directives</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">editable per account</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $toggles_on ?></div>
            <div class="stat-label">Toggles Enabled</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">opcache, errors &amp; url access</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="pencil" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $custom_count ?></div>
            <div class="stat-label">Custom Values</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">differ from server defaults</div>
        </div>
    </div>
</div>

<div class="tip-card tip-blue fade-in-delay-1">
    <i data-lucide="info" class="lucide"></i>
    <div class="tip-body">
        Settings are stored in <code class="chip-mono"><?= h($user_ini_path) ?></code> in your home directory.
        Saving writes only the values that differ from PHP defaults &mdash; empty or default values are omitted from the file.
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="save">

    <?php foreach ($ini_sections as $section): ?>
    <div class="card fade-in-delay-1" style="margin-top:16px">
        <div class="card-header"><h3><i data-lucide="<?= h($section['icon']) ?>" class="lucide"></i> <?= h($section['title']) ?></h3></div>
        <div class="card-body" style="padding-top:6px">
            <?php foreach ($section['keys'] as $key):
                $config = $settings_config[$key];
                $current = $current_values[$key];
                $is_default = ($current == $config['default']);
            ?>
            <div class="pi-row <?= $is_default ? '' : 'is-custom' ?>">
                <div class="pi-info">
                    <div class="pi-label"><?= h($config['label']) ?></div>
                    <div class="pi-key"><?= h($key) ?> <span class="pi-def">&middot; Default: <?= h($config['default']) ?></span></div>
                    <div class="pi-current">
                        <?php if ($config['type'] === 'toggle'): ?>
                            <span class="badge <?= ($current == '1' || strtolower($current) === 'on') ? 'badge-active' : 'badge-suspended' ?>" style="font-size:10px;padding:3px 8px">
                                <?= ($current == '1' || strtolower($current) === 'on') ? 'Enabled' : 'Disabled' ?>
                            </span>
                        <?php else: ?>
                            <code class="chip-mono"><?= h($current) ?></code>
                        <?php endif; ?>
                        <?php if (!$is_default): ?>
                            <span class="badge badge-pending" style="font-size:10px;padding:3px 8px">Custom</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="pi-field">
                    <?php if ($config['type'] === 'toggle'): ?>
                        <label class="pi-toggle">
                            <input type="hidden" name="php_settings[<?= h($key) ?>]" value="0">
                            <input type="checkbox" name="php_settings[<?= h($key) ?>]" value="1" <?= $current == '1' || strtolower($current) === 'on' ? 'checked' : '' ?>>
                            <span class="pi-toggle-track"><span class="pi-toggle-knob"></span></span>
                            <span class="pi-toggle-label"><?= $current == '1' || strtolower($current) === 'on' ? 'Enabled' : 'Disabled' ?></span>
                        </label>
                    <?php elseif ($config['type'] === 'timezone'): ?>
                        <div class="pi-select-wrap">
                            <i data-lucide="globe" class="lucide pi-select-icon"></i>
                            <select name="php_settings[<?= h($key) ?>]" class="pi-select">
                                <?php
                                $zones = ['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Europe/London','Europe/Paris','Europe/Berlin','Asia/Tokyo','Asia/Shanghai','Asia/Kolkata','Australia/Sydney','Pacific/Auckland'];
                                foreach ($zones as $tz):
                                ?>
                                    <option value="<?= h($tz) ?>" <?= $current === $tz ? 'selected' : '' ?>><?= h($tz) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($config['type'] === 'seconds'): ?>
                        <div class="pi-input-group">
                            <input type="number" name="php_settings[<?= h($key) ?>]" value="<?= h($current) ?>" min="0" class="pi-input">
                            <span class="pi-input-suffix">sec</span>
                        </div>
                    <?php elseif ($config['type'] === 'size'): ?>
                        <?php
                        $size_val = preg_replace('/[^0-9]/', '', $current);
                        $size_unit = preg_replace('/[0-9]/', '', $current) ?: 'M';
                        ?>
                        <div class="pi-input-group">
                            <input type="number" name="php_settings[<?= h($key) ?>_val]" value="<?= h($size_val) ?>" min="0" class="pi-input" style="width:80px">
                            <select name="php_settings[<?= h($key) ?>_unit]" class="pi-input" style="width:70px;border-radius:0;border-left:1.5px solid var(--border)">
                                <option value="K" <?= $size_unit === 'K' ? 'selected' : '' ?>>KB</option>
                                <option value="M" <?= $size_unit === 'M' ? 'selected' : '' ?>>MB</option>
                                <option value="G" <?= $size_unit === 'G' ? 'selected' : '' ?>>GB</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="text" name="php_settings[<?= h($key) ?>]" value="<?= h($current) ?>" class="pi-input">
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="card fade-in-delay-2" style="margin-top:16px">
        <div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div style="flex:1;font-size:12px;color:var(--text4);min-width:200px">
                <i data-lucide="info" class="lucide" style="width:14px;height:14px;vertical-align:-2px"></i>
                Default values are omitted on save &mdash; PHP falls back to server defaults for anything not written.
            </div>
            <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save All Settings</button>
            <button type="button" class="btn btn-danger" onclick="if(confirm('Restore all PHP settings to defaults? This will remove your .user.ini file.')){var f=document.createElement('form');f.method='POST';f.innerHTML='<input name=action value=restore>';document.body.appendChild(f);f.submit()}">
                <i data-lucide="rotate-ccw" class="lucide"></i> Restore Defaults
            </button>
        </div>
    </div>
</form>

<style>
.pi-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:14px 0;border-bottom:1px solid var(--border2)}
.pi-row:last-child{border-bottom:none}
.pi-row.is-custom{padding-left:12px;padding-right:12px;margin-left:-12px;margin-right:-12px;background:linear-gradient(90deg,rgba(124,58,237,.05),transparent 55%);border-radius:var(--radius-sm)}
.pi-info{min-width:0;flex:1}
.pi-label{font-size:13px;font-weight:600;color:var(--text)}
.pi-key{font-size:11px;font-family:'Fira Code',monaco,consolas,monospace;color:var(--text4);margin-top:3px}
.pi-key .pi-def{opacity:.7}
.pi-current{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:6px}
.pi-current .chip-mono{font-size:11px}
.pi-field{flex-shrink:0;min-width:190px;max-width:250px}
.pi-field .pi-toggle{width:100%;justify-content:flex-end}
.pi-input{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--radius-xs);font-size:13px;font-family:'Fira Code',monaco,consolas,monospace;background:var(--bg);color:var(--text);outline:none;transition:border-color .15s;box-sizing:border-box}
.pi-input:focus{border-color:#7C3AED}
.pi-input-group{display:flex;align-items:stretch}
.pi-input-group > .pi-input{border-radius:var(--radius-xs) 0 0 var(--radius-xs);border-right:none}
.pi-input-suffix{display:flex;align-items:center;padding:0 12px;background:var(--bg4);border:1.5px solid var(--border);border-left:none;border-radius:0 var(--radius-xs) var(--radius-xs) 0;font-size:11px;color:var(--text4);font-weight:600}
.pi-select-wrap{position:relative}
.pi-select-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--text4);pointer-events:none}
.pi-select{width:100%;padding:9px 12px 9px 32px;border:1.5px solid var(--border);border-radius:var(--radius-xs);font-size:13px;background:var(--bg);color:var(--text);outline:none;cursor:pointer;-webkit-appearance:none;appearance:none}
.pi-select:focus{border-color:#7C3AED}
.pi-toggle{display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.pi-toggle input{display:none}
.pi-toggle-track{width:40px;height:22px;border-radius:99px;background:var(--bg4);border:1.5px solid var(--border);position:relative;transition:background .2s,border-color .2s;flex-shrink:0}
.pi-toggle input:checked + .pi-toggle-track{background:#7C3AED;border-color:#7C3AED}
.pi-toggle-knob{width:16px;height:16px;border-radius:50%;background:#fff;position:absolute;top:2px;left:2px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.pi-toggle input:checked + .pi-toggle-track .pi-toggle-knob{transform:translateX(18px)}
.pi-toggle-label{font-size:12px;color:var(--text3);min-width:60px}
@media(max-width:640px){
  .pi-row{flex-direction:column;align-items:stretch;gap:10px}
  .pi-field{max-width:none;min-width:0}
  .pi-field .pi-toggle{justify-content:flex-start}
}
</style>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>