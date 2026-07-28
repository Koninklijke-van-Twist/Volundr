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
        'Laatst gezien',
        'Totaal run hours',
        'Dagelijks gemiddelde (7d)',
        'Wekelijks gemiddelde (4w)',
        'Maandelijks gemiddelde (12m)',
    ];
    $tableHtml = runhours_render_table('connect-data-table', $headers, runhours_averages_table($processed));
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
    <title>Völundr</title>
    <meta name="description" content="QVT 101Connect — actuele run hours">
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
            <a class="qvt-btn contract-nav" href="predict.php">Bekijk onderhoudsvoorspellingen</a>
        </div>
        <?= $tableHtml ?>
    <?php endif; ?>
</div>
<script src="assets/runhours.js" defer></script>
</body>
</html>
