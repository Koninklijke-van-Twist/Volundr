<?php

/**
 * Nightly job: wekelijks mailrapport (alleen op maandag; anders skip).
 * Data komt uit de cache (gevuld door hourly.php).
 * Mímir: doet géén BC-OData — VOLUNDR_NIGHTLY_MAX_AGE (14400) gereserveerd.
 *
 * Wordt extern via GET/CLI aangeroepen.
 */

@ini_set('display_errors', '1');
@ini_set('max_execution_time', '120');
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/runhours_data.php';

runhours_cron_require_trusted();

$force = false;
if (PHP_SAPI === 'cli') {
    global $argv;
    $force = in_array('--force', $argv ?? [], true);
} else {
    $force = isset($_GET['force']) && (string) $_GET['force'] === '1';
}

runhours_cron_log('[' . gmdate('Y-m-d H:i:s') . "] 101Connect nightly gestart\n");

$result = runhours_send_weekly_report_if_due($force);

if (!($result['ok'] ?? false)) {
    runhours_cron_log('Fout bij weekrapport: ' . (string) ($result['error'] ?? 'onbekend') . "\n", true);
    runhours_cron_exit(1);
}

if ($result['skipped'] ?? false) {
    runhours_cron_log('Weekrapport overgeslagen: ' . (string) ($result['reason'] ?? '') . "\n");
    runhours_cron_exit(0);
}

runhours_cron_log('Weekrapport verstuurd: ' . (string) ($result['subject'] ?? '') . "\n");
runhours_cron_exit(0);
