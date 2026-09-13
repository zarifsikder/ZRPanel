<?php
require_once __DIR__ . '/../config.php';

$load = [0, 0, 0];
$uptime_line = @shell_exec('uptime 2>/dev/null');
if (preg_match('/load average:\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)/', $uptime_line, $lm)) {
    $load = [(float)$lm[1], (float)$lm[2], (float)$lm[3]];
} elseif (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
}

$cpu_count = 1;
if (is_readable('/proc/cpuinfo')) {
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo) {
        preg_match_all('/^processor/m', $cpuinfo, $matches);
        $cpu_count = max(count($matches[0]), 1);
    }
}

$cpu_model = 'Unknown';
$cpu_model_line = @shell_exec('getprop ro.soc.model 2>/dev/null');
if ($cpu_model_line && trim($cpu_model_line) !== '') {
    $cpu_model = trim($cpu_model_line);
} elseif (is_readable('/proc/cpuinfo')) {
    $ci = @file_get_contents('/proc/cpuinfo');
    if ($ci && preg_match('/^Hardware\s*:\s*(.+)$/m', $ci, $cm)) $cpu_model = trim($cm[1]);
}

$load_percent = min(round(($load[0] / $cpu_count) * 100), 100);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode([
    'load'    => array_map(function($v) { return number_format($v, 2); }, $load),
    'percent' => $load_percent,
    'count'   => $cpu_count,
    'model'   => $cpu_model,
    'ts'      => microtime(true),
]);
