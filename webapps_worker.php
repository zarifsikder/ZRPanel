<?php
/**
 * ZRPanel Web Apps — async build worker.
 *
 * Usage: php webapps_worker.php <app_id>
 * Runs install + build for the app in the background and reports progress
 * through the build_status column and the app's build.log.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/webapps.php';

$appId = (int)($argv[1] ?? 0);
if ($appId <= 0) {
    fwrite(STDERR, "Usage: php webapps_worker.php <app_id>\n");
    exit(1);
}

wa_build_worker($appId);
