<?php
require_once __DIR__ . '/../config.php';
require_login();
require_feature('dns_services');
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$lookup_host = '';
$lookup_type = 'A';
$dns_results = [];
$dns_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'lookup';

    if ($action === 'lookup') {
        $lookup_host = trim($_POST['host'] ?? '');
        $lookup_type = strtoupper(trim($_POST['type'] ?? 'A'));
        $valid_types = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'ANY'];
        if (!in_array($lookup_type, $valid_types)) $lookup_type = 'A';

        if (empty($lookup_host)) {
            $dns_error = 'Please enter a hostname or domain to look up.';
        } else {
            $lookup_host = preg_replace('/[^a-zA-Z0-9._\-]/', '', $lookup_host);
            $dns_results = perform_dns_lookup($lookup_host, $lookup_type, $dns_error);
        }
    }
}

function perform_dns_lookup($host, $type, &$out_error) {
    $results = [];
    $type_map = [
        'A'     => DNS_A,
        'AAAA'  => DNS_AAAA,
        'MX'    => DNS_MX,
        'NS'    => DNS_NS,
        'TXT'   => DNS_TXT,
        'CNAME' => DNS_CNAME,
        'SOA'   => DNS_SOA,
        'ANY'   => DNS_ANY,
    ];

    $dns_type = $type_map[$type] ?? DNS_A;

    try {
        if ($type === 'MX') {
            $mx_hosts = [];
            $mx_weights = [];
            if (getmxrr($host, $mx_hosts, $mx_weights)) {
                for ($i = 0; $i < count($mx_hosts); $i++) {
                    $results[] = [
                        'host'       => $host,
                        'class'      => 'IN',
                        'type'       => 'MX',
                        'target'     => $mx_hosts[$i],
                        'pri'        => $mx_weights[$i] ?? 0,
                        'ttl'        => 0,
                        'extras'     => [],
                    ];
                }
            }
            $records = @dns_get_record($host, DNS_MX);
            if (!empty($records)) {
                foreach ($records as $r) {
                    $exists = false;
                    foreach ($results as &$er) {
                        if (strtolower($er['target']) === strtolower($r['target'])) { $exists = true; break; }
                    }
                    if (!$exists) {
                        $results[] = [
                            'host'   => $r['host'] ?? $host,
                            'class'  => $r['class'] ?? 'IN',
                            'type'   => 'MX',
                            'target' => $r['target'],
                            'pri'    => $r['pri'] ?? 0,
                            'ttl'    => $r['ttl'] ?? 0,
                            'extras' => [],
                        ];
                    }
                }
            }
        } else {
            $records = @dns_get_record($host, $dns_type);
            if (!empty($records)) {
                foreach ($records as $r) {
                    $results[] = [
                        'host'   => $r['host'] ?? $host,
                        'class'  => $r['class'] ?? 'IN',
                        'type'   => $r['type'] ?? $type,
                        'target' => $r['target'] ?? $r['ip'] ?? $r['ipv6'] ?? $r['txt'] ?? $r['mname'] ?? '',
                        'pri'    => $r['pri'] ?? null,
                        'ttl'    => $r['ttl'] ?? 0,
                        'extras' => array_diff_key($r, array_flip(['host', 'class', 'type', 'target', 'pri', 'ttl', 'ip', 'ipv6', 'txt', 'mname', 'rname', 'serial', 'refresh', 'retry', 'expire', 'minimum'])),
                    ];
                    if (isset($r['mname'])) {
                        $results[count($results)-1]['extras']['mname'] = $r['mname'];
                        $results[count($results)-1]['extras']['rname'] = $r['rname'] ?? '';
                        $results[count($results)-1]['extras']['serial'] = $r['serial'] ?? '';
                        $results[count($results)-1]['extras']['refresh'] = $r['refresh'] ?? '';
                        $results[count($results)-1]['extras']['retry'] = $r['retry'] ?? '';
                        $results[count($results)-1]['extras']['expire'] = $r['expire'] ?? '';
                    }
                }
            }
        }
    } catch (Exception $e) {
        $out_error = 'DNS lookup failed: ' . $e->getMessage();
    }

    if (empty($results) && empty($out_error)) {
        $out_error = "No {$type} records found for " . h($host);
    }

    return $results;
}

$nav = 'trackdns';
$page_title = 'Track DNS';
require_once __DIR__ . '/../templates/header.php';
?>

<?php
$my_domains = $db->prepare("SELECT DISTINCT domain FROM dns_records WHERE user_id = ? ORDER BY domain");
$my_domains->execute([$user_id]);
$my_domains = $my_domains->fetchAll(PDO::FETCH_COLUMN);

$type_colors = ['A' => '#3498db', 'AAAA' => '#9b59b6', 'MX' => '#e74c3c', 'NS' => '#2ecc71', 'TXT' => '#f39c12', 'CNAME' => '#1abc9c', 'SOA' => '#e67e22', 'ANY' => '#95a5a6'];
$type_tips = ['A' => 'IPv4 address', 'AAAA' => 'IPv6 address', 'MX' => 'Mail exchange', 'NS' => 'Name server', 'TXT' => 'Text / SPF', 'CNAME' => 'Alias / canonical', 'SOA' => 'Zone authority', 'ANY' => 'All records'];
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="radar" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Track DNS</div>
        <div class="hero-desc">Look up live DNS records for any hostname and verify your zones are resolving correctly.</div>
    </div>
    <div class="hero-actions"><span class="badge badge-blue" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><i data-lucide="list" class="lucide"></i> 8 record types</span></div>
</div>

<?php
$result_count = count($dns_results);
$result_types = empty($dns_results) ? 0 : count(array_unique(array_column($dns_results, 'type')));
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon"><i data-lucide="server" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number">8</div>
            <div class="stat-label">Lookup Types</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">A, MX, TXT, CNAME + more</div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="check-check" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $result_count ?></div>
            <div class="stat-label">Records Found</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px"><?= $lookup_host ? 'for ' . h($lookup_host) : 'run a lookup to begin' ?></div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon"><i data-lucide="activity" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $result_types ?></div>
            <div class="stat-label">Types Returned</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">distinct record types</div>
        </div>
    </div>
</div>

<div class="card fade-in-delay-2">
    <div class="card-header"><h3><i data-lucide="search" class="lucide"></i> DNS Lookup</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="lookup">
            <div class="form-group">
                <label>Domain / Hostname</label>
                <div class="trk-host-wrap">
                    <i data-lucide="command" class="lucide" style="width:15px;height:15px;color:var(--text4)"></i>
                    <input type="text" name="host" id="trkHost" required placeholder="e.g. example.com" value="<?= h($lookup_host) ?>" autocomplete="off" spellcheck="false">
                </div>
                <?php if (!empty($my_domains)): ?>
                <div class="trk-chips">
                    <span class="trk-chip-label">Your zones</span>
                    <?php foreach ($my_domains as $d): ?>
                        <button type="button" class="trk-chip" data-host="<?= h($d) ?>"><?= h($d) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="form-group" style="margin-top:16px">
                <label>Record Type</label>
                <div class="trk-types">
                    <?php foreach (array_keys($type_tips) as $t): ?>
                        <label class="trk-type">
                            <input type="radio" name="type" value="<?= $t ?>" <?= $t === $lookup_type ? 'checked' : '' ?>>
                            <span class="trk-tile">
                                <span class="trk-dot" style="background:<?= $type_colors[$t] ?>"></span>
                                <span class="trk-tile-t"><?= $t ?></span>
                                <span class="trk-tile-s"><?= $type_tips[$t] ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:18px"><i data-lucide="radar" class="lucide"></i> Look Up</button>
        </form>
    </div>
</div>

<?php if ($lookup_host): ?>
<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-header">
        <h3><i data-lucide="globe" class="lucide"></i> Results for <code style="font-family:'Fira Code',monaco,consolas,monospace;font-size:13px;background:var(--bg4);padding:2px 8px;border-radius:5px"><?= h($lookup_host) ?></code></h3>
        <div class="trk-switch">
            <?php foreach (['A','AAAA','MX','NS','TXT','CNAME','SOA','ANY'] as $qt): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="lookup">
                    <input type="hidden" name="host" value="<?= h($lookup_host) ?>">
                    <input type="hidden" name="type" value="<?= $qt ?>">
                    <button type="submit" class="btn btn-sm <?= $qt === $lookup_type ? 'btn-primary' : 'btn-ghost' ?>"><?= h($qt) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body" style="padding:16px">
        <?php if ($dns_error && empty($dns_results)): ?>
            <div class="trk-err">
                <div class="empty-state-icon"><i data-lucide="search-x" class="lucide"></i></div>
                <strong><?= h($dns_error) ?></strong>
                <p>Check the hostname with the <code class="chip-mono">dig</code> command at your registrar or DNS provider.</p>
            </div>
        <?php elseif (!empty($dns_results)): ?>
            <div class="trk-list">
                <?php foreach ($dns_results as $r): ?>
                    <?php
                    $tc = $type_colors[$r['type']] ?? '#95a5a6';
                    $extra_id = 'extras-' . md5(json_encode($r));
                    $has_extra = !empty($r['extras']);
                    ?>
                    <div class="trk-row" data-host="<?= h(strtolower($r['host'])) ?>">
                        <span class="trk-type" style="background:<?= $tc ?>20;color:<?= $tc ?>;border:1px solid <?= $tc ?>40"><?= h($r['type']) ?></span>
                        <div class="trk-main">
                            <div class="trk-host"><?= h($r['host']) ?></div>
                            <div class="trk-target">
                                <code><?= h($r['target']) ?></code>
                                <button type="button" class="trk-copy" data-copy="<?= h($r['target']) ?>" title="Copy value"><i data-lucide="copy" class="lucide"></i></button>
                            </div>
                            <div class="trk-meta">
                                <span class="badge badge-active"><?= h($r['class']) ?></span>
                                <span>TTL <?= $r['ttl'] > 0 ? ($r['ttl'] >= 86400 ? round($r['ttl']/86400).' day' . ($r['ttl']/86400>=2?'s':'') : ($r['ttl'] >= 3600 ? round($r['ttl']/3600).' hour' . ($r['ttl']/3600>=2?'s':'') : round($r['ttl']/60).' min' . ($r['ttl']/60>=2?'s':''))) : '—' ?></span>
                                <?php if ($r['pri'] !== null): ?><span>Priority <?= (int)$r['pri'] ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="trk-side">
                            <?php if ($has_extra): ?>
                                <button type="button" class="trk-info" data-target="#<?= $extra_id ?>" title="Details"><i data-lucide="info" class="lucide"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php if ($has_extra): ?>
                        <div class="trk-extra" id="<?= $extra_id ?>">
                            <div class="trk-extra-title">Record details</div>
                            <div class="trk-extra-grid">
                                <?php foreach ($r['extras'] as $ek => $ev): ?>
                                    <div><span><?= h($ek) ?></span><code><?= h(is_array($ev) ? implode(', ', $ev) : $ev) ?></code></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card fade-in-delay-2" style="margin-top:16px">
    <div class="card-header"><h3><i data-lucide="book-open" class="lucide"></i> DNS Record Types Reference</h3></div>
    <div class="card-body">
        <div class="trk-ref-grid">
            <?php
            $ref = [
                'A'     => ['Maps a domain to an IPv4 address', '93.184.216.34'],
                'AAAA'  => ['Maps a domain to an IPv6 address', '2606:2800:220:1:248:1893:25c8:1946'],
                'MX'    => ['Routes mail for the domain', '10 mail.example.com'],
                'NS'    => ['Authoritative name server', 'ns1.example.com'],
                'TXT'   => ['Text record (SPF, DKIM, etc.)', 'v=spf1 include:_spf.google.com ~all'],
                'CNAME' => ['Aliases one name to another', 'www.example.com'],
                'SOA'   => ['Start of authority (zone info)', 'ns1.example.com admin.example.com'],
            ];
            foreach ($ref as $t => $info): ?>
            <div class="trk-ref-tile">
                <div class="trk-ref-top">
                    <span class="trk-type" style="background:<?= $type_colors[$t] ?>20;color:<?= $type_colors[$t] ?>;border:1px solid <?= $type_colors[$t] ?>40"><?= $t ?></span>
                    <span class="trk-tile-s"><?= h($info[0]) ?></span>
                </div>
                <code><?= h($info[1]) ?></code>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
.trk-host-wrap{display:flex;align-items:center;gap:9px;padding:0 12px;border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);transition:border-color .15s}
.trk-host-wrap:focus-within{border-color:var(--primary)}
.trk-host-wrap .lucide{width:15px;height:15px;color:var(--text4);flex-shrink:0}
.trk-host-wrap input{flex:1;border:none;outline:none;background:transparent;padding:9px 0;font-size:13px;color:var(--text)}
.trk-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:9px}
.trk-chip-label{font-size:11px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px}
.trk-chip{padding:4px 10px;border:1px solid var(--border);border-radius:99px;background:var(--bg2);color:var(--text3);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:'Fira Code',monaco,consolas,monospace}
.trk-chip:hover{border-color:var(--primary);color:var(--primary)}
.trk-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr));gap:8px}
.trk-type{position:relative;cursor:pointer}
.trk-type input{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:1}
.trk-tile{display:flex;align-items:center;gap:8px;height:100%;padding:9px 10px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-sm);transition:border-color .15s,box-shadow .15s,transform .15s}
.trk-type:hover .trk-tile{border-color:var(--text4);transform:translateY(-1px)}
.trk-type input:checked + .trk-tile{border-color:var(--primary);background:var(--primary-light);box-shadow:0 0 0 2px rgba(0,115,230,.12)}
.trk-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.trk-tile-t{font-weight:700;font-size:12px;color:var(--text);font-family:monospace}
.trk-tile-s{font-size:10px;color:var(--text4);line-height:1.3;min-width:0}
.trk-switch{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
.trk-list{display:flex;flex-direction:column;gap:10px}
.trk-row{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;padding:13px 14px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .2s,box-shadow .2s,transform .2s}
.trk-row:hover{border-color:var(--text4);box-shadow:var(--shadow);transform:translateY(-2px)}
.trk-row.has-extra{grid-template-rows:auto auto}
.trk-type{font-size:11px;font-weight:700;padding:3px 9px;border-radius:6px;font-family:monospace;justify-self:start;align-self:start;line-height:1.4}
.trk-main{min-width:0}
.trk-host{font-size:12px;font-weight:700;color:var(--text);font-family:'Fira Code',monaco,consolas,monospace;word-break:break-all}
.trk-target{display:flex;align-items:center;gap:6px;margin-top:3px;max-width:100%}
.trk-target code{font-size:12px;color:var(--text2);font-family:'Fira Code',monaco,consolas,monospace;word-break:break-all;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:3px 8px}
.trk-copy{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:none;border-radius:5px;cursor:pointer;background:transparent;color:var(--text4);transition:all .15s;flex-shrink:0}
.trk-copy .lucide{width:12px;height:12px}
.trk-copy:hover{background:rgba(0,115,230,.1);color:var(--primary)}
.trk-copy.copied{background:rgba(5,150,105,.12);color:var(--success)}
.trk-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--text4);margin-top:6px}
.trk-meta .badge{font-size:10px;padding:2px 7px}
.trk-side{display:flex;align-items:center;gap:6px}
.trk-info{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--border);border-radius:7px;cursor:pointer;background:var(--bg3);color:var(--text3);transition:all .15s}
.trk-info:hover{color:var(--primary);border-color:var(--primary)}
.trk-info .lucide{width:14px;height:14px}
.trk-extra{display:none;grid-column:1/-1;margin-left:56px;background:var(--bg3);border:1px dashed var(--border);border-radius:var(--radius-sm);padding:10px 12px}
.trk-extra-title{font-size:10.5px;font-weight:700;color:var(--text4);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.trk-extra-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,170px),1fr));gap:4px 14px}
.trk-extra-grid > div{font-size:11px;color:var(--text4)}
.trk-extra-grid span{font-weight:600}
.trk-extra-grid code{font-family:'Fira Code',monaco,consolas,monospace;color:var(--text2);word-break:break-all}
.trk-err{display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;padding:36px 16px;color:var(--text4)}
.trk-err strong{color:var(--text);font-size:14px}
.trk-err p{font-size:12px;margin:0;max-width:420px}
.trk-ref-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr));gap:10px}
.trk-ref-tile{display:flex;flex-direction:column;gap:8px;padding:13px 14px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-sm);transition:border-color .2s,transform .2s}
.trk-ref-tile:hover{border-color:var(--text4);transform:translateY(-1px)}
.trk-ref-top{display:flex;align-items:center;gap:9px;min-width:0}
.trk-ref-top .trk-tile-s{flex:1;text-align:left}
.trk-ref-tile code{font-size:11px;font-family:'Fira Code',monaco,consolas,monospace;color:var(--text3);word-break:break-all}
@media(max-width:620px){
  .trk-row{grid-template-columns:auto 1fr}
  .trk-side{grid-column:1/-1;justify-content:flex-end}
  .trk-extra{margin-left:0}
  .trk-switch{width:100%;justify-content:flex-start}
}
</style>

<script>
(function () {
    document.querySelectorAll('.trk-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            var input = document.getElementById('trkHost');
            if (input) input.value = c.getAttribute('data-host');
        });
    });
    document.querySelectorAll('.trk-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-copy') || '';
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(val).then(function () { flashCopy(btn); }).catch(function () { fallbackCopy(val, btn); });
            } else {
                fallbackCopy(val, btn);
            }
        });
    });
    function fallbackCopy(val, btn) {
        var ta = document.createElement('textarea');
        ta.value = val;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        flashCopy(btn);
    }
    function flashCopy(btn) {
        var icon = btn.querySelector('.lucide');
        var old = icon ? icon.getAttribute('data-lucide') : 'copy';
        if (icon) icon.setAttribute('data-lucide', 'check');
        btn.classList.add('copied');
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({root: btn});
        setTimeout(function () {
            btn.classList.remove('copied');
            if (icon) icon.setAttribute('data-lucide', old);
            if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons({root: btn});
        }, 1200);
    }
    document.querySelectorAll('.trk-info').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.querySelector(b.getAttribute('data-target'));
            if (el) el.style.display = el.style.display === 'block' ? 'none' : 'block';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
