<?php
// ============================================================
// Local authoritative DNS exporter (NSD).
//
// Reads the panel database (same unix-socket / root connection the
// panel itself uses) and writes RFC-1035 zone files plus an NSD
// config. On Android/Termux /etc is read-only (/etc -> /system/etc),
// so everything is exported under a writable base directory, default
// $HOME/.nsd. Point real NSD (or the bundled PHP DNS server at
// scripts/dns_server.php) at these files.
//
//   php dns_sync.php            # refresh zone files + nsd.conf
//   ZR_NSD_BASE=/x php dns_sync.php
// ============================================================

define('NO_SESSION', true);               // CLI: no web session needed
require_once __DIR__ . '/config.php';

// --- Writable storage location (Termux-safe; /etc/nsd is read-only) ---

$__nsd_base = getenv('ZR_NSD_BASE');
if ($__nsd_base === false || $__nsd_base === '') {
    $__nsd_base = (getenv('HOME') ?: '/data/data/com.termux/files/home') . '/.nsd';
}
define('NSD_BASE', rtrim($__nsd_base, '/'));
define('NSD_ZONES_DIR', NSD_BASE . '/zones');
define('NSD_CONF', NSD_BASE . '/nsd.conf');
define('NSD_DB', NSD_BASE . '/nsd.db');

function nsd_serial() {
    return date('Ymd') . '01';
}

// A target without a dot is relative to the zone; one with dots is
// treated as an absolute name (trailing dot enforced).
function zone_target(string $content): string {
    $t = trim($content);
    if ($t === '' || $t === '@') return '@';
    if (strpos($t, '.') === false) return $t;
    return substr($t, -1) === '.' ? $t : $t . '.';
}

function sync_dns_to_zones() {
    foreach ([NSD_ZONES_DIR, dirname(NSD_CONF), dirname(NSD_DB)] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    $db = db();

    $nameservers = $db->query("SELECT * FROM nameservers ORDER BY ns_domain, ns_name")->fetchAll(PDO::FETCH_ASSOC);
    $records = $db->query("SELECT * FROM dns_records WHERE status = 'active' ORDER BY domain, type, name")->fetchAll(PDO::FETCH_ASSOC);

    $row = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
    $global_domain = $row['value'] ?? '';
    if (SITE_DOMAIN !== '' && SITE_DOMAIN !== 'localhost') {
        $global_domain = SITE_DOMAIN;
    }
    if ($global_domain === '') {
        $global_domain = 'localhost';
    }

    $zones = [];
    $ns_by_domain = [];
    foreach ($nameservers as $ns) {
        $ns_by_domain[$ns['ns_domain']][] = $ns;
    }

    foreach ($records as $r) {
        $zones[$r['domain']][] = $r;
    }

    // Glue A records for every configured nameserver of its zone.
    foreach ($ns_by_domain as $domain => $ns_list) {
        if (!isset($zones[$domain])) {
            $zones[$domain] = [];
        }
        foreach ($ns_list as $ns) {
            $already = false;
            foreach ($zones[$domain] as $zr) {
                if ($zr['name'] === $ns['ns_name'] && $zr['type'] === 'A') {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $zones[$domain][] = [
                    'name' => $ns['ns_name'],
                    'type' => 'A',
                    'content' => $ns['ip_address'],
                    'ttl' => 86400,
                    'priority' => 0,
                ];
            }
        }
    }

    // The global (site) zone must exist.
    if (empty($zones[$global_domain])) {
        $zones[$global_domain] = [];
    }

    $ns_for_global = $ns_by_domain[$global_domain] ?? [];

    // No nameservers configured at all -> fall back to ns1.<domain> glue
    // pointing at SERVER_IP so the zone stays self-delegatable.
    foreach ($zones as $domain => &$recs) {
        $has_ns = false;
        foreach ($recs as $zr) {
            if ($zr['type'] === 'NS') {
                $has_ns = true;
                break;
            }
        }
        $ns_list = $ns_by_domain[$domain] ?? [];
        if (!$has_ns && empty($ns_list)) {
            $ns1 = "ns1.{$domain}.";
            $has_glue = false;
            foreach ($recs as $zr) {
                if ($zr['type'] === 'A' && ($zr['name'] === $domain . '.' || $zr['name'] === '@' && $domain === $global_domain)) {
                    continue;
                }
                if ($zr['type'] === 'A' && rtrim($zr['name'], '.') === $domain) {
                    $has_glue = true;
                    break;
                }
            }
            foreach ($recs as $zr) {
                if ($zr['type'] === 'A' && ($zr['name'] === 'ns1' || $zr['name'] === 'ns1.' . $domain . '.')) {
                    $has_glue = true;
                    break;
                }
            }
            if (!$has_glue && SERVER_IP !== '' && SERVER_IP !== '127.0.0.1') {
                $recs[] = [
                    'name' => 'ns1',
                    'type' => 'A',
                    'content' => SERVER_IP,
                    'ttl' => 86400,
                    'priority' => 0,
                ];
            }
        }
    }
    unset($recs);

    if (empty($ns_by_domain[$global_domain])) {
        $ns_by_domain[$global_domain] = [
            ['ns_domain' => $global_domain, 'ns_name' => 'ns1', 'ip_address' => SERVER_IP],
        ];
    }

    $serial = nsd_serial();
    $zone_files = [];
    $zone_domains = [];

    foreach ($zones as $domain => $recs) {
        $zone_file = NSD_ZONES_DIR . "/{$domain}.zone";

        $lines = [];
        $lines[] = "\$TTL 86400";
        $lines[] = "@ IN SOA ns1.{$domain}. admin.{$domain}. (";
        $lines[] = "    {$serial}    ; serial";
        $lines[] = "    3600        ; refresh";
        $lines[] = "    900         ; retry";
        $lines[] = "    604800      ; expire";
        $lines[] = "    86400       ; minimum ttl";
        $lines[] = ")";

        $has_ns = false;
        $ns_list = $ns_by_domain[$domain] ?? [];
        foreach ($recs as $r) {
            if ($r['type'] === 'NS') {
                $has_ns = true;
                break;
            }
        }
        if (!$has_ns) {
            if (!empty($ns_list)) {
                foreach ($ns_list as $ns) {
                    $lines[] = "@ IN NS {$ns['ns_name']}.{$domain}.";
                }
            } else {
                $lines[] = "@ IN NS ns1.{$domain}.";
            }
        }

        foreach ($recs as $r) {
            if ($r['type'] === 'SOA') {
                continue;
            }

            $name = $r['name'] === '@' ? '@' : "{$r['name']}.{$domain}.";
            $ttl = $r['ttl'] ?? 3600;

            switch ($r['type']) {
                case 'A':
                    $lines[] = "{$name} IN A {$r['content']}";
                    break;
                case 'AAAA':
                    $lines[] = "{$name} IN AAAA {$r['content']}";
                    break;
                case 'CNAME':
                    $lines[] = "{$name} IN CNAME " . zone_target($r['content']);
                    break;
                case 'MX':
                    $lines[] = "{$name} IN MX {$r['priority']} " . zone_target($r['content']);
                    break;
                case 'TXT':
                    $lines[] = "{$name} IN TXT \"" . str_replace('"', '\"', $r['content']) . "\"";
                    break;
                case 'NS':
                    $lines[] = "{$name} IN NS " . zone_target($r['content']);
                    break;
                case 'SRV':
                    $parts = preg_split('/\s+/', trim($r['content']), 3);
                    if (count($parts) < 3) break;
                    $lines[] = "{$name} IN SRV {$r['priority']} {$parts[0]} {$parts[1]} " . zone_target($parts[2]);
                    break;
                case 'CAA':
                    $lines[] = "{$name} IN CAA {$r['content']}";
                    break;
                case 'PTR':
                    $lines[] = "{$name} IN PTR " . zone_target($r['content']);
                    break;
            }
        }

        file_put_contents($zone_file, implode("\n", $lines) . "\n");
        $zone_files[$domain] = $zone_file;
        $zone_domains[] = $domain;
    }

    // On Android nothing may run on privileged port 53; NSD config still
    // targets 53 by default and is overridden where it runs with root.
    $nsd_conf = "# ZRPanel Auto-generated NSD config\n";
    $nsd_conf .= "server:\n";
    $nsd_conf .= "    ip-address: 0.0.0.0\n";
    $nsd_conf .= "    ip-address: ::0\n";
    $nsd_conf .= "    database: " . NSD_DB . "\n";
    $nsd_conf .= "    username: \"\"\n";
    $nsd_conf .= "    log-level: 2\n\n";

    foreach ($zone_files as $domain => $file) {
        $nsd_conf .= "zone:\n";
        $nsd_conf .= "    name: {$domain}\n";
        $nsd_conf .= "    zonefile: {$file}\n\n";
    }

    file_put_contents(NSD_CONF, $nsd_conf);

    // Reload a real NSD if one is present (external hosts only).
    if (is_executable('/usr/bin/nsd-control') || is_executable('/usr/sbin/nsd-control')) {
        exec('nsd-control reload 2>&1', $output, $ret);
        if ($ret !== 0) {
            exec('nsd-control restart 2>&1', $output, $ret);
        }
    }

    return $zone_domains;
}

try {
    $done = sync_dns_to_zones();
    echo date('Y-m-d H:i:s') . " DNS sync complete: " . count($done) . " zones -> " . NSD_ZONES_DIR . "\n";
    echo "On-device authoritative DNS: \"php " . __DIR__ . "/scripts/dns_server.php\" (ZR_DNS_PORT, default 5390)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "DNS sync failed: " . $e->getMessage() . "\n");
    exit(1);
}