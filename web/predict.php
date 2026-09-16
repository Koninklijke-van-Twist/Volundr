<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/runhours_data.php';

/**
 * Page load
 */
$fetch = runhours_fetch();
$error = '';
$tableHtml = '';
$updated = runhours_updated_label();

if (!($fetch['ok'] ?? false)) {
    $error = (string) ($fetch['error'] ?? 'Kon data niet laden.');
} else {
    $processed = runhours_process_rows($fetch['data'] ?? []);
    $headers = [
        'Set',
        'Model',
        'Extra Naam',
        'Totaal run hours',
        'Volgende check op',
        'Interval',
        'Vorige onderhoudsdatum',
        'Verwachte onderhoudsdatum',
        '',
    ];
    $tableHtml = runhours_render_table('connect-data-table', $headers, runhours_predictions_table($processed));
    try {
        $updated = runhours_updated_label(new DateTimeImmutable((string) ($fetch['fetched_at'] ?? 'now')));
    } catch (Throwable $e) {
        $updated = runhours_updated_label();
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QVT 101Connect — Onderhoud</title>
    <meta name="description" content="QVT 101Connect — onderhoudsvoorspellingen">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="favicon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <link rel="stylesheet" href="assets/runhours.css">
</head>
<body>
<div class="qvt-page">
    <header class="qvt-header">
        <img src="logo-website.png" alt="KVT" class="qvt-logo">
        <h1 class="brand-display">101Connect</h1>
    </header>

    <div class="box" id="updated"><article><?= runhours_h($updated) ?></article></div>

    <?php if ($error !== ''): ?>
        <div class="qvt-alert"><?= runhours_h($error) ?></div>
    <?php else: ?>
        <div class="qvt-toolbar">
            <label for="filterInput">Filter:</label>
            <input type="text" id="filterInput" placeholder="Zoeken...">
            <a class="qvt-btn contract-nav" href="index.php">Bekijk actuele waardes</a>
        </div>
        <?= $tableHtml ?>
        <div class="qvt-modal" id="maintenanceModal" hidden>
            <div class="qvt-modal-backdrop" data-close-modal></div>
            <div class="qvt-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="maintenanceModalTitle">
                <h2 id="maintenanceModalTitle">Onderhoud bevestigen</h2>
                <p class="qvt-modal-motor" id="maintenanceModalMotor"></p>
                <p>
                    Bevestig dat onderhoud is uitgevoerd op de onderstaande datum.
                    De <strong>huidige draaiuren</strong> worden als nieuw startpunt voor de onderhoudstimer opgeslagen.
                </p>
                <label class="qvt-modal-label" for="maintenanceDate">Onderhoudsdatum</label>
                <input type="date" id="maintenanceDate" max="<?= runhours_h(runhours_today_amsterdam()) ?>">
                <p class="qvt-modal-note" id="maintenanceModalHours"></p>
                <p class="qvt-modal-note" id="maintenanceModalExpected"></p>
                <p class="qvt-modal-error" id="maintenanceModalError" hidden></p>
                <div class="qvt-modal-actions">
                    <button type="button" class="qvt-btn qvt-btn-secondary" data-close-modal>Annuleren</button>
                    <button type="button" class="qvt-btn" id="maintenanceConfirmBtn">Opslaan</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="assets/runhours.js" defer></script>
</body>
</html>
