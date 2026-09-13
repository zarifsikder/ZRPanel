<?php
define('MYSQL_HOST', getenv('MYSQL_HOST') ?: '127.0.0.1');
define('MYSQL_PORT', getenv('MYSQL_PORT') ?: '3306');
define('MYSQL_DB', getenv('MYSQL_DB') ?: 'panel');
define('MYSQL_USER', getenv('MYSQL_USER') ?: 'paneluser');
define('MYSQL_PASS', getenv('MYSQL_PASS') ?: 'panel2026pass');

define('NSD_ZONES_DIR', '/etc/nsd/zones');
define('NSD_CONF', '/etc/nsd/nsd.conf');
define('NSD_DB', '/var/lib/nsd/nsd.db');

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function generate_serial() {
    return date('Ymd') . '01';
}

function sync_dns() {
    $db = get_db();

    if (!is_dir(NSD_ZONES_DIR)) {
        mkdir(NSD_ZONES_DIR, 0755, true);
    }

    $nameservers = $db->query("SELECT * FROM nameservers ORDER BY ns_domain, ns_name")->fetchAll(PDO::FETCH_ASSOC);
    $records = $db->query("SELECT * FROM dns_records WHERE status = 'active' ORDER BY domain, type, name")->fetchAll(PDO::FETCH_ASSOC);
    $global_domain_row = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
    $global_domain = $global_domain_row['value'] ?? 'dzhost.shop';

    $zones = [];
    $ns_by_domain = [];
    foreach ($nameservers as $ns) {
        $ns_by_domain[$ns['ns_domain']][] = $ns;
    }

    foreach ($records as $r) {
        $zones[$r['domain']][] = $r;
    }

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

    if (empty($zones[$global_domain])) {
        $zones[$global_domain] = [];
    }

    $ns_for_global = $ns_by_domain[$global_domain] ?? [];
    $has_soa_ns = false;
    foreach ($zones[$global_domain] as $zr) {
        if ($zr['type'] === 'NS') {
            $has_soa_ns = true;
            break;
        }
    }
    if (!$has_soa_ns && !empty($ns_for_global)) {
        foreach ($ns_for_global as $ns) {
            array_unshift($zones[$global_domain], [
                'name' => '@',
                'type' => 'NS',
                'content' => "{$ns['ns_name']}.{$global_domain}.",
                'ttl' => 86400,
                'priority' => 0,
            ]);
        }
    }

    if (!empty($ns_for_global)) {
        $has_soa = false;
        foreach ($zones[$global_domain] as $zr) {
            if ($zr['type'] === 'SOA') {
                $has_soa = true;
                break;
            }
        }
        if (!$has_soa) {
            $primary_ns = "{$ns_for_global[0]['ns_name']}.{$global_domain}.";
            array_unshift($zones[$global_domain], [
                'name' => '@',
                'type' => 'SOA',
                'content' => "{$primary_ns} admin.{$global_domain}. " . generate_serial() . " 3600 900 604800 86400",
                'ttl' => 86400,
                'priority' => 0,
            ]);
        }
    }

    $serial = generate_serial();
    $zone_files = [];
    $zone_domains = [];

    foreach ($zones as $domain => $recs) {
        $zone_file = NSD_ZONES_DIR . "/{$domain}.zone";
        $zone_files[$domain] = $zone_file;

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
        if (!$has_ns && !empty($ns_list)) {
            foreach ($ns_list as $ns) {
                $lines[] = "@ IN NS {$ns['ns_name']}.{$domain}.";
            }
        }

        $has_soa_ns = false;
        foreach ($recs as $r) {
            if ($r['type'] === 'SOA') {
                $has_soa_ns = true;
                break;
            }
        }
        if (!$has_soa_ns && empty($ns_list)) {
            $lines[] = "@ IN NS ns1.{$domain}.";
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
                    $target = rtrim($r['content'], '.') . '.';
                    $lines[] = "{$name} IN CNAME {$target}";
                    break;
                case 'MX':
                    $lines[] = "{$name} IN MX {$r['priority']} {$r['content']}.";
                    break;
                case 'TXT':
                    $lines[] = "{$name} IN TXT \"{$r['content']}\"";
                    break;
                case 'NS':
                    $lines[] = "{$name} IN NS {$r['content']}";
                    break;
                case 'SRV':
                    $lines[] = "{$name} IN SRV {$r['priority']} {$r['content']}";
                    break;
                case 'CAA':
                    $lines[] = "{$name} IN CAA {$r['content']}";
                    break;
            }
        }

        file_put_contents($zone_file, implode("\n", $lines) . "\n");
        $zone_domains[] = $domain;
    }

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

    exec('nsd-control reload 2>&1', $output, $ret);
    if ($ret !== 0) {
        exec('nsd-control restart 2>&1', $output, $ret);
    }

    echo date('Y-m-d H:i:s') . " DNS sync complete: " . count($zone_domains) . " zones\n";
}

sync_dns();
