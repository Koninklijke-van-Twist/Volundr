<?php

/**
 * Bevestig uitgevoerde onderhoudsbeurt: slaat huidige draaiuren + datum op.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/runhours_data.php';

function maintenance_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    maintenance_json(['ok' => false, 'error' => 'Alleen POST is toegestaan.'], 405);
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_POST;
}

$motorId = trim((string) ($input['motor_id'] ?? ''));
$date = trim((string) ($input['date'] ?? ''));
if ($date === '') {
    $date = runhours_today_amsterdam();
}

if ($motorId === '') {
    maintenance_json(['ok' => false, 'error' => 'Geen motor opgegeven.'], 400);
}

if (!runhours_valid_iso_date($date)) {
    maintenance_json(['ok' => false, 'error' => 'Ongeldige onderhoudsdatum.'], 400);
}

if ($date > runhours_today_amsterdam()) {
    maintenance_json(['ok' => false, 'error' => 'De onderhoudsdatum mag niet in de toekomst liggen.'], 400);
}

$item = runhours_find_processed_motor($motorId);
if ($item === null) {
    maintenance_json(['ok' => false, 'error' => 'Motor niet gevonden.'], 404);
}

$hours = $item['current_run_hours_raw'] ?? null;
if (!is_numeric($hours)) {
    maintenance_json(['ok' => false, 'error' => 'Geen actuele draaiuren beschikbaar voor deze motor.'], 400);
}

try {
    $record = runhours_maintenance_store($motorId, (float) $hours, $date);
} catch (Throwable $e) {
    maintenance_json(['ok' => false, 'error' => 'Opslaan mislukt: ' . $e->getMessage()], 500);
}

$parsed = runhours_parse_interval((string) ($item['additionalName'] ?? ''));
$interval = (int) ($parsed['interval'] ?? 0);
$nextHours = $interval > 0
    ? runhours_next_check_hours((float) $hours, $interval, (float) $hours)
    : null;

maintenance_json([
    'ok' => true,
    'motor_id' => $motorId,
    'record' => $record,
    'next_hours' => $nextHours,
    'previous_label' => runhours_previous_maintenance_label($record),
]);
