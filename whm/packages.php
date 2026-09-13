<?php
require_once __DIR__ . '/../config.php';
require_whm();
require_feature('whm_packages');
init_db();
$db = db();

function pkg_clean_int($v, $def = 0) {
    $v = (int)($v ?? $def);
    return $v < 0 ? 0 : $v;
}

// ---------------------------------------------------------------------------
// POST actions: create / update / delete
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $disk  = !empty($_POST['disk_unlimited']) ? 0 : pkg_clean_int(($_POST['disk_quota'] ?? 1024), 1024) * 1048576;
        $bw    = !empty($_POST['bw_unlimited']) ? 0 : pkg_clean_int(($_POST['bandwidth'] ?? 10240), 10240) * 1048576;
        $doms  = !empty($_POST['domains_unlimited']) ? 0 : pkg_clean_int(($_POST['max_domains'] ?? 5), 5);

        if ($name === '') {
            flash('error', 'Package name is required');
        } elseif ($action === 'create') {
            $stmt = $db->prepare("INSERT INTO packages (name, disk_quota, bandwidth, max_domains) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $disk, $bw, $doms]);
            flash('success', "Package '{$name}' created");
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("UPDATE packages SET name = ?, disk_quota = ?, bandwidth = ?, max_domains = ? WHERE id = ?");
            $stmt->execute([$name, $disk, $bw, $doms, $id]);
            flash('success', "Package '{$name}' updated");
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM packages WHERE id = ?")->execute([$id]);
        flash('success', 'Package deleted');
    }
    redirect('/whm/packages.php');
}

// Ensure an "Unlimited" package exists (0 = unlimited for all limits).
$hasUnlimited = $db->query("SELECT id FROM packages WHERE name = 'Unlimited'")->fetch();
if (!$hasUnlimited) {
    $db->prepare("INSERT INTO packages (name, disk_quota, bandwidth, max_domains) VALUES ('Unlimited', 0, 0, 0)")->execute();
}

$packages = $db->query("SELECT p.*, (SELECT COUNT(*) FROM users WHERE package_id = p.id AND role='cpanel') as user_count FROM packages p ORDER BY p.name")->fetchAll(PDO::FETCH_ASSOC);

$total_packages = count($packages);
$assigned_users = 0;
$total_disk = 0;
$total_bw = 0;
foreach ($packages as $p) {
    $assigned_users += (int)$p['user_count'];
    $total_disk += (int)$p['disk_quota'];
    $total_bw += (int)$p['bandwidth'];
}

$nav = 'packages';
$page_title = 'Manage Packages';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
    .db-set-badge{display:inline-flex;align-items:center;gap:6px}

    /* ---- Create / edit form unlimited checkboxes ---- */
    .unlim-toggle{display:flex;align-items:center;gap:7px;margin-top:8px;cursor:pointer;user-select:none;width:fit-content}
    .unlim-toggle input[type="checkbox"]{width:15px;height:15px;margin:0;accent-color:var(--success);cursor:pointer}
    .unlim-label{font-size:12px;font-weight:600;color:var(--text4);transition:color .2s}
    .unlim-toggle input:checked ~ .unlim-label{color:var(--success);font-weight:700}

    /* ---- Package list ---- */
    .pkg-list{display:flex;flex-direction:column;gap:12px}
    .pkg-row{
        display:flex;align-items:center;gap:16px;padding:16px 18px 16px 20px;
        background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--radius);
        position:relative;overflow:hidden;
        transition:border-color .2s,box-shadow .2s,transform .2s,opacity .3s
    }
    .pkg-row::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(180deg,#7c3aed,#a78bfa)}
    .pkg-row.plan-unlimited::before{background:linear-gradient(180deg,#059669,#34d399)}
    .pkg-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
    .pkg-ic{
        width:46px;height:46px;min-width:46px;border-radius:12px;
        display:flex;align-items:center;justify-content:center;color:#fff;
        box-shadow:0 4px 10px rgba(124,58,237,.18)
    }
    .pkg-ic .lucide{width:20px;height:20px}
    .pkg-ic.pkg-ic-1{background:linear-gradient(135deg,#6d28d9,#7c3aed 55%,#a78bfa);box-shadow:0 4px 10px rgba(124,58,237,.18)}
    .pkg-ic.pkg-ic-2{background:linear-gradient(135deg,#0059b3,#0073e6 55%,#3397f0);box-shadow:0 4px 10px rgba(0,115,230,.18)}
    .pkg-ic.pkg-ic-3{background:linear-gradient(135deg,#047857,#059669 55%,#10b981);box-shadow:0 4px 10px rgba(5,150,105,.18)}
    .pkg-ic.pkg-ic-4{background:linear-gradient(135deg,#b45309,#d97706 55%,#f59e0b);box-shadow:0 4px 10px rgba(217,119,6,.18)}
    .pkg-ic.pkg-ic-5{background:linear-gradient(135deg,#b91c1c,#dc2626 55%,#ef4444);box-shadow:0 4px 10px rgba(220,38,38,.18)}
    .pkg-ic.pkg-ic-6{background:linear-gradient(135deg,#0f766e,#0d9488 55%,#14b8a6);box-shadow:0 4px 10px rgba(13,148,136,.18)}
    .pkg-ic.pkg-ic-7{background:linear-gradient(135deg,#52525b,#71717a 55%,#a1a1aa);box-shadow:0 4px 10px rgba(113,113,122,.18)}
    .pkg-main{min-width:0;flex:1}
    .pkg-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-weight:800;color:var(--text);font-size:14.5px;letter-spacing:-.2px}
    .pkg-tag{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;padding:2px 8px;border-radius:999px}
    .pkg-tag.tag-unlimited{background:var(--success-light);color:var(--success);border:1px solid var(--success-border)}
    .pkg-tag.tag-limit{background:var(--bg4);color:var(--text4);border:1px solid var(--border2)}
    .pkg-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;margin-top:8px}
    .pkg-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--text2);background:var(--bg3);border:1px solid var(--border);padding:4px 10px;border-radius:8px}
    .pkg-chip .lucide{width:12px;height:12px;color:var(--text4)}
    .pkg-chip.chip-unlimited{background:var(--success-light);border-color:var(--success-border);color:var(--success)}
    .pkg-chip.chip-unlimited .lucide{color:var(--success)}
    .pkg-chip.chip-acc{background:var(--primary-light);border-color:var(--primary);color:var(--primary)}
    .pkg-chip.chip-acc .lucide{color:var(--primary)}
    .pkg-actions{display:flex;gap:8px;align-items:center;flex-shrink:0}
    .pkg-actions .btn{display:inline-flex;align-items:center;gap:5px}
    .pkg-del{background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.25);color:#dc2626}
    .pkg-del:hover{background:rgba(239,68,68,.12);border-color:#dc2626;color:#b91c1c}
    @media(max-width:640px){
      .pkg-row{flex-wrap:wrap;gap:12px;padding:14px}
      .pkg-main{flex-basis:100%}
      .pkg-actions{width:100%}
      .pkg-actions form,.pkg-actions .btn{flex:1;justify-content:center}
    }
</style>

<div class="page-hero fade-in">
    <div class="hero-icon purple"><i data-lucide="package" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Packages</div>
        <div class="hero-desc">Define and manage hosting plans. Each package sets the disk quota, bandwidth and feature limits that customers receive.</div>
    </div>
    <div class="hero-actions">
            <button type="button" class="btn btn-primary" onclick="document.getElementById('createCard').scrollIntoView({behavior:'smooth',block:'center'})"><i data-lucide="plus" class="lucide"></i> New Package</button>
            <span class="badge badge-purple"><i data-lucide="package" class="lucide"></i> <?= $total_packages ?> package<?= $total_packages === 1 ? '' : 's' ?></span>
        </div>
</div>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-purple fade-in">
        <div class="stat-icon icon-purple"><i data-lucide="package" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_packages ?></div>
            <div class="stat-label">Total Packages</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">available hosting plans</div>
        </div>
    </div>
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="users" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $assigned_users ?></div>
            <div class="stat-label">Assigned Accounts</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">customers on a package</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in">
        <div class="stat-icon icon-green"><i data-lucide="hard-drive" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_disk ? format_size($total_disk) : '0' ?></div>
            <div class="stat-label">Allocated Disk</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">total quota across plans</div>
        </div>
    </div>
    <div class="stat-card stat-orange fade-in">
        <div class="stat-icon icon-orange"><i data-lucide="activity" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $total_bw ? format_size($total_bw) : '0' ?></div>
            <div class="stat-label">Allocated Bandwidth</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">total bandwidth across plans</div>
        </div>
    </div>
</div>

<div class="card fade-in" id="createCard">
    <div class="card-header"><h3><i data-lucide="package-plus" class="lucide"></i> Create Package</h3></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="create">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="c_name">Package Name</label>
                <input type="text" name="name" id="c_name" required placeholder="e.g. Basic, Pro, Enterprise">
            </div>
            <div class="form-group">
                <label for="c_disk">Disk Quota (MB)</label>
                <input type="number" name="disk_quota" id="c_disk" value="1024" min="100">
                <label class="unlim-toggle">
                    <input type="checkbox" name="disk_unlimited" onchange="setUnlimited(this,'c_disk')">
                    <span class="unlim-label">Unlimited</span>
                </label>
            </div>
            <div class="form-group">
                <label for="c_bw">Bandwidth (MB)</label>
                <input type="number" name="bandwidth" id="c_bw" value="10240" min="100">
                <label class="unlim-toggle">
                    <input type="checkbox" name="bw_unlimited" onchange="setUnlimited(this,'c_bw')">
                    <span class="unlim-label">Unlimited</span>
                </label>
            </div>
            <div class="form-group">
                <label for="c_doms">Max Domains</label>
                <input type="number" name="max_domains" id="c_doms" value="5" min="1">
                <label class="unlim-toggle">
                    <input type="checkbox" name="domains_unlimited" onchange="setUnlimited(this,'c_doms')">
                    <span class="unlim-label">Unlimited</span>
                </label>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><i data-lucide="plus" class="lucide"></i> Create Package</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1">
    <div class="card-header"><h3><i data-lucide="list" class="lucide"></i> All Packages <span class="db-set-badge badge badge-blue"><?= count($packages) ?></span></h3></div>
    <?php if (!empty($packages)): ?>
    <div class="table-toolbar">
        <div class="toolbar-search">
            <i data-lucide="search" class="lucide"></i>
            <input type="text" id="pkgSearch" placeholder="Search packages..." autocomplete="off">
        </div>
        <span class="toolbar-count" id="pkgCount"><?= count($packages) ?> package<?= count($packages) === 1 ? '' : 's' ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body" style="padding:<?= empty($packages) ? '14px' : '16px' ?>">
        <?php if (empty($packages)): ?>
            <div class="empty-state" style="padding:10px 0 18px">
                <div class="empty-state-icon"><i data-lucide="package" class="lucide"></i></div>
                <strong>No packages created yet</strong>
                <p>Use the form above to create your first hosting plan.</p>
            </div>
        <?php else: ?>
            <div class="pkg-list">
            <?php $i = 0; foreach ($packages as $p):
                $unlim_all = (int)$p['disk_quota'] === 0 && (int)$p['bandwidth'] === 0 && (int)$p['max_domains'] === 0;
                $i++;
            ?>
                <div class="pkg-row <?= $unlim_all ? 'plan-unlimited' : '' ?>" data-name="<?= h($p['name']) ?>">
                    <span class="pkg-ic pkg-ic-<?= (($i - 1) % 7) + 1 ?>"><i data-lucide="package" class="lucide"></i></span>
                    <div class="pkg-main">
                        <div class="pkg-name"><?= h($p['name']) ?>
                            <?php if ($unlim_all): ?>
                                <span class="pkg-tag tag-unlimited">∞ Unlimited</span>
                            <?php else: ?>
                                <span class="pkg-tag tag-limit">Plan</span>
                            <?php endif; ?>
                        </div>
                        <div class="pkg-meta">
                            <span class="pkg-chip <?= (int)$p['disk_quota'] === 0 ? 'chip-unlimited' : '' ?>"><i data-lucide="hard-drive" class="lucide"></i> <?= $p['disk_quota'] > 0 ? format_size($p['disk_quota']) : 'Unlimited' ?></span>
                            <span class="pkg-chip <?= (int)$p['bandwidth'] === 0 ? 'chip-unlimited' : '' ?>"><i data-lucide="activity" class="lucide"></i> <?= $p['bandwidth'] > 0 ? format_size($p['bandwidth']) : 'Unlimited' ?></span>
                            <span class="pkg-chip <?= (int)$p['max_domains'] === 0 ? 'chip-unlimited' : '' ?>"><i data-lucide="globe" class="lucide"></i> <?= $p['max_domains'] > 0 ? ($p['max_domains'] . ' domain' . ($p['max_domains'] == 1 ? '' : 's')) : 'Unlimited' ?></span>
                            <span class="pkg-chip chip-acc"><i data-lucide="user-check" class="lucide"></i> <?= $p['user_count'] ?> account<?= $p['user_count'] == 1 ? '' : 's' ?></span>
                        </div>
                    </div>
                    <div class="pkg-actions">
                        <button type="button" class="btn btn-sm" onclick='openEdit(<?= json_encode([
                            'id' => (int)$p['id'], 'name' => $p['name'],
                            'disk' => (int)round($p['disk_quota'] / 1048576),
                            'bw' => (int)round($p['bandwidth'] / 1048576),
                            'doms' => (int)$p['max_domains'],
                        ], JSON_HEX_APOS) ?>)'><?= $unlim_all ? '<i data-lucide="settings" class="lucide"></i> Customize' : '<i data-lucide="edit" class="lucide"></i> Edit' ?></button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this package?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm pkg-del" title="Delete package"><i data-lucide="trash-2" class="lucide"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ Edit package modal ============ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="max-width:560px">
        <div class="modal-top"><h3><i data-lucide="settings" class="lucide"></i> Edit Package</h3><button type="button" class="modal-x" onclick="document.getElementById('editModal').classList.remove('open')"><i data-lucide="x" class="lucide"></i></button></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="e_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="e_name">Package Name</label>
                        <input type="text" name="name" id="e_name" required>
                    </div>
                    <div class="form-group">
                        <label for="e_disk">Disk Quota (MB)</label>
                        <input type="number" name="disk_quota" id="e_disk" min="100">
                        <label class="unlim-toggle">
                            <input type="checkbox" name="disk_unlimited" id="e_disk_unlimited" onchange="setUnlimited(this,'e_disk')">
                            <span class="unlim-label">Unlimited</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="e_bw">Bandwidth (MB)</label>
                        <input type="number" name="bandwidth" id="e_bw" min="100">
                        <label class="unlim-toggle">
                            <input type="checkbox" name="bw_unlimited" id="e_bw_unlimited" onchange="setUnlimited(this,'e_bw')">
                            <span class="unlim-label">Unlimited</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="e_doms">Max Domains</label>
                        <input type="number" name="max_domains" id="e_doms" min="1">
                        <label class="unlim-toggle">
                            <input type="checkbox" name="domains_unlimited" id="e_doms_unlimited" onchange="setUnlimited(this,'e_doms')">
                            <span class="unlim-label">Unlimited</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="lucide"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function setUnlimited(cb, id) {
    var i = document.getElementById(id);
    if (i) {
        i.disabled = cb.checked;
        if (cb.checked) i.value = '';
    }
}
function openEdit(p) {
    document.getElementById('e_id').value = p.id;
    document.getElementById('e_name').value = p.name;
    document.getElementById('e_disk').value = p.disk;
    document.getElementById('e_bw').value = p.bw;
    document.getElementById('e_doms').value = p.doms;
    [['e_disk','e_disk_unlimited'],['e_bw','e_bw_unlimited'],['e_doms','e_doms_unlimited']].forEach(function (pair) {
        var i = document.getElementById(pair[0]), cb = document.getElementById(pair[1]);
        if (i && cb) {
            var unlimited = (i.value === '' || i.value === '0' || i.value === null);
            cb.checked = unlimited;
            i.disabled = unlimited;
        }
    });
    document.getElementById('editModal').classList.add('open');
    if (window.lucide) window.lucide.createIcons();
}
(function () {
    var input = document.getElementById('pkgSearch');
    var count = document.getElementById('pkgCount');
    if (input && count) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.pkg-row'));
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || (r.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            count.textContent = shown + ' package' + (shown === 1 ? '' : 's');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
