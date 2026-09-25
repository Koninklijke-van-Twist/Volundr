<?php

/**
 * Hourly job: incrementele update van runtime-history + table cache (101Connect).
 * Geen BC-OData vandaag — VOLUNDR_HOURLY_MAX_AGE (1800) gereserveerd voor Mímir.
 * Wordt extern via GET/CLI aangeroepen.
 */

@ini_set('display_errors', '1');
@ini_set('max_execution_time', '180');
if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/runhours_data.php';

runhours_cron_require_trusted();

runhours_cron_log('[' . gmdate('Y-m-d H:i:s') . "] 101Connect hourly gestart\n");

$result = runhours_refresh_incremental();
if (!($result['ok'] ?? false)) {
    runhours_cron_log('Fout: ' . (string) ($result['error'] ?? 'onbekend') . "\n", true);
    runhours_cron_exit(1);
}

runhours_cron_log(
    'History bijgewerkt: ' . (int) ($result['rows'] ?? 0)
    . ' rijen (fetched_at=' . (string) ($result['fetched_at'] ?? '') . ")\n"
);
runhours_cron_exit(0);
