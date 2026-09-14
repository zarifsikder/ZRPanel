<?php
// ============================================================
// ZRPanel DNS probe — tiny client for scripts/dns_server.php
// (or any DNS server). Prints the decoded answer.
//
//   php scripts/dns_probe.php [@server:port] [name] [type]
//
// Defaults: @127.0.0.1:5390  example.com  A
// ============================================================

$name   = 'dzhost.shop';
$type   = 'A';
$server = '127.0.0.1';
$port   = (int)(getenv('ZR_DNS_PORT') ?: '5390');
$typeMap = ['A'=>1,'NS'=>2,'CNAME'=>5,'SOA'=>6,'PTR'=>12,'MX'=>15,'TXT'=>16,'AAAA'=>28,'SRV'=>33,'CAA'=>257,'ANY'=>255];

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if ($arg === '') continue;
    if ($arg[0] === '@') {
        $sp = explode(':', substr($arg, 1));
        $server = array_shift($sp);
        if (isset($sp[0]) && $sp[0] !== '') $port = (int)$sp[0];
    } elseif (isset($typeMap[strtoupper($arg)])) {
        $type = strtoupper($arg);
    } else {
        $name = $arg;
    }
}

$qtype = $typeMap[$type] ?? 1;

function en_dn(string $name): string {
    $name = rtrim($name, '.');
    if ($name === '') return "\0";
    $o = '';
    foreach (explode('.', $name) as $l) $o .= chr(strlen($l)) . $l;
    return $o . "\0";
}

function de_name(string $pkt, int &$off): ?array {
    $labels = [];
    $max = strlen($pkt);
    $jumps = 0;
    while (true) {
        if ($off >= $max) return null;
        $len = ord($pkt[$off]);
        if (($len & 0xC0) === 0xC0) {
            if ($off + 1 >= $max) return null;
            $ptr = (($len & 0x3F) << 8) | ord($pkt[$off + 1]);
            if (++$jumps > 40) return null;
            $off += 2;
            // resolve pointer iteratively
            $saved = $off;
            $tmp = $ptr;
            while (true) {
                if ($tmp >= $max) return null;
                $l = ord($pkt[$tmp]);
                if ($l === 0) break;
                if (($l & 0xC0) === 0xC0) {
                    $tmp = (($l & 0x3F) << 8) | ord($pkt[$tmp + 1]);
                    continue;
                }
                if ($tmp + 1 + $l > $max) return null;
                $labels[] = substr($pkt, $tmp + 1, $l);
                $tmp += $l + 1;
                if (count($labels) > 127) return null;
            }
            return [implode('.', $labels)];
        }
        if ($len === 0) { $off++; break; }
        if ($off + 1 + $len > $max) return null;
        $labels[] = substr($pkt, $off + 1, $len);
        $off += $len + 1;
        if (count($labels) > 127) return null;
    }
    return [implode('.', $labels)];
}

function fmt_rdata(string $pkt, int $off, int $type, int $rdlen): string {
    switch ($type) {
        case 1:  $ip = substr($pkt, $off, $rdlen); return $rdlen === 4 ? @inet_ntop($ip) : '(bad A)';
        case 28: $ip = substr($pkt, $off, $rdlen); return $rdlen === 16 ? @inet_ntop($ip) : '(bad AAAA)';
        case 2: case 5: case 12:
            $n = de_name($pkt, $off); return $n ? $n[0] : '(bad name)';
        case 15:
            $pri = unpack('n', substr($pkt, $off, 2))[1];
            $o = $off + 2;
            $n = de_name($pkt, $o);
            return ($n ? $n[0] : '(bad)') . " pri={$pri}";
        case 16:
            $e = $off; $end = $off + $rdlen; $parts = [];
            while ($e < $end) { $l = ord($pkt[$e]); $parts[] = substr($pkt, $e + 1, $l); $e += 1 + $l; }
            return '"' . implode('" "', $parts) . '"';
        case 6:
            $m = de_name($pkt, $off);
            $r = de_name($pkt, $off);
            $ints = unpack('N5', substr($pkt, $off, 20));
            return ($m ? $m[0] : '?') . ' ' . ($r ? $r[0] : '?') . ' serial=' . $ints[1] . ' refresh=' . $ints[2] . ' retry=' . $ints[3] . ' expire=' . $ints[4] . ' min=' . $ints[5];
        case 33:
            $v = unpack('n3', substr($pkt, $off, 6));
            $o = $off + 6;
            $n = de_name($pkt, $o);
            return ($n ? $n[0] : '?') . " pri={$v[1]} weight={$v[2]} port={$v[3]}";
        case 257:
            $flags = ord($pkt[$off]);
            $taglen = ord($pkt[$off + 1]);
            $tag = substr($pkt, $off + 2, $taglen);
            $val = substr($pkt, $off + 2 + $taglen, $rdlen - 2 - $taglen);
            return "flags={$flags} {$tag}=\"{$val}\"";
        default:
            return bin2hex(substr($pkt, $off, $rdlen));
    }
}

// Build + send query
$id  = random_int(0, 0xFFFF);
$qry = pack('n2n4', $id, 0x0100, 1, 0, 0, 0) . en_dn($name) . pack('n2', $qtype, 1);

$sock = @stream_socket_client("udp://{$server}:{$port}", $errno, $errstr, 3);
if (!$sock) {
    fwrite(STDERR, "Cannot reach {$server}:{$port} — {$errstr}\n");
    exit(2);
}
stream_set_timeout($sock, 3);
fwrite($sock, $qry);
$resp = '';
while (strlen($resp) < 512) {
    $chunk = fread($sock, 512);
    if ($chunk === false || $chunk === '') break;
    $resp .= $chunk;
}

if (strlen($resp) < 12) {
    fwrite(STDERR, "No (or empty) DNS response from {$server}:{$port}\n");
    exit(2);
}

$rid  = unpack('n', substr($resp, 0, 2))[1];
$flag = unpack('n', substr($resp, 2, 2))[1];
$qd   = unpack('n', substr($resp, 4, 2))[1];
$an   = unpack('n', substr($resp, 6, 2))[1];
$ns   = unpack('n', substr($resp, 8, 2))[1];
$ar   = unpack('n', substr($resp, 10, 2))[1];
$rcode = $flag & 0xF;

$off = 12;
for ($i = 0; $i < $qd; $i++) {
    $n = de_name($resp, $off);
    if (!$n) break;
    $off += 4; // qtype + qclass
}

printf("DNS %s -> %s:%d (qid %d, rcode %d)\n", $name, $server, $port, $rid, $rcode);
printf("answers=%d authority=%d additional=%d\n\n", $an, $ns, $ar);

$fmtType = 0; // will trigger type of each rr
$count = 0;
for ($sec = 0; $sec < 3; $sec++) {
    $cnt = $sec === 0 ? $an : ($sec === 1 ? $ns : $ar);
    $label = ['ANSWER', 'AUTHORITY', 'ADDITIONAL'][$sec];
    for ($i = 0; $i < $cnt; $i++) {
        $n = de_name($resp, $off);
        if (!$n) break;
        if ($off + 10 > strlen($resp)) break;
        $rrh = unpack('ntype/nclass/Nttl/nrdlen', substr($resp, $off, 10));
        $rrtype = $rrh['type'];
        $ttl = $rrh['ttl'];
        $rdlen = $rrh['rdlen'];
        $off += 10;
        if ($off + $rdlen > strlen($resp)) break;
        $text = fmt_rdata($resp, $off, $rrtype, $rdlen);
        $off += $rdlen;
        $tname = [1=>'A',2=>'NS',5=>'CNAME',6=>'SOA',12=>'PTR',15=>'MX',16=>'TXT',28=>'AAAA',33=>'SRV',257=>'CAA'][$rrtype] ?? (string)$rrtype;
        printf("  %-6s %-10s %s %-7s %5d  %s\n", $label, $n[0], 'IN', $tname, $ttl, $text);
        $count++;
    }
}

if ($rcode === 3) { echo "  (NXDOMAIN)\n"; exit(1); }
exit($an > 0 ? 0 : 1);