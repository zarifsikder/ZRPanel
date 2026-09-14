<?php
// ============================================================
// ZRPanel on-device authoritative DNS server (UDP).
//
// Serves the zones the cPanel DNS manager edits, straight from the
// panel database — no reload or cron needed. Android/Termux may not
// bind privileged port 53 (no root), so this listens on
// 0.0.0.0:<ZR_DNS_PORT> (default 5390):
//
//   php scripts/dns_server.php                 # default 5390
//   ZR_DNS_PORT=5390 php scripts/dns_server.php
//
// Query it with:  php scripts/dns_probe.php test.dzhost.shop A
// ============================================================

define('NO_SESSION', true);
require_once __DIR__ . '/../config.php';

const T_A    = 1;
const T_NS   = 2;
const T_CNAME= 5;
const T_SOA  = 6;
const T_PTR  = 12;
const T_MX   = 15;
const T_TXT  = 16;
const T_AAAA = 28;
const T_SRV  = 33;
const T_CAA  = 257;
const T_ANY  = 255;

// DNS wire type name -> id lookup (records are stored as "A", "MX", …)
$GLOBALS['__type_id'] = [
    'A' => T_A, 'AAAA' => T_AAAA, 'NS' => T_NS, 'CNAME' => T_CNAME,
    'SOA' => T_SOA, 'PTR' => T_PTR, 'MX' => T_MX, 'TXT' => T_TXT,
    'SRV' => T_SRV, 'CAA' => T_CAA,
];

function type_id(string $name): int {
    return $GLOBALS['__type_id'][strtoupper($name)] ?? 0;
}

$port = (int)(getenv('ZR_DNS_PORT') ?: '5390');
if ($port < 1 || $port > 65535) $port = 5390;

// ------------------------------------------------------------
// DNS wire helpers
// ------------------------------------------------------------

function encode_dn(string $name): string {
    $name = rtrim($name, '.');
    if ($name === '') return "\0";
    $out = '';
    foreach (explode('.', $name) as $label) {
        $out .= chr(strlen($label)) . $label;
    }
    return $out . "\0";
}

// Returns [id, flags, qname(lowercased), qtype, endOffset] or null.
function decode_query(string $pkt): ?array {
    if (strlen($pkt) < 12) return null;
    $id    = unpack('n', substr($pkt, 0, 2))[1];
    $flags = unpack('n', substr($pkt, 2, 2))[1];
    $qd    = unpack('n', substr($pkt, 4, 2))[1];
    $an    = unpack('n', substr($pkt, 6, 2))[1];
    if ($qd < 1 || $an > 0) return null;              // single-question queries only

    $labels = [];
    $pos = 12;
    $max = strlen($pkt);
    $end = null;
    $jumps = 0;
    while (true) {
        if ($pos >= $max) return null;
        $len = ord($pkt[$pos]);
        if (($len & 0xC0) === 0xC0) {                 // compression pointer
            if ($pos + 1 >= $max) return null;
            $ptr = (($len & 0x3F) << 8) | ord($pkt[$pos + 1]);
            if ($end === null) $end = $pos + 2;
            $pos = $ptr;
            if (++$jumps > 40) return null;
            continue;
        }
        if ($len === 0) {
            if ($end === null) $end = $pos + 1;
            break;
        }
        if ($pos + 1 + $len > $max) return null;
        $labels[] = substr($pkt, $pos + 1, $len);
        $pos += $len + 1;
    }
    $qname = strtolower(implode('.', $labels));
    if (strlen($pkt) < $end + 4) return null;
    $qtype  = unpack('n', substr($pkt, $end, 2))[1];
    $qclass = unpack('n', substr($pkt, $end + 2, 2))[1];
    if ($qclass !== 1) return null;                   // IN class only
    return [$id, $flags, $qname, $qtype, $end];
}

function encode_rr(string $owner, int $type, int $ttl, string $rdata): string {
    return encode_dn($owner) . pack('n2Nn', $type, 1, $ttl, strlen($rdata)) . $rdata;
}

function rdata_for(int $type, array $rec, string $zone): ?string {
    $content = (string)($rec['content'] ?? '');
    switch ($type) {
        case T_A:
            $ip = @inet_pton(trim($content));
            return ($ip === false || strlen($ip) !== 4) ? null : $ip;
        case T_AAAA:
            $ip = @inet_pton(trim($content));
            return ($ip === false || strlen($ip) !== 16) ? null : $ip;
        case T_NS:
        case T_CNAME:
        case T_PTR:
            $t = trim($content);
            if ($t === '' || $t === '@') $t = $zone;
            elseif (strpos($t, '.') === false) $t .= ".{$zone}.";
            elseif (substr($t, -1) !== '.') $t .= '.';
            return encode_dn($t);
        case T_MX:
            $t = trim($content);
            if ($t === '' || $t === '@') $t = $zone;
            elseif (strpos($t, '.') === false) $t .= ".{$zone}.";
            elseif (substr($t, -1) !== '.') $t .= '.';
            return pack('n', (int)($rec['priority'] ?? 0)) . encode_dn($t);
        case T_TXT:
            $out = '';
            foreach (str_split($content, 255) as $c) $out .= chr(strlen($c)) . $c;
            return $out;
        case T_SRV:
            $parts = preg_split('/\s+/', trim($content), 3);
            if (count($parts) < 3) return null;
            $t = trim($parts[2]);
            if ($t === '' || $t === '@') $t = $zone;
            elseif (strpos($t, '.') === false) $t .= ".{$zone}.";
            elseif (substr($t, -1) !== '.') $t .= '.';
            return pack('n3', (int)($rec['priority'] ?? 0), (int)$parts[0], (int)$parts[1]) . encode_dn($t);
        case T_CAA:
            $parts = preg_split('/\s+/', trim($content), 3);
            if (count($parts) < 2) return null;
            $flags = (int)$parts[0];
            $tag   = strtolower(trim($parts[1]));
            $val   = trim($parts[2] ?? '', "\"\t ");
            if ($tag === '' || strlen($tag) > 255) return null;
            return pack('C', $flags) . chr(strlen($tag)) . $tag . $val;
    }
    return null;
}

function soa_rdata(array $soa): string {
    return encode_dn($soa['mname'] . '.') . encode_dn($soa['rname'] . '.') .
           pack('N5', $soa['serial'], $soa['refresh'], $soa['retry'], $soa['expire'], $soa['minimum']);
}

// ------------------------------------------------------------
// Zone data (mirrors dns_sync.php — reads the panel DB)
// ------------------------------------------------------------

function build_zones(): ?array {
    try {
        $db = db();
        $ns     = $db->query("SELECT * FROM nameservers ORDER BY ns_domain, ns_name")->fetchAll(PDO::FETCH_ASSOC);
        $records = $db->query("SELECT * FROM dns_records WHERE status = 'active' ORDER BY domain, type, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    $global = SITE_DOMAIN;
    if ($global === '' || $global === 'localhost') {
        try {
            $row = $db->query("SELECT value FROM config WHERE key_name = 'global_domain'")->fetch();
            $global = $row['value'] ?? 'localhost';
        } catch (Throwable $e) {
            $global = 'localhost';
        }
    }
    if ($global === '') $global = 'localhost';

    $serverIp = SERVER_IP !== '' ? SERVER_IP : '127.0.0.1';

    $ns_by_domain = [];
    foreach ($ns as $n) $ns_by_domain[$n['ns_domain']][] = $n;

    $zones = [];
    foreach ($records as $r) $zones[$r['domain']][] = $r;
    if (!isset($zones[$global])) $zones[$global] = [];

    // Nameserver glue A records
    foreach ($ns_by_domain as $domain => $list) {
        if (!isset($zones[$domain])) $zones[$domain] = [];
        foreach ($list as $n) {
            $found = false;
            foreach ($zones[$domain] as $zr) {
                if ($zr['name'] === $n['ns_name'] && $zr['type'] === 'A') { $found = true; break; }
            }
            if (!$found) $zones[$domain][] = ['name' => $n['ns_name'], 'type' => 'A', 'content' => $n['ip_address'], 'ttl' => 86400, 'priority' => 0];
        }
    }

    // Implicit default ns1.<domain> -> SERVER_IP when nothing is configured
    foreach ($zones as $domain => &$recs) {
        $hasNs = false;
        foreach ($recs as $zr) if ($zr['type'] === 'NS') { $hasNs = true; break; }
        if ($hasNs) continue;
        $hasGlue = false;
        foreach ($recs as $zr) {
            if ($zr['type'] === 'A' && ($zr['name'] === 'ns1' || $zr['name'] === 'ns1.' . $domain . '.')) { $hasGlue = true; break; }
        }
        if (!$hasGlue) $recs[] = ['name' => 'ns1', 'type' => 'A', 'content' => $serverIp, 'ttl' => 86400, 'priority' => 0];
    }
    unset($recs);

    $out = [];
    foreach ($zones as $domain => $recs) {
        $domain = rtrim($domain, '.');
        $entries = [];                                 // fqdn_lc => list of records
        $nsTargets = [];

        foreach ($recs as $r) {
            $name = ($r['name'] === '@' || $r['name'] === '') ? $domain : "{$r['name']}.{$domain}";
            $fqdn = strtolower($name);
            $entries[$fqdn][] = ['type' => strtoupper((string)$r['type']), 'content' => $r['content'], 'ttl' => (int)($r['ttl'] ?? 3600), 'priority' => (int)($r['priority'] ?? 0)];
            if ($entries[$fqdn][count($entries[$fqdn]) - 1]['type'] === 'NS' && (string)$r['name'] === '@') {
                $nsTargets[rtrim((string)$r['content'], '.')] = 1;
            }
        }

        // Ensure apex NS records + glue
        $apex = strtolower($domain);
        $hasApexNs = false;
        foreach (($entries[$apex] ?? []) as $e) if ($e['type'] === 'NS') { $hasApexNs = true; break; }
        if (!$hasApexNs) {
            if (empty($ns_by_domain[$domain] ?? [])) {
                $nsTargets['ns1.' . $domain] = 1;
            } else {
                foreach ($ns_by_domain[$domain] as $n) {
                    $nsTargets["{$n['ns_name']}.{$n['ns_domain']}"] = 1;
                }
            }
            foreach (array_keys($nsTargets) as $t) {
                $entries[$apex][] = ['type' => 'NS', 'content' => $t . '.', 'ttl' => 86400, 'priority' => 0];
            }
        }
        foreach (array_keys($nsTargets) as $t) {
            $tl = strtolower($t);
            if (!isset($entries[$tl])) {
                $entries[$tl][] = ['type' => 'A', 'content' => $serverIp, 'ttl' => 86400, 'priority' => 0];
            }
        }

        $primary = 'ns1.' . $domain;
        $nsTargetKeys = array_keys($nsTargets);
        if (!empty($nsTargetKeys)) $primary = $nsTargetKeys[0];

        $out[] = [
            'domain'     => $domain,
            'apex_lc'    => $apex,
            'entries'    => $entries,
            'ns_targets' => $nsTargetKeys,
            'server_ip'  => $serverIp,
            'soa'        => [
                'mname'   => $primary,
                'rname'   => 'admin.' . $domain,
                'serial'  => (int)(date('Ymd') . '01'),
                'refresh' => 3600,
                'retry'   => 900,
                'expire'  => 604800,
                'minimum' => 86400,
            ],
        ];
    }
    return $out;
}

$zoneCache   = null;
$zoneCacheAt = 0;

function zones_refresh(bool $force = false): ?array {
    global $zoneCache, $zoneCacheAt;
    if ($force || $zoneCache === null || (microtime(true) - $zoneCacheAt) > 5) {
        $zoneCache   = build_zones();
        $zoneCacheAt = microtime(true);
    }
    return $zoneCache;
}

// ------------------------------------------------------------
// Query handler
// ------------------------------------------------------------

function answer_query(int $id, int $flags, string $qname, int $qtype): string {
    $zones = zones_refresh();

    // Longest matching hosted zone wins (authoritative apex)
    $zone = null;
    if ($zones !== null) {
        usort($zones, fn($a, $b) => strlen($b['domain']) <=> strlen($a['domain']));
        foreach ($zones as $z) {
            $d = $z['apex_lc'];
            if ($qname === $d || substr($qname, -strlen($d) - 1) === '.' . $d) {
                $zone = $z;
                break;
            }
        }
    }

    $answers   = [];
    $authority = [];
    $rd        = ($flags & 0x0100) ? 0x0100 : 0;

    if ($zone === null) {
        $rcode = 5;                                    // REFUSED — we don't host this name
    } else {
        $soa     = $zone['soa'];
        $apex    = $zone['apex_lc'];
        $entries = $zone['entries'];
        $rcode   = 0;
        $hasName = ($qname === $apex) || isset($entries[$qname]);

        // SOA at the apex
        if ($qname === $apex && ($qtype === T_SOA || $qtype === T_ANY)) {
            $answers[] = encode_rr($qname, T_SOA, 300, soa_rdata($soa));
        }

        // NS at the apex (implicit + explicit)
        if ($qname === $apex && ($qtype === T_NS || $qtype === T_ANY)) {
            foreach ($zone['ns_targets'] as $t) {
                $rdat = encode_dn($t);
                $answers[] = encode_rr($apex, T_NS, 86400, $rdat);
            }
        }

        // Other records living at the exact name
        if ($qtype !== T_SOA) {
            $recs   = $entries[$qname] ?? [];
            $cname  = null;
            $others = [];
            foreach ($recs as $e) {
                if ($e['type'] === 'CNAME') { $cname = $e; } else { $others[] = $e; }
            }

            if ($cname !== null && (empty($others) || $qtype === T_ANY)) {
                $rdat = rdata_for(T_CNAME, $cname, $zone['domain']);
                if ($rdat !== null) $answers[] = encode_rr($qname, T_CNAME, (int)$cname['ttl'], $rdat);
            }
            foreach ($others as $e) {
                if ($qtype !== T_ANY && type_id($e['type']) !== $qtype) continue;
                $tid = type_id($e['type']);
                if ($tid <= 0) continue;
                if ($qname === $apex && ($tid === T_NS || $tid === T_SOA)) continue;
                $rdat = rdata_for($tid, $e, $zone['domain']);
                if ($rdat !== null) $answers[] = encode_rr($qname, $tid, (int)$e['ttl'], $rdat);
            }
        }

        if (empty($answers)) {
            if (!$hasName) {
                $rcode = 3;                            // NXDOMAIN + SOA in authority
                $authority[] = encode_rr($qname, T_SOA, 300, soa_rdata($soa));
            }
        } else {
            // Authoritative answers carry the zone NS + SOA in the authority section
            foreach ($zone['ns_targets'] as $t) {
                $authority[] = encode_rr($t, T_NS, 86400, encode_dn($t));
            }
            $authority[] = encode_rr($zone['domain'], T_SOA, 300, soa_rdata($soa));
        }
    }

    $header = pack('n2n4', $id, 0x8000 | $rd | ($rcode & 0xF), 1, count($answers), count($authority), 0);
    $question = encode_dn($qname) . pack('n2', $qtype, 1);
    return $header . $question . implode('', $answers) . implode('', $authority);
}

// ------------------------------------------------------------
// Server loop
// ------------------------------------------------------------

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
}

$sock = @stream_socket_server("udp://0.0.0.0:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
if (!$sock) {
    fwrite(STDERR, "DNS server: cannot bind udp://0.0.0.0:{$port} — {$errstr}\n");
    exit(1);
}
stream_set_blocking($sock, false);

fwrite(STDOUT, "ZRPanel DNS server listening on 0.0.0.0:{$port} (config DB) — Ctrl+C to stop.\n");

while ($running) {
    $read   = [$sock];
    $write  = null;
    $except = null;
    $ready  = @stream_select($read, $write, $except, 1);
    if ($ready === false || $ready === 0) {
        usleep(100000);
        continue;
    }

    $pkt = @stream_socket_recvfrom($sock, 65535, 0, $peer);
    if ($pkt === false || $peer === '') continue;

    try {
        $q = decode_query($pkt);
        if ($q === null) {
            $id = strlen($pkt) >= 2 ? unpack('n', substr($pkt, 0, 2))[1] : 0;
            $resp = pack('n2', $id, 0x8001) . pack('n4', 0, 0, 0, 0);
        } else {
            [$id, $flags, $qname, $qtype] = $q;
            $resp = answer_query($id, $flags, $qname, $qtype);
        }
        @stream_socket_sendto($sock, $resp, 0, $peer);
    } catch (Throwable $e) {
        fwrite(STDERR, "DNS: " . $e->getMessage() . "\n");
        $resp = pack('n2', $id = (strlen($pkt) >= 2 ? unpack('n', substr($pkt, 0, 2))[1] : 0), 0x8002) . pack('n4', 0, 0, 0, 0);
        @stream_socket_sendto($sock, $resp, 0, $peer);
    }
}

fwrite(STDOUT, "DNS server stopped.\n");
exit(0);