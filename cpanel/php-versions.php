<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('php_selector');
init_db();
$db = db();

$user_id = $_SESSION['user_id'];

$versions = [
    '8.5' => ['label' => 'PHP 8.5', 'status' => 'active', 'badge' => 'Latest'],
    '8.4' => ['label' => 'PHP 8.4', 'status' => 'available', 'badge' => 'Stable'],
    '8.3' => ['label' => 'PHP 8.3', 'status' => 'available', 'badge' => 'Stable'],
    '8.2' => ['label' => 'PHP 8.2', 'status' => 'available', 'badge' => 'Supported'],
    '8.1' => ['label' => 'PHP 8.1', 'status' => 'available', 'badge' => 'Supported'],
];

$stmt = $db->prepare("SELECT php_version FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$current = $stmt->fetchColumn() ?: '8.5';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_version = $_POST['php_version'] ?? '';
    if (isset($versions[$new_version])) {
        $db->prepare("UPDATE users SET php_version = ? WHERE id = ?")->execute([$new_version, $user_id]);
        flash('success', "PHP version changed to {$versions[$new_version]['label']}");
    }
    redirect('/cpanel/php-versions.php');
}

$running = phpversion();

$nav = 'phpversions';
$page_title = 'PHP Version Selector';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="code-2" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">PHP Version Selector</div>
        <div class="hero-desc">Choose which PHP version runs your scripts. Changes apply instantly across all sites in your account.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="check-circle" class="lucide"></i> PHP <?= h($current) ?> active</span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="code-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= count($versions) ?></div>
            <div class="stat-label">Available Versions</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">PHP 8.1 through 8.5</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="check-circle-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number">PHP <?= h($current) ?></div>
            <div class="stat-label">Your Selection</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">applies across all sites</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="server" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number">PHP <?= h($running) ?></div>
            <div class="stat-label">Server Runtime</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">binary installed on host</div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header">
        <h3><i data-lucide="code-2" class="lucide"></i> Choose PHP Version</h3>
        <span style="font-size:12px;color:var(--text3)">Current: <strong style="color:var(--text)">PHP <?= h($current) ?></strong></span>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="pv-tiles">
                <?php foreach ($versions as $ver => $info):
                    $is_current = ($ver === $current);
                    if ($is_current) { $pill_cls = 'badge-active'; $pill_txt = 'Active'; }
                    elseif ($info['badge'] === 'Latest') { $pill_cls = 'badge-blue'; $pill_txt = 'Latest'; }
                    elseif ($info['badge'] === 'Stable') { $pill_cls = 'badge-purple'; $pill_txt = 'Stable'; }
                    else { $pill_cls = 'badge-pending'; $pill_txt = 'Supported'; }
                ?>
                <label class="pv-tile">
                    <input type="radio" name="php_version" value="<?= h($ver) ?>" <?= $is_current ? 'checked' : '' ?>>
                    <span class="pv-card">
                        <span class="pv-top">
                            <span class="pv-check"><i data-lucide="check" class="lucide"></i></span>
                            <span class="pv-ver"><?= h($info['label']) ?></span>
                            <span class="badge <?= $pill_cls ?>" style="font-size:10px;padding:3px 8px"><?= h($pill_txt) ?></span>
                        </span>
                        <span class="pv-desc">
                            <?php if ($ver === '8.5'): ?>
                                JIT improvements, property hooks, asymmetric visibility
                            <?php elseif ($ver === '8.4'): ?>
                                Property hooks, new array functions, HTML5 parser
                            <?php elseif ($ver === '8.3'): ?>
                                Typed class constants, json_validate(), readonly amendments
                            <?php elseif ($ver === '8.2'): ?>
                                Disjunctive Normal Form, DNF types, readonly classes
                            <?php elseif ($ver === '8.1'): ?>
                                Enums, fibers, readonly properties, intersection types
                            <?php endif; ?>
                        </span>
                        <span class="pv-foot"><?= $is_current ? 'Currently active &mdash; changes apply instantly' : 'Select this version and press Save' ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;align-items:center;gap:12px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap">
                <span style="font-size:12px;color:var(--text4)">Your selection is stored on your account and used for every PHP script.</span>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-body">
        <div class="tip-card tip-blue" style="margin-bottom:0">
            <i data-lucide="info" class="lucide"></i>
            <div class="tip-body">
                PHP version changes apply to all PHP scripts hosted under your account. The currently installed version on this server is <code class="chip-mono">PHP <?= phpversion() ?></code>.<br>
                To use a different version, install it via: <code class="chip-mono">pkg install php<?= str_replace('.', '', $current) ?></code>
            </div>
        </div>
    </div>
</div>

<style>
.pv-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,215px),1fr));gap:12px}
.pv-tile{position:relative;cursor:pointer}
.pv-tile input{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:1}
.pv-card{display:flex;flex-direction:column;gap:8px;height:100%;padding:14px;background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius);transition:border-color .15s,box-shadow .15s,transform .15s;box-sizing:border-box}
.pv-tile:hover .pv-card{border-color:var(--text4);transform:translateY(-1px)}
.pv-tile input:checked + .pv-card{border-color:#7C3AED;background:rgba(124,58,237,.06);box-shadow:0 0 0 2px rgba(124,58,237,.14)}
.pv-top{display:flex;align-items:center;gap:8px}
.pv-check{width:18px;height:18px;border-radius:50%;border:2px solid var(--border);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff;transition:background .15s,border-color .15s}
.pv-check .lucide{width:11px;height:11px;opacity:0;transition:opacity .15s}
.pv-tile input:checked + .pv-card .pv-check{background:#7C3AED;border-color:#7C3AED}
.pv-tile input:checked + .pv-card .pv-check .lucide{opacity:1}
.pv-ver{font-weight:700;font-size:14px;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace}
.pv-ver{flex:1}
.pv-desc{font-size:11px;color:var(--text4);line-height:1.5;min-height:48px}
.pv-foot{font-size:10.5px;color:var(--text4);padding-top:8px;border-top:1px dashed var(--border)}
@media(max-width:640px){
  .pv-tiles{grid-template-columns:1fr}
}
</style>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>