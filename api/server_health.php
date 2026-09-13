<?php
require_once __DIR__ . '/../config.php';

$cpu_count = 1;
if (is_readable('/proc/cpuinfo')) {
    $cpuinfo = file_get_contents('/proc/cpuinfo');
    preg_match_all('/^processor/m', $cpuinfo, $matches);
    $cpu_count = max(count($matches[0]), 1);
}

$load = [0, 0, 0];
$uptime_line = @shell_exec('uptime 2>/dev/null');
if (preg_match('/load average:\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)/', $uptime_line, $lm)) {
    $load = [(float)$lm[1], (float)$lm[2], (float)$lm[3]];
} elseif (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
}

$cpu_model = 'Unknown';
$cpu_model_line = @shell_exec('getprop ro.soc.model 2>/dev/null');
if ($cpu_model_line && trim($cpu_model_line) !== '') {
    $cpu_model = trim($cpu_model_line);
} elseif (is_readable('/proc/cpuinfo')) {
    $ci = file_get_contents('/proc/cpuinfo');
    if (preg_match('/^Hardware\s*:\s*(.+)$/m', $ci, $cm)) $cpu_model = trim($cm[1]);
}

$load_percent = min(round(($load[0] / $cpu_count) * 100), 100);

$storage_paths = ['/storage/emulated/0', '/data', '/'];
$disk_total = 0;
$disk_free = 0;
foreach ($storage_paths as $path) {
    $t = @disk_total_space($path);
    $f = @disk_free_space($path);
    if ($t && $t > $disk_total) { $disk_total = $t; $disk_free = $f ?: 0; }
}
if ($disk_total === 0) {
    $disk_total = @disk_total_space('/') ?: 1;
    $disk_free = @disk_free_space('/') ?: 0;
}
$disk_used = $disk_total - $disk_free;
$disk_percent = round(($disk_used / $disk_total) * 100);

$mem_used = 0;
$mem_total = 0;
$mem_available = 0;
$mem_cached = 0;
$mem_buffers = 0;
$swap_total = 0;
$swap_free = 0;
if (is_readable('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');
    if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m)) $mem_total = $m[1] * 1024;
    if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) $mem_available = $m[1] * 1024;
    if (preg_match('/^MemFree:\s+(\d+)/m', $meminfo, $m)) $mem_free_val = $m[1] * 1024;
    if (preg_match('/^Buffers:\s+(\d+)/m', $meminfo, $m)) $mem_buffers = $m[1] * 1024;
    if (preg_match('/^Cached:\s+(\d+)/m', $meminfo, $m)) $mem_cached = $m[1] * 1024;
    if (preg_match('/^SwapTotal:\s+(\d+)/m', $meminfo, $m)) $swap_total = $m[1] * 1024;
    if (preg_match('/^SwapFree:\s+(\d+)/m', $meminfo, $m)) $swap_free = $m[1] * 1024;
    $mem_used = $mem_total - ($mem_available ?: ($mem_free_val ?? 0));
}
$swap_used = $swap_total - $swap_free;
$mem_percent = $mem_total > 0 ? min(round(($mem_used / $mem_total) * 100), 100) : 0;
$swap_percent = $swap_total > 0 ? min(round(($swap_used / $swap_total) * 100), 100) : 0;

$uptime = 0;
if (is_readable('/proc/uptime')) {
    $up = @file_get_contents('/proc/uptime');
    if ($up) $uptime = (float)(explode(' ', $up)[0]);
}
if ($uptime <= 0) {
    $up_line = @shell_exec('uptime 2>/dev/null');
    if (preg_match('/up\s+(\d+)\s+days?,\s*(\d+):(\d+)/', $up_line, $um)) {
        $uptime = (int)$um[1] * 86400 + (int)$um[2] * 3600 + (int)$um[3] * 60;
    } else {
        $up_line = @shell_exec('uptime -p 2>/dev/null');
        $total = 0;
        if (preg_match('/(\d+)\s+weeks?/', $up_line, $m)) $total += (int)$m[1] * 604800;
        if (preg_match('/(\d+)\s+days?/', $up_line, $m)) $total += (int)$m[1] * 86400;
        if (preg_match('/(\d+)\s+hours?/', $up_line, $m)) $total += (int)$m[1] * 3600;
        if (preg_match('/(\d+)\s+minutes?/', $up_line, $m)) $total += (int)$m[1] * 60;
        $uptime = $total;
    }
}

$tasks_total = 0;
$tasks_running = 0;
$processes = 0;
$top_out = @shell_exec('top -bn1 -d0 2>/dev/null');
if (preg_match('/(\d+)\s+total/', $top_out, $tt)) $tasks_total = (int)$tt[1];
if (preg_match('/(\d+)\s+running/', $top_out, $tr)) $tasks_running = (int)$tr[1];
$processes = $tasks_total;
if (!$processes && is_dir('/proc')) {
    $procs = @scandir('/proc');
    $processes = $procs ? count(array_filter($procs, function($p) { return is_numeric($p); })) : 0;
    $tasks_total = $processes;
    $tasks_running = max(1, intval($processes * 0.1));
}

$io_read = 0;
$io_write = 0;
if (is_readable('/proc/diskstats')) {
    $diskstats = @file_get_contents('/proc/diskstats');
    if ($diskstats) {
        foreach (explode("\n", $diskstats) as $line) {
            $parts = array_values(array_filter(explode(' ', trim($line))));
            if (count($parts) >= 14) {
                $name = $parts[2];
                if (preg_match('/^sd[a-z]$|^nvme\d+n\d+$|^mmcblk\d+$|^vda$|^dm-\d+$/', $name)) {
                    $io_read += (int)$parts[5] * 512;
                    $io_write += (int)$parts[9] * 512;
                }
            }
        }
    }
}
if ($io_read === 0 && $io_write === 0) {
    $io_out = @shell_exec('cat /proc/self/io 2>/dev/null');
    if ($io_out) {
        if (preg_match('/read_bytes:\s+(\d+)/', $io_out, $irm)) $io_read = (int)$irm[1];
        if (preg_match('/write_bytes:\s+(\d+)/', $io_out, $iwm)) $io_write = (int)$iwm[1];
    }
}
if ($io_read === 0 && $io_write === 0 && is_dir('/proc')) {
    $pids = @scandir('/proc');
    if ($pids) {
        foreach ($pids as $p) {
            if (!is_numeric($p)) continue;
            $pio = @file_get_contents("/proc/$p/io");
            if ($pio) {
                if (preg_match('/read_bytes:\s+(\d+)/', $pio, $rm)) $io_read += (int)$rm[1];
                if (preg_match('/write_bytes:\s+(\d+)/', $pio, $wm)) $io_write += (int)$wm[1];
            }
            if ($io_read > 0 || $io_write > 0) break;
        }
    }
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode([
    'disk' => [
        'total'       => $disk_total,
        'used'        => $disk_used,
        'free'        => $disk_free,
        'total_human' => format_size($disk_total),
        'used_human'  => format_size($disk_used),
        'free_human'  => format_size($disk_free),
        'percent'     => $disk_percent,
    ],
    'memory' => [
        'total'         => $mem_total,
        'used'          => $mem_used,
        'free'          => $mem_available,
        'cached'        => $mem_cached,
        'buffers'       => $mem_buffers,
        'total_human'   => format_size($mem_total),
        'used_human'    => format_size($mem_used),
        'free_human'    => format_size($mem_available),
        'cached_human'  => format_size($mem_cached),
        'buffers_human' => format_size($mem_buffers),
        'percent'       => $mem_percent,
    ],
    'swap' => [
        'total'       => $swap_total,
        'used'        => $swap_used,
        'total_human' => format_size($swap_total),
        'used_human'  => format_size($swap_used),
        'percent'     => $swap_percent,
    ],
    'cpu' => [
        'model'   => $cpu_model,
        'load'    => array_map(function($v) { return number_format($v, 2); }, $load),
        'count'   => $cpu_count,
        'percent' => $load_percent,
    ],
    'uptime'        => $uptime,
    'uptime_human'  => format_uptime($uptime),
    'tasks_total'   => $tasks_total,
    'tasks_running' => $tasks_running,
    'processes'     => $processes,
    'io' => [
        'read'        => $io_read,
        'write'       => $io_write,
        'read_human'  => format_size($io_read),
        'write_human' => format_size($io_write),
    ],
    'timestamp' => time(),
]);
