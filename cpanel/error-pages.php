<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT home_dir FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$home = $user['home_dir'] ?? '';

function rebuild_htaccess_error_pages($db, $user_id, $home) {
    $pages = $db->prepare("SELECT error_code, content FROM error_pages WHERE user_id = ?");
    $pages->execute([$user_id]);
    $all = $pages->fetchAll(PDO::FETCH_ASSOC);

    $htaccess = "\n# --- ZRPanel Custom Error Pages ---\n";
    foreach ($all as $p) {
        $code = (int)$p['error_code'];
        $file = "/{$code}.html";
        $htaccess .= "ErrorDocument {$code} {$file}\n";
    }
    $htaccess .= "# --- End ZRPanel Error Pages ---\n";

    $htaccess_file = $home . '/public_html/.htaccess';
    $existing = '';
    if (file_exists($htaccess_file)) {
        $existing = file_get_contents($htaccess_file);
    }

    if (preg_match('/# --- ZRPanel Custom Error Pages ---.*?# --- End ZRPanel Error Pages ---/s', $existing)) {
        $new = preg_replace('/# --- ZRPanel Custom Error Pages ---.*?# --- End ZRPanel Error Pages ---/s', trim($htaccess), $existing);
    } else {
        $new = rtrim($existing) . "\n" . $htaccess;
    }
    file_put_contents($htaccess_file, $new);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $code = (int)($_POST['error_code'] ?? 404);
        $content = $_POST['content'] ?? '';
        $exists = $db->prepare("SELECT id FROM error_pages WHERE user_id = ? AND error_code = ?");
        $exists->execute([$user_id, $code]);
        if ($exists->fetch()) {
            $db->prepare("UPDATE error_pages SET content = ? WHERE user_id = ? AND error_code = ?")->execute([$content, $user_id, $code]);
        } else {
            $db->prepare("INSERT INTO error_pages (user_id, error_code, content) VALUES (?, ?, ?)")->execute([$user_id, $code, $content]);
        }
        $home = $user['home_dir'] ?? '';
        if ($home) {
            @mkdir($home . '/public_html', 0755, true);
            file_put_contents($home . "/public_html/{$code}.html", $content);
            rebuild_htaccess_error_pages($db, $user_id, $home);
        }
        flash('success', "Error page {$code} saved and .htaccess updated");
    } elseif ($action === 'reset') {
        $code = (int)($_POST['error_code'] ?? 404);
        $db->prepare("DELETE FROM error_pages WHERE user_id = ? AND error_code = ?")->execute([$user_id, $code]);
        $home = $user['home_dir'] ?? '';
        if ($home) {
            @unlink($home . "/public_html/{$code}.html");
            rebuild_htaccess_error_pages($db, $user_id, $home);
        }
        flash('success', "Error page {$code} reset and .htaccess updated");
    } elseif ($action === 'reset_all') {
        $db->prepare("DELETE FROM error_pages WHERE user_id = ?")->execute([$user_id]);
        $home = $user['home_dir'] ?? '';
        if ($home) {
            $codes = [400,401,403,404,500,502,503];
            foreach ($codes as $code) @unlink($home . "/public_html/{$code}.html");
            rebuild_htaccess_error_pages($db, $user_id, $home);
        }
        flash('success', 'All error pages reset to defaults');
    }
    redirect('/cpanel/error-pages.php');
}

$pages = $db->prepare("SELECT * FROM error_pages WHERE user_id = ?");
$pages->execute([$user_id]);
$page_list = [];
foreach ($pages->fetchAll(PDO::FETCH_ASSOC) as $p) $page_list[$p['error_code']] = $p;

$error_codes = [
    400 => 'Bad Request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not Found',
    500 => 'Internal Server Error',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
];

$nav = 'errorpages';
$page_title = 'Custom Error Pages';
require_once __DIR__ . '/../templates/header.php';

$edit_code = (int)($_GET['code'] ?? 0);
$editing = $edit_code && isset($error_codes[$edit_code]);
$edit_content = $editing && isset($page_list[$edit_code]) ? $page_list[$edit_code]['content'] : '';
?>

<?php if ($editing): ?>
<div class="fade-in" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:12px">
        <a href="/cpanel/error-pages.php" class="btn btn-sm btn-ghost"><i data-lucide="arrow-left" class="lucide"></i> All Pages</a>
        <span class="ep-chip ep-code-num"><?= $edit_code ?></span>
        <div>
            <div style="font-weight:700;color:var(--text);font-size:14px"><?= h($error_codes[$edit_code]) ?></div>
            <div style="font-size:11px;color:var(--text4)">Served as <code>/<?= $edit_code ?>.html</code></div>
        </div>
    </div>
    <span class="ep-status <?= $edit_content ? 'is-custom' : 'is-default' ?>"><span class="dot"></span> <?= $edit_content ? 'Custom content' : 'Default' ?></span>
</div>

<div class="grid-2 fade-in">
    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-header"><h3><i data-lucide="code-2" class="lucide"></i> HTML Source</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;flex:1">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px">Start from</span>
                <button type="button" class="btn btn-sm btn-ghost" onclick="applyTemplate('minimal')"><i data-lucide="dot" class="lucide"></i> Minimal</button>
                <button type="button" class="btn btn-sm btn-ghost" onclick="applyTemplate('simple')"><i data-lucide="file-text" class="lucide"></i> Simple</button>
                <button type="button" class="btn btn-sm btn-ghost" onclick="applyTemplate('branded')"><i data-lucide="sparkles" class="lucide"></i> Branded</button>
            </div>
            <form id="epForm" method="POST" style="display:flex;flex-direction:column;gap:10px;flex:1">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="error_code" value="<?= $edit_code ?>">
                <textarea id="epEditor" name="content" class="code-editor" style="flex:1;min-height:330px" placeholder="<html>&#10;<head><title>Error</title></head>&#10;<body>&#10;  <h1>Oops!</h1>&#10;</body>&#10;</html>"><?= h($edit_content) ?></textarea>
                <div class="form-hint"><i data-lucide="info" class="lucide"></i> Written to <code>/public_html/<?= $edit_code ?>.html</code> &middot; <b>Ctrl/&#8984;+Enter</b> to save</div>
            </form>
        </div>
    </div>

    <div class="card" style="display:flex;flex-direction:column">
        <div class="card-header"><h3><i data-lucide="eye" class="lucide"></i> Live Preview</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;flex:1">
            <div class="ep-preview"><iframe id="epPreview" title="Preview"></iframe></div>
            <div class="ep-card-htaccess">
                <span class="ep-htaccess-label"><i data-lucide="file-code" class="lucide"></i> .htaccess directive</span>
                <code>ErrorDocument <?= $edit_code ?> /<?= $edit_code ?>.html</code>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:auto">
                <button type="submit" form="epForm" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save Error Page</button>
                <a href="/cpanel/error-pages.php" class="btn btn-ghost">Cancel</a>
                <?php if ($edit_content): ?>
                <form method="POST" style="margin:0 0 0 auto" onsubmit="return confirm('Reset error page <?= $edit_code ?> to default?')">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="error_code" value="<?= $edit_code ?>">
                    <button type="submit" class="btn btn-ghost"><i data-lucide="rotate-ccw" class="lucide"></i> Reset</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var PAGE_CODE = <?= (int)$edit_code ?>;
    var PAGE_DESC = <?= json_encode($error_codes[$edit_code]) ?>;
    var TEMPLATES = {
        minimal: [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>{{code}} Error</title>',
            '</head>',
            '<body>',
            '<h1>{{code}}</h1>',
            '<h2>{{desc}}</h2>',
            '<p>The requested resource is unavailable. Please try again later.</p>',
            '</body>',
            '</html>'
        ].join('\n'),
        simple: [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>{{code}} - {{desc}}</title>',
            '</head>',
            '<body style="font-family:system-ui,sans-serif;text-align:center;padding:80px 20px">',
            '<h1 style="font-size:56px;margin:0">{{code}}</h1>',
            '<h2 style="color:#6b7280">{{desc}}</h2>',
            '<p>Sorry - the page you requested could not be loaded using this link.</p>',
            '</body>',
            '</html>'
        ].join('\n'),
        branded: [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>{{code}} - {{desc}}</title>',
            '<style>',
            'body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}',
            '.card{text-align:center;max-width:420px;padding:24px}',
            '.card h1{font-size:80px;margin:0;color:#f59e0b;letter-spacing:-2px}',
            '.card h2{font-size:20px;margin:12px 0 8px}',
            '.card p{color:#94a3b8;font-size:14px;line-height:1.6}',
            '.card a{color:#38bdf8;text-decoration:none}',
            '</style>',
            '</head>',
            '<body>',
            '<div class="card">',
            '<h1>{{code}}</h1>',
            '<h2>{{desc}}</h2>',
            '<p>The page you are looking for could not be found or is temporarily unavailable.</p>',
            '<p><a href="/">Go back home</a></p>',
            '</div>',
            '</body>',
            '</html>'
        ].join('\n')
    };
    var editor = document.getElementById('epEditor');
    var preview = document.getElementById('epPreview');
    function updatePreview(){
        try { preview.srcdoc = (editor.value || '<!-- empty content -->'); } catch (e) {}
    }
    function applyTemplate(name){
        var t = (TEMPLATES[name] || '')
            .split('{{code}}').join(PAGE_CODE)
            .split('{{desc}}').join(PAGE_DESC);
        editor.value = t.trim();
        updatePreview();
    }
    window.applyTemplate = applyTemplate;
    editor.addEventListener('input', updatePreview);
    updatePreview();
    document.addEventListener('keydown', function(e){
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            var f = document.getElementById('epForm');
            if (f) { e.preventDefault(); f.submit(); }
        }
    });
})();
</script>
<?php else: ?>

<div class="page-hero fade-in">
    <div class="hero-icon orange"><i data-lucide="alert-triangle" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Custom Error Pages</div>
        <div class="hero-desc">Customize the pages visitors see when HTTP errors occur &mdash; changes are written to .htaccess automatically.</div>
    </div>
    <div class="hero-actions">
        <span class="badge <?= count($page_list) ? 'badge-pending' : '' ?>" style="<?= count($page_list) ? '' : 'background:var(--bg3);color:var(--text4);border:1px solid var(--border)' ?>"><i data-lucide="file-check" class="lucide"></i> <?= count($page_list) ?> custom</span>
    </div>
</div>

<div class="ep-grid fade-in-delay-1">
    <?php foreach ($error_codes as $code => $desc): ?>
        <?php $is_custom = isset($page_list[$code]); ?>
        <?php $severity = $code >= 500 ? 'ep-red' : 'ep-amber'; ?>
        <?php $ep_icon = [400 => 'file-x', 401 => 'shield-alert', 403 => 'ban', 404 => 'file-question', 500 => 'server-crash', 502 => 'plug', 503 => 'pause'][$code] ?? 'alert-triangle'; ?>
        <?php $html_file = $home . '/public_html/' . $code . '.html'; ?>
        <?php $html_size = $is_custom && is_file($html_file) ? filesize($html_file) : 0; ?>
        <div class="ep-card <?= $severity ?> <?= $is_custom ? 'ep-custom' : '' ?>">
            <div class="ep-card-top">
                <div style="display:flex;align-items:center;gap:12px">
                    <span class="ep-icon"><i data-lucide="<?= $ep_icon ?>" class="lucide"></i></span>
                    <div class="ep-card-code">
                        <span class="ep-code-num"><?= $code ?></span>
                        <span class="ep-code-desc"><?= h($desc) ?></span>
                    </div>
                </div>
            </div>
            <div class="ep-meta">
                <span class="ep-status <?= $is_custom ? 'is-custom' : 'is-default' ?>"><span class="dot"></span> <?= $is_custom ? 'Custom page' : 'Apache default' ?></span>
                <?php if ($is_custom): ?>
                <span class="ep-size"><i data-lucide="file-text" class="lucide"></i> <?= format_size($html_size) ?></span>
                <?php endif; ?>
            </div>
            <div class="ep-card-htaccess">
                <span class="ep-htaccess-label"><i data-lucide="file-code" class="lucide"></i> .htaccess directive</span>
                <code>ErrorDocument <?= $code ?> /<?= $code ?>.html</code>
            </div>
            <div class="ep-card-actions">
                <a href="?code=<?= $code ?>" class="btn btn-sm btn-info"><i data-lucide="edit-3" class="lucide"></i> Edit</a>
                <?php if ($is_custom): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Reset error page <?= $code ?> to default?')">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="error_code" value="<?= $code ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i data-lucide="rotate-ccw" class="lucide"></i> Reset</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($page_list)): ?>
<div style="margin-top:22px;display:flex;align-items:center;justify-content:flex-end" class="fade-in-delay-2">
    <form method="POST" onsubmit="return confirm('Reset ALL error pages to defaults? This cannot be undone.')">
        <input type="hidden" name="action" value="reset_all">
        <button type="submit" class="btn btn-danger"><i data-lucide="alert-triangle" class="lucide"></i> Reset All Custom Pages</button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.ep-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,280px),1fr));gap:14px}
.ep-card{
    position:relative;overflow:hidden;
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);
    padding:16px;display:flex;flex-direction:column;gap:12px;
    transition:border-color .2s,box-shadow .2s,transform .2s;
}
.ep-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--ep-acc),transparent);opacity:0;transition:opacity .2s ease}
.ep-card:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-3px)}
.ep-card:hover::before{opacity:1}
.ep-card.ep-amber{--ep-acc:#f59e0b}
.ep-card.ep-red{--ep-acc:#ef4444}
.ep-card.ep-custom{border-color:rgba(217,119,6,.4);box-shadow:0 0 0 1px rgba(217,119,6,.06)}
.ep-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.ep-icon{
    width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
    background:var(--bg3);border:1px solid var(--border);color:var(--text3);
}
.ep-card.ep-amber .ep-icon{background:var(--warning-light);color:var(--warning);border-color:var(--warning-border)}
.ep-card.ep-red .ep-icon{background:var(--danger-light);color:var(--danger);border-color:var(--danger-border)}
.ep-icon .lucide{width:18px;height:18px}
.ep-card-code{display:flex;flex-direction:column}
.ep-code-num{font-size:24px;font-weight:800;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;line-height:1.1;letter-spacing:-.5px}
.ep-card.ep-amber .ep-code-num{color:#d97706}
.ep-card.ep-red .ep-code-num{color:#dc2626}
.ep-code-desc{font-size:12px;color:var(--text3);margin-top:2px}
.ep-meta{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ep-status{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:5px 9px;border-radius:999px}
.ep-status .dot{width:7px;height:7px;border-radius:50%}
.ep-status.is-custom{background:var(--warning-light);color:var(--warning);border:1px solid var(--warning-border)}
.ep-status.is-custom .dot{background:#f59e0b;box-shadow:0 0 6px rgba(245,158,11,.45)}
.ep-status.is-default{background:var(--bg3);color:var(--text4);border:1px solid var(--border)}
.ep-status.is-default .dot{background:var(--text4)}
.ep-size{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text3);font-weight:600;white-space:nowrap}
.ep-size .lucide{width:12px;height:12px}
.ep-card-htaccess{
    padding:9px 12px;background:var(--bg3);border:1px solid var(--border);
    border-radius:var(--radius-xs);
}
.ep-htaccess-label{font-size:10px;color:var(--text4);display:flex;align-items:center;gap:5px;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;font-weight:700}
.ep-htaccess-label .lucide{width:11px;height:11px}
.ep-card-htaccess code{font-size:11px;color:var(--text2);word-break:break-all;font-family:'Fira Code',monaco,consolas,monospace}
.ep-card-actions{display:flex;gap:6px;margin-top:auto}
.ep-chip{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:5px 12px;color:var(--warning);font-size:20px}
.ep-preview{
    flex:1;min-height:330px;border:1px solid var(--border);border-radius:var(--radius-sm);
    background:#fff;overflow:hidden;position:relative;display:flex;
}
.ep-preview::after{content:'Live update as you type';position:absolute;top:8px;right:10px;font-size:10px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;background:rgba(255,255,255,.8);padding:3px 8px;border-radius:6px;pointer-events:none}
.ep-preview iframe{width:100%;height:100%;min-height:330px;border:none;background:#fff}
@media(max-width:480px){.ep-grid{grid-template-columns:1fr}}
</style>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
