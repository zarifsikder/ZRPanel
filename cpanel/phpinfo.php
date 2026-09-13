<?php
require_once __DIR__ . '/../config.php';
require_login();

$nav = 'phpinfo';
$page_title = 'PHP Information';
require_once __DIR__ . '/../templates/header.php';
?>
<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="terminal" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">PHP Information</div>
        <div class="hero-desc">Detailed PHP configuration and server environment information for your account.</div>
    </div>
    <div class="hero-actions">
        <span class="badge badge-blue" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="info" class="lucide"></i> PHP <?= PHP_VERSION ?></span>
    </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="code-2" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" style="font-size:clamp(16px,3vw,22px)"><?= PHP_VERSION ?></div>
            <div class="stat-label">PHP Version</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= PHP_OS ?></div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon"><i data-lucide="server" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number" style="font-size:clamp(14px,2.4vw,19px)"><?= php_sapi_name() ?></div>
            <div class="stat-label">Server API</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">script handler in use</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon"><i data-lucide="gauge" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= ini_get('memory_limit') ?></div>
            <div class="stat-label">Memory Limit</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">per-request budget</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon"><i data-lucide="timer" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= ini_get('max_execution_time') ?>s</div>
            <div class="stat-label">Max Execution Time</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= ini_get('max_input_time') ?>s input time</div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header">
        <h3><i data-lucide="info" class="lucide"></i> phpinfo() <span style="font-size:11px;font-weight:400;color:var(--text4)">generated at runtime</span></h3>
        <button type="button" class="btn btn-sm btn-ghost" id="pinfoToggle"><i data-lucide="eye-off" class="lucide"></i> Hide</button>
    </div>
    <div class="pinfo-body" id="pinfoContent">
        <?php
        ob_start();
        phpinfo();
        $info = ob_get_clean();
        preg_match('/<body>(.*)<\/body>/s', $info, $m);
        echo $m[1] ?? $info;
        ?>
    </div>
</div>

<style>
.pinfo-body{
    overflow-x:auto;overflow-y:auto;
    max-height:clamp(320px,60vh,640px);
    background:var(--bg);color:var(--text);
    font-size:12px;line-height:1.5;
}
.pinfo-body table{
    width:100%;border-collapse:collapse;
}
.pinfo-body td,.pinfo-body th{
    padding:6px 12px;border:1px solid var(--border);
    font-size:12px;text-align:left;
}
.pinfo-body th{
    background:var(--bg3);font-weight:700;color:var(--text2);
}
.pinfo-body td{color:var(--text3);font-family:'Fira Code',monaco,consolas,monospace;word-break:break-all}
.pinfo-body .h{background:var(--bg3);font-weight:700;color:var(--text)}
.pinfo-body .v{color:var(--text2)}
.pinfo-body hr{display:none}
.pinfo-body a{color:var(--primary)}
</style>

<script>
(function () {
    var btn = document.getElementById('pinfoToggle');
    var body = document.getElementById('pinfoContent');
    if (btn && body) {
        btn.addEventListener('click', function () {
            var hidden = body.style.display === 'none';
            body.style.display = hidden ? 'block' : 'none';
            btn.innerHTML = hidden ? '<i data-lucide="eye-off" class="lucide"></i> Hide' : '<i data-lucide="eye" class="lucide"></i> Show';
            if (window.lucide) { try { window.lucide.createIcons(); } catch (e) {} }
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>