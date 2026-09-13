<?php
class Debug {
    private static array $queries = [];
    private static float $startTime = 0;

    public static function init(): void {
        self::$startTime = microtime(true);
        if (defined('DEBUG_ENABLED') && DEBUG_ENABLED) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }
    }

    public static function log(string $label, string $message = ''): void {
        if (!defined('DEBUG_ENABLED') || !DEBUG_ENABLED) return;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $file = basename($trace['file'] ?? 'unknown');
        $line = $trace['line'] ?? 0;
        $ts = date('H:i:s') . '.' . substr(microtime(), 2, 3);
        $mem = round(memory_get_usage() / 1024);
        error_log("[{$ts}][{$file}:{$line}][{$mem}KB] {$label}: {$message}");
    }

    public static function logError(string $message): void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $file = basename($trace['file'] ?? 'unknown');
        $line = $trace['line'] ?? 0;
        error_log("[ERROR][{$file}:{$line}] {$message}");
    }

    public static function logQuery(string $sql, array $params = [], float $time = 0, int $rows = 0): void {
        if (!defined('DEBUG_ENABLED') || !DEBUG_ENABLED) return;
        self::$queries[] = compact('sql', 'params', 'time', 'rows');
    }

    public static function render(): void {
        if (!defined('DEBUG_ENABLED') || !DEBUG_ENABLED) return;
        $elapsed = round((microtime(true) - self::$startTime) * 1000, 1);
        $mem = round(memory_get_peak_usage() / 1024 / 1024, 2);
        echo '<div style="position:fixed;bottom:0;right:0;background:rgba(0,0,0,.85);color:#0f0;font:11px monospace;padding:8px 12px;z-index:99999;border-radius:6px 0 0;max-height:40vh;overflow:auto">';
        echo "<div><strong>Debug</strong> {$elapsed}ms | {$mem}MB</div>";
        if (self::$queries) {
            echo '<table style="font-size:10px;margin-top:4px;border-collapse:collapse">';
            echo '<tr><th style="padding:2px 6px;border-bottom:1px solid #333">SQL</th><th style="padding:2px 6px;border-bottom:1px solid #333">Time</th><th style="padding:2px 6px;border-bottom:1px solid #333">Rows</th></tr>';
            foreach (self::$queries as $q) {
                echo '<tr>';
                echo '<td style="padding:2px 6px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($q['sql']) . '</td>';
                echo '<td style="padding:2px 6px">' . round($q['time'] * 1000, 1) . 'ms</td>';
                echo '<td style="padding:2px 6px">' . $q['rows'] . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }
}
