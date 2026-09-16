<?php

/**
 * 101Connect run hours — data ophalen, formatteren, voorspellen, e-mail.
 */

/**
 * Constants
 */
const RUNHOURS_INITIAL_CHECK_HOURS = 500;
const RUNHOURS_MONTHS_NL = [
    'januari', 'februari', 'maart', 'april', 'mei', 'juni',
    'juli', 'augustus', 'september', 'oktober', 'november', 'december',
];

/**
 * Functies
 */

function runhours_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function runhours_cache_dir(): string
{
    $dir = __DIR__ . '/cache/runhours';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function runhours_today_amsterdam(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}

function runhours_maintenance_path(): string
{
    return __DIR__ . '/data/maintenance.json';
}

function runhours_maintenance_ensure_writable(): void
{
    $dir = dirname(runhours_maintenance_path());
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Kon data-map niet aanmaken.');
    }
    @chmod($dir, 0777);

    $path = runhours_maintenance_path();
    if (!is_file($path)) {
        if (@file_put_contents($path, "{}") !== false) {
            @chmod($path, 0666);
        }
    } elseif (!is_writable($path)) {
        @chmod($path, 0666);
    }

    if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
        throw new RuntimeException('Onderhoudsdata is niet schrijfbaar.');
    }
}

/**
 * @return array<string, array{hours:float, date:string, saved_at?:string}>
 */
function runhours_maintenance_all(): array
{
    $path = runhours_maintenance_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

function runhours_motor_key(array $row): string
{
    return implode('|', [
        trim((string) ($row['imei'] ?? '')),
        trim((string) ($row['setup_name'] ?? '')),
        trim((string) ($row['name'] ?? '')),
        trim((string) ($row['additionalName'] ?? '')),
    ]);
}

function runhours_maintenance_record(array $item, array $all): ?array
{
    $key = runhours_motor_key($item);
    if ($key === '' || $key === '|||') {
        return null;
    }

    $record = $all[$key] ?? null;
    if (!is_array($record) || !isset($record['hours'], $record['date']) || !is_numeric($record['hours'])) {
        return null;
    }

    return $record;
}

function runhours_last_maintenance_hours(?array $record): ?float
{
    if ($record === null || !isset($record['hours']) || !is_numeric($record['hours'])) {
        return null;
    }

    return (float) $record['hours'];
}

function runhours_format_hours_brief(float $hours): string
{
    $rounded = round($hours);
    if (abs($hours - $rounded) < 0.05) {
        return ((int) $rounded) . ' u';
    }

    return str_replace('.', ',', sprintf('%.1f', round($hours, 1))) . ' u';
}

function runhours_previous_maintenance_label(?array $record): string
{
    if ($record === null) {
        return '—';
    }

    $date = trim((string) ($record['date'] ?? ''));
    $hours = $record['hours'] ?? null;
    if ($date === '' || !is_numeric($hours)) {
        return '—';
    }

    return $date . ' (' . runhours_format_hours_brief((float) $hours) . ')';
}

function runhours_valid_iso_date(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return false;
    }

    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * @return array{hours:float, date:string, saved_at:string}
 */
function runhours_maintenance_store(string $motorId, float $hours, string $date): array
{
    runhours_maintenance_ensure_writable();
    $path = runhours_maintenance_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Kon onderhoudsdata niet openen.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Kon onderhoudsdata niet vergrendelen.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $all = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($all)) {
            $all = [];
        }

        $record = [
            'hours' => $hours,
            'date' => $date,
            'saved_at' => gmdate('c'),
        ];
        $all[$motorId] = $record;

        $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Kon onderhoudsdata niet coderen.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);

        return $record;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function runhours_find_processed_motor(string $motorId): ?array
{
    $fetch = runhours_fetch();
    if (!($fetch['ok'] ?? false)) {
        return null;
    }

    foreach (runhours_process_rows($fetch['data'] ?? []) as $item) {
        if (runhours_motor_key($item) === $motorId) {
            return $item;
        }
    }

    return null;
}

function runhours_api_config(): array
{
    global $runhoursApi;

    return is_array($runhoursApi ?? null) ? $runhoursApi : [];
}

function runhours_mail_config(): array
{
    global $runhoursMail, $reportMail;

    $mail = is_array($runhoursMail ?? null) ? $runhoursMail : [];
    if (($mail['from_email'] ?? '') === '' && is_array($reportMail ?? null)) {
        $mail['from_email'] = (string) ($reportMail['from_email'] ?? 'kvtbot@kvt.nl');
        $mail['from_name'] = (string) ($reportMail['from_name'] ?? ($mail['from_name'] ?? '101Connect'));
    }

    return $mail;
}

/**
 * Leest de laatste table-cache (geen live API-call).
 * Live updates gebeuren alleen via hourly.php → runhours_refresh_incremental().
 *
 * @return array{ok:bool, data?:list<array>, error?:string, fetched_at?:string}
 */
function runhours_fetch(): array
{
    $cachePath = runhours_cache_dir() . '/table.json';
    if (!is_file($cachePath)) {
        return [
            'ok' => false,
            'error' => 'Nog geen data-cache. Wacht op de hourly-update of start hourly.php eenmaal handmatig.',
        ];
    }

    $raw = @file_get_contents($cachePath);
    $cached = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cached) || !isset($cached['data']) || !is_array($cached['data'])) {
        return [
            'ok' => false,
            'error' => 'Data-cache is ongeldig. Draai hourly.php om opnieuw te vullen.',
        ];
    }

    return [
        'ok' => true,
        'data' => $cached['data'],
        'fetched_at' => (string) ($cached['fetched_at'] ?? gmdate('c')),
    ];
}

/**
 * Forceert een incrementele history-update (live 101Connect XML → runtime-history + table cache).
 * Alleen bedoeld voor hourly.php (of handmatige refresh).
 *
 * @return array{ok:bool, data?:list<array>, error?:string, fetched_at?:string, rows?:int}
 */
function runhours_refresh_incremental(): array
{
    $config = runhours_api_config();
    $useMock = !empty($config['use_mock']);

    if (!function_exists('connect_runhours_build')) {
        require_once __DIR__ . '/api.php';
    }

    $result = connect_runhours_build($useMock);
    if (!($result['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Kon run hours niet ophalen.'),
        ];
    }

    $rows = $result['data'] ?? [];
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'API-antwoord mist een rijenlijst.'];
    }

    $fetchedAt = gmdate('c');
    if (!$useMock) {
        $cachePath = runhours_cache_dir() . '/table.json';
        @file_put_contents($cachePath, json_encode([
            'saved_at' => time(),
            'fetched_at' => $fetchedAt,
            'data' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return [
        'ok' => true,
        'data' => $rows,
        'fetched_at' => $fetchedAt,
        'rows' => count($rows),
    ];
}

function runhours_cron_log(string $message, bool $isError = false): void
{
    if (PHP_SAPI === 'cli') {
        $stream = $isError
            ? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'))
            : (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        if (is_resource($stream)) {
            fwrite($stream, $message);
        }

        return;
    }

    if ($isError) {
        error_log(rtrim($message));
    }

    echo $message;
    if (function_exists('flush')) {
        @flush();
    }
}

function runhours_cron_exit(int $code): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code === 0 ? 200 : 500);
    }

    exit($code);
}

function runhours_cron_require_trusted(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');
    require_once __DIR__ . '/logincheck.php';
    if (!is_trusted_requester()) {
        runhours_cron_log("Alleen trusted requester of CLI mag deze job starten.\n", true);
        runhours_cron_exit(1);
    }
}

function runhours_parse_hours(mixed $totalHours, bool $isTotal = false): string
{
    if ($totalHours === 0 || $totalHours === 0.0 || $totalHours === '0') {
        return '-';
    }
    if ($totalHours === null || $totalHours === '') {
        return $isTotal ? 'Geen data ontvangen' : '-';
    }
    if (!is_numeric($totalHours)) {
        return $isTotal ? 'Geen data ontvangen' : '-';
    }

    $totalMinutes = (int) round((float) $totalHours * 60);
    $runhours = intdiv($totalMinutes, 60);
    $runminutes = $totalMinutes % 60;

    if ($runminutes === 0) {
        return $runhours . ' uur';
    }
    if ($runhours === 0) {
        return $runminutes . ' minuten';
    }

    return $runhours . ' uur, ' . $runminutes . ' minuten';
}

function runhours_format_last_contact(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return '-';
    }

    $months = RUNHOURS_MONTHS_NL;
    $day = (int) $date->format('j');
    $month = $months[(int) $date->format('n') - 1] ?? $date->format('m');
    $year = $date->format('Y');
    $hours = $date->format('H');
    $minutes = $date->format('i');

    return $day . ' ' . $month . ' ' . $year . ', ' . $hours . ':' . $minutes;
}

function runhours_updated_label(?DateTimeInterface $when = null): string
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $date = $when instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($when)->setTimezone($tz)
        : new DateTimeImmutable('now', $tz);

    $months = RUNHOURS_MONTHS_NL;
    $day = (int) $date->format('j');
    $month = $months[(int) $date->format('n') - 1] ?? $date->format('m');
    $year = $date->format('Y');
    $hours = $date->format('H');
    $minutes = $date->format('i');

    return 'Laatste meetwaardes opgehaald op: ' . $day . ' ' . $month . ' ' . $year . ', ' . $hours . ':' . $minutes;
}

/**
 * @param list<array> $rows
 * @return list<array>
 */
function runhours_process_rows(array $rows): array
{
    $processed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $current = $row['current_run_hours'] ?? null;
        $daily = $row['avg_daily_7d'] ?? null;
        $weekly = $row['avg_weekly_4w'] ?? null;
        $monthly = $row['avg_monthly_12m'] ?? null;

        $processed[] = [
            'setup_name' => (string) ($row['setup_name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'additionalName' => (string) ($row['additionalName'] ?? ''),
            'imei' => (string) ($row['imei'] ?? ''),
            'last_contact' => runhours_format_last_contact($row['last_contact'] ?? null),
            'current_run_hours_raw' => is_numeric($current) ? (float) $current : null,
            'current_run_hours' => runhours_parse_hours($current, true),
            'avg_daily_7d_raw' => is_numeric($daily) ? (float) $daily : null,
            'avg_weekly_4w_raw' => is_numeric($weekly) ? (float) $weekly : null,
            'avg_monthly_12m_raw' => is_numeric($monthly) ? (float) $monthly : null,
            'avg_daily_7d' => runhours_parse_hours($daily),
            'avg_weekly_4w' => runhours_parse_hours($weekly),
            'avg_monthly_12m' => runhours_parse_hours($monthly),
            'full_week_measured' => (bool) ($row['full_week_measured'] ?? false),
            'full_month_measured' => (bool) ($row['full_month_measured'] ?? false),
            'full_year_measured' => (bool) ($row['full_year_measured'] ?? false),
        ];
    }

    usort($processed, static function (array $a, array $b): int {
        return strcoll($a['setup_name'], $b['setup_name']);
    });

    return $processed;
}

function runhours_imprecise_html(?string $text, bool $isImprecise, string $tooltip): string
{
    $display = $text !== null && $text !== '' ? $text : 'Data nog niet beschikbaar';
    if ($isImprecise && $text !== null && $text !== '-' && $text !== '') {
        return '<imprecise>' . $display
            . '<span class="tooltiptext">' . runhours_h($tooltip) . '</span></imprecise>';
    }

    return $display;
}

/**
 * @param list<array> $processed
 * @return list<array{cells:list<array{text:string,html:bool}>, sort:list<string>}>
 */
function runhours_averages_table(array $processed): array
{
    $tooltip = 'Meetwaardes voor dit object worden nog verzameld. Berekende waardes zijn mogelijk niet accuraat.';
    $rows = [];

    foreach ($processed as $item) {
        $daily = runhours_imprecise_html(
            (string) $item['avg_daily_7d'],
            !(bool) $item['full_week_measured'],
            $tooltip
        );
        $weekly = runhours_imprecise_html(
            (string) $item['avg_weekly_4w'],
            !(bool) $item['full_month_measured'],
            $tooltip
        );
        $monthly = runhours_imprecise_html(
            (string) $item['avg_monthly_12m'],
            !(bool) $item['full_year_measured'],
            $tooltip
        );

        $cells = [
            ['text' => (string) $item['setup_name'], 'html' => false],
            ['text' => (string) $item['name'], 'html' => false],
            ['text' => (string) $item['additionalName'], 'html' => false],
            ['text' => (string) $item['last_contact'], 'html' => false],
            ['text' => (string) $item['current_run_hours'], 'html' => false],
            ['text' => $daily, 'html' => true],
            ['text' => $weekly, 'html' => true],
            ['text' => $monthly, 'html' => true],
        ];

        $rows[] = [
            'cells' => $cells,
            'sort' => array_map(static fn (array $c): string => strip_tags($c['text']), $cells),
        ];
    }

    return $rows;
}

function runhours_parse_interval(string $additionalName): array
{
    if (str_contains($additionalName, '/')) {
        [$serial, $intervalRaw] = explode('/', $additionalName, 2);

        return [
            'extra_name' => $serial,
            'interval' => (int) $intervalRaw,
        ];
    }

    return [
        'extra_name' => $additionalName,
        'interval' => 0,
    ];
}

function runhours_weighted_average_per_day(?float $daily, ?float $weekly, ?float $monthly): float
{
    $d = (float) ($daily ?? 0);
    $w = (float) ($weekly ?? 0);
    $m = (float) ($monthly ?? 0);

    return ($d * 0.5) + (($w / 7) * 0.3) + (($m / 30) * 0.2);
}

function runhours_next_check_hours(float $currentHours, int $interval, ?float $lastMaintenanceHours = null): int
{
    if ($lastMaintenanceHours !== null && $interval > 0) {
        return (int) round($lastMaintenanceHours + $interval);
    }

    if ($currentHours < RUNHOURS_INITIAL_CHECK_HOURS) {
        return RUNHOURS_INITIAL_CHECK_HOURS;
    }

    return (int) (ceil($currentHours / $interval) * $interval);
}

function runhours_iso_week_number(DateTimeInterface $date): int
{
    return (int) $date->format('W');
}

function runhours_format_date_nl(DateTimeInterface $date): string
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $local = DateTimeImmutable::createFromInterface($date)->setTimezone($tz);
    $months = RUNHOURS_MONTHS_NL;
    $day = (int) $local->format('j');
    $month = $months[(int) $local->format('n') - 1] ?? $local->format('m');
    $year = $local->format('Y');

    return $day . ' ' . $month . ' ' . $year;
}

function runhours_parse_dutch_date(string $str): ?int
{
    $datePart = explode(', ', $str)[0] ?? $str;
    $parts = preg_split('/\s+/', trim($datePart)) ?: [];
    if (count($parts) < 3) {
        return null;
    }

    [$day, $monthName, $year] = $parts;
    $monthIndex = array_search(strtolower($monthName), RUNHOURS_MONTHS_NL, true);
    if ($monthIndex === false) {
        return null;
    }

    return (int) mktime(0, 0, 0, ((int) $monthIndex) + 1, (int) $day, (int) $year);
}

/**
 * @return array{date:string,week:int,datetime:DateTimeImmutable}|null
 */
function runhours_predict_date(
    float $currentHours,
    int $interval,
    ?float $daily,
    ?float $weekly,
    ?float $monthly,
    ?float $lastMaintenanceHours = null
): ?array {
    $avgPerDay = runhours_weighted_average_per_day($daily, $weekly, $monthly);
    if ($avgPerDay <= 0) {
        return null;
    }

    $targetHours = runhours_next_check_hours($currentHours, $interval, $lastMaintenanceHours);
    $hoursRemaining = $targetHours - $currentHours;
    $daysRemaining = $hoursRemaining / $avgPerDay;
    $seconds = (int) round($daysRemaining * 86400);
    $targetDate = (new DateTimeImmutable('now'))->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');

    return [
        'date' => runhours_format_date_nl($targetDate),
        'week' => runhours_iso_week_number($targetDate),
        'datetime' => $targetDate,
    ];
}

function runhours_overdue_row_class(?DateTimeInterface $expectedAt): string
{
    if ($expectedAt === null) {
        return '';
    }

    $now = new DateTimeImmutable('now');
    $target = DateTimeImmutable::createFromInterface($expectedAt);
    if ($now >= $target->modify('+1 month')) {
        return 'overdue-month';
    }
    if ($now >= $target->modify('+7 days')) {
        return 'overdue-week';
    }

    return '';
}

/**
 * @param list<array> $processed
 * @return list<array{cells:list<array{text:string,html:bool}>, sort:list<string>}>
 */
function runhours_predictions_table(array $processed): array
{
    $tooltip = 'Meetwaardes voor dit object worden nog verzameld. Geschatte tijden zijn mogelijk niet accuraat.';
    $maintenanceAll = runhours_maintenance_all();
    $today = runhours_today_amsterdam();
    $rows = [];

    foreach ($processed as $item) {
        $parsed = runhours_parse_interval((string) $item['additionalName']);
        $interval = (int) $parsed['interval'];
        if ($interval <= 0) {
            continue;
        }

        $runhours = $item['current_run_hours_raw'];
        if ($runhours === null) {
            continue;
        }

        $record = runhours_maintenance_record($item, $maintenanceAll);
        $lastHours = runhours_last_maintenance_hours($record);
        $nextHours = runhours_next_check_hours((float) $runhours, $interval, $lastHours);
        $firstCheck = $lastHours === null && ((float) $runhours) < RUNHOURS_INITIAL_CHECK_HOURS;
        $nextLabel = $nextHours . ' uur' . ($firstCheck ? ' (eerste check)' : '');
        $prediction = runhours_predict_date(
            (float) $runhours,
            $interval,
            $item['avg_daily_7d_raw'],
            $item['avg_weekly_4w_raw'],
            $item['avg_monthly_12m_raw'],
            $lastHours
        );

        $fullYear = (bool) $item['full_year_measured'];
        $expectedAt = null;
        if ($prediction === null) {
            $dateHtml = 'Voorspelling nog niet beschikbaar';
            $sortDate = null;
            $expectedLabel = '';
        } else {
            $label = $prediction['date'] . ' (Week ' . $prediction['week'] . ')';
            $dateHtml = (!$fullYear)
                ? '<imprecise>' . $label . '<span class="tooltiptext">' . runhours_h($tooltip) . '</span></imprecise>'
                : $label;
            $sortDate = $prediction['date'];
            $expectedAt = $prediction['datetime'] ?? null;
            $expectedLabel = $prediction['date'];
        }

        $motorId = runhours_motor_key($item);
        $motorLabelParts = array_filter([
            trim((string) $item['setup_name']),
            trim((string) $item['name']),
            trim((string) $parsed['extra_name']),
        ], static fn (string $part): bool => $part !== '');
        $motorLabel = implode(' — ', $motorLabelParts);
        $afterConfirmNext = (int) round((float) $runhours + $interval);
        $buttonHtml = '<button type="button" class="qvt-btn qvt-btn-compact js-maintenance-done"'
            . ' data-motor-id="' . runhours_h($motorId) . '"'
            . ' data-motor-label="' . runhours_h($motorLabel) . '"'
            . ' data-hours="' . runhours_h((string) $runhours) . '"'
            . ' data-hours-label="' . runhours_h((string) $item['current_run_hours']) . '"'
            . ' data-interval="' . $interval . '"'
            . ' data-next-hours="' . $afterConfirmNext . '"'
            . ' data-default-date="' . runhours_h($today) . '"'
            . ' data-expected-date="' . runhours_h($expectedLabel) . '">'
            . 'Onderhoud Uitgevoerd</button>';

        $cells = [
            ['text' => (string) $item['setup_name'], 'html' => false],
            ['text' => (string) $item['name'], 'html' => false],
            ['text' => (string) $parsed['extra_name'], 'html' => false],
            ['text' => (string) $item['current_run_hours'], 'html' => false],
            ['text' => $nextLabel, 'html' => false],
            ['text' => 'Elke ' . $interval . ' uur', 'html' => false],
            ['text' => runhours_previous_maintenance_label($record), 'html' => false],
            ['text' => $dateHtml, 'html' => true],
            ['text' => $buttonHtml, 'html' => true],
        ];

        $rows[] = [
            'cells' => $cells,
            'row_class' => runhours_overdue_row_class($expectedAt instanceof DateTimeInterface ? $expectedAt : null),
            'sort' => array_map(static fn (array $c): string => strip_tags($c['text']), $cells),
            'sort_date' => $sortDate,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $da = $a['sort_date'] ?? null;
        $db = $b['sort_date'] ?? null;
        if ($da === null) {
            return 1;
        }
        if ($db === null) {
            return -1;
        }
        $ta = runhours_parse_dutch_date((string) $da);
        $tb = runhours_parse_dutch_date((string) $db);
        if ($ta === null) {
            return 1;
        }
        if ($tb === null) {
            return -1;
        }

        return $ta <=> $tb;
    });

    foreach ($rows as &$row) {
        unset($row['sort_date']);
    }
    unset($row);

    return $rows;
}

/**
 * @param list<string> $headers
 * @param list<array{cells:list<array{text:string,html:bool}>}> $rows
 */
function runhours_render_table(string $tableId, array $headers, array $rows): string
{
    $html = '<div id="connect-data"><table id="' . runhours_h($tableId) . '"><thead><tr>';
    foreach ($headers as $index => $header) {
        $col = $index + 1;
        $isAction = trim($header) === '';
        $thClass = $isAction ? ' class="no-sort"' : '';
        $aria = $isAction ? ' aria-label="Actie"' : '';
        $html .= '<th data-col-index="' . $col . '" data-col-name="' . runhours_h($header) . '"'
            . $thClass . $aria . '>' . runhours_h($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $rIndex => $row) {
        $rowNum = $rIndex + 1;
        $extraClass = trim((string) ($row['row_class'] ?? ''));
        $trClass = 'r' . $rowNum . ($extraClass !== '' ? ' ' . $extraClass : '');
        $html .= '<tr class="' . runhours_h($trClass) . '">';
        foreach ($row['cells'] as $cIndex => $cell) {
            $col = $cIndex + 1;
            $name = $headers[$cIndex] ?? '';
            $content = !empty($cell['html']) ? (string) $cell['text'] : runhours_h((string) $cell['text']);
            $html .= '<td data-col-index="' . $col . '" data-col-name="' . runhours_h($name) . '"'
                . ' class="r' . $rowNum . ' c' . $col . '">' . $content . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    return $html;
}

function runhours_imprecise_email(string $text, bool $isImprecise): string
{
    if ($isImprecise && $text !== '' && $text !== '-') {
        return '<imprecise ' . runhours_imprecise_inline_style() . '>' . $text;
    }

    return $text !== '' ? $text : 'Data nog niet beschikbaar';
}

function runhours_imprecise_inline_style(): string
{
    return 'style="position:relative;display:inline-block;cursor:pointer;background-image:url(\'data:image/svg+xml;utf8,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;20&quot; height=&quot;4&quot; viewBox=&quot;0 0 20 6&quot;><path fill=&quot;none&quot; stroke=&quot;orange&quot; stroke-width=&quot;1&quot; d=&quot;M0 3 Q2.5 0 5 3 T10 3 T15 3 T20 3&quot;/></svg>\');background-repeat:repeat-x;background-position:0 100%;background-size:20px 6px;padding-bottom:6px;"';
}

/**
 * @param list<array> $processed
 * @return array{subject:string, html:string}
 */
function runhours_build_email(array $processed): array
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $now = new DateTimeImmutable('now', $tz);
    $months = RUNHOURS_MONTHS_NL;
    $day = (int) $now->format('j');
    $month = $months[(int) $now->format('n') - 1] ?? $now->format('m');
    $year = $now->format('Y');
    $dateLabel = $day . ' ' . $month . ' ' . $year;

    $mailRows = [];
    $maintenanceAll = runhours_maintenance_all();
    foreach ($processed as $item) {
        $parsed = runhours_parse_interval((string) $item['additionalName']);
        $interval = (int) $parsed['interval'];
        $runhours = $item['current_run_hours_raw'];
        $record = runhours_maintenance_record($item, $maintenanceAll);
        $lastHours = runhours_last_maintenance_hours($record);

        $row = [
            'Set' => (string) $item['setup_name'],
            'Model' => (string) $item['name'],
            'Extra Naam' => (string) $parsed['extra_name'],
            'Totaal run hours' => (string) $item['current_run_hours'],
            'Dagelijks gemiddelde (7d)' => runhours_imprecise_email(
                (string) $item['avg_daily_7d'],
                !(bool) $item['full_week_measured']
            ),
            'Wekelijks gemiddelde (4w)' => runhours_imprecise_email(
                (string) $item['avg_weekly_4w'],
                !(bool) $item['full_month_measured']
            ),
            'Maandelijks gemiddelde (12m)' => runhours_imprecise_email(
                (string) $item['avg_monthly_12m'],
                !(bool) $item['full_year_measured']
            ),
        ];

        if ($interval > 0 && $runhours !== null) {
            $nextHours = runhours_next_check_hours((float) $runhours, $interval, $lastHours);
            $row['Volgende check op'] = $nextHours . ' uur';
            $row['Interval'] = 'Elke ' . $interval . ' uur';
            $row['Vorige onderhoudsdatum'] = runhours_previous_maintenance_label($record);
            $prediction = runhours_predict_date(
                (float) $runhours,
                $interval,
                $item['avg_daily_7d_raw'],
                $item['avg_weekly_4w_raw'],
                $item['avg_monthly_12m_raw'],
                $lastHours
            );
            $fullYear = (bool) $item['full_year_measured'];
            if ($prediction === null) {
                $row['Verwachte onderhoudsdatum'] = 'Voorspelling nog niet beschikbaar';
                $row['_sort'] = null;
            } else {
                $label = $prediction['date'] . ' (Week ' . $prediction['week'] . ')';
                $row['Verwachte onderhoudsdatum'] = (!$fullYear)
                    ? '<imprecise ' . runhours_imprecise_inline_style() . '>' . $label
                    : $label;
                $row['_sort'] = $prediction['date'];
            }
        } else {
            $row['Volgende check op'] = 'N.v.t.';
            $row['Interval'] = 'N.v.t.';
            $row['Vorige onderhoudsdatum'] = runhours_previous_maintenance_label($record);
            $row['Verwachte onderhoudsdatum'] = 'Geen onderhoud';
            $row['_sort'] = null;
        }

        $mailRows[] = $row;
    }

    usort($mailRows, static function (array $a, array $b): int {
        $da = $a['_sort'] ?? null;
        $db = $b['_sort'] ?? null;
        if ($da === null) {
            return 1;
        }
        if ($db === null) {
            return -1;
        }
        $ta = runhours_parse_dutch_date((string) $da);
        $tb = runhours_parse_dutch_date((string) $db);
        if ($ta === null) {
            return 1;
        }
        if ($tb === null) {
            return -1;
        }

        return $ta <=> $tb;
    });

    foreach ($mailRows as &$row) {
        unset($row['_sort']);
    }
    unset($row);

    $html = '<h1>Weekrapport 101Connect</h1><h2>' . runhours_h($dateLabel) . '</h2>';
    $html .= '<b style="color:red;">Let op! Verwachte onderhoudsdatums zijn schattingen! Deze kunnen afwijken van de werkelijkheid.</b><br/>';

    if ($mailRows !== []) {
        $headers = array_keys($mailRows[0]);
        $html .= '<table><tr style="background-color:#024a93; color:white;">';
        foreach ($headers as $header) {
            $html .= '<th>' . runhours_h((string) $header) . '</th>';
        }
        $html .= '</tr>';

        foreach ($mailRows as $i => $row) {
            $bg = ($i % 2 === 0) ? '#68c7e7' : 'white';
            $html .= '<tr style="background-color:' . $bg . '; color:black;">';
            foreach ($row as $value) {
                $html .= '<td>' . $value . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table><br/>';
    }

    $mailCfg = runhours_mail_config();
    $liveUrl = trim((string) ($mailCfg['live_url'] ?? ''));
    if ($liveUrl === '') {
        $liveUrl = 'index.php';
    }
    $html .= 'Bekijk de live versie <a href="' . runhours_h($liveUrl) . '">hier</a>.';

    return [
        'subject' => 'Weekrapport 101Connect - ' . $dateLabel,
        'html' => $html,
    ];
}

function runhours_smtp_settings(): ?array
{
    global $reportMail;
    if (!is_array($reportMail ?? null)) {
        return null;
    }

    $smtp = $reportMail['smtp'] ?? null;
    if (!is_array($smtp)) {
        return null;
    }

    $host = trim((string) ($smtp['host'] ?? ''));
    if ($host === '') {
        return null;
    }

    $timeout = (int) ($smtp['timeout'] ?? ($smtp['ticd meout'] ?? 20));

    return [
        'host' => $host,
        'port' => (int) ($smtp['port'] ?? 587),
        'encryption' => strtolower(trim((string) ($smtp['encryption'] ?? 'tls'))),
        'username' => trim((string) ($smtp['username'] ?? '')),
        'password' => (string) ($smtp['password'] ?? ''),
        'timeout' => max(5, $timeout),
    ];
}

function runhours_smtp_read($socket): string
{
    $data = '';
    while (is_resource($socket) && !feof($socket)) {
        $line = fgets($socket, 8192);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $data;
}

function runhours_smtp_write($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function runhours_smtp_expect(string $response, array $codes): bool
{
    if ($response === '') {
        return false;
    }

    return in_array((int) substr($response, 0, 3), $codes, true);
}

function runhours_smtp_dot_stuff(string $body): string
{
    $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
    $stuffed = [];
    foreach ($lines as $line) {
        if ($line !== '' && $line[0] === '.') {
            $line = '.' . $line;
        }
        $stuffed[] = $line;
    }

    return implode("\r\n", $stuffed);
}

function runhours_send_smtp(
    string $to,
    string $fromEmail,
    string $fromName,
    string $encodedSubject,
    string $mimeHeaders,
    string $body
): bool {
    $smtp = runhours_smtp_settings();
    if ($smtp === null) {
        return false;
    }

    $remote = ($smtp['encryption'] === 'ssl' ? 'ssl://' : 'tcp://')
        . $smtp['host'] . ':' . $smtp['port'];
    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $smtp['timeout'],
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        return false;
    }

    stream_set_timeout($socket, $smtp['timeout']);

    $response = runhours_smtp_read($socket);
    if (!runhours_smtp_expect($response, [220])) {
        fclose($socket);

        return false;
    }

    runhours_smtp_write($socket, 'EHLO 101connect.local');
    $response = runhours_smtp_read($socket);
    if (!runhours_smtp_expect($response, [250])) {
        fclose($socket);

        return false;
    }

    if ($smtp['encryption'] === 'tls') {
        runhours_smtp_write($socket, 'STARTTLS');
        $response = runhours_smtp_read($socket);
        if (!runhours_smtp_expect($response, [220])) {
            fclose($socket);

            return false;
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);

            return false;
        }

        runhours_smtp_write($socket, 'EHLO 101connect.local');
        $response = runhours_smtp_read($socket);
        if (!runhours_smtp_expect($response, [250])) {
            fclose($socket);

            return false;
        }
    }

    if ($smtp['username'] !== '' && $smtp['password'] !== '') {
        runhours_smtp_write($socket, 'AUTH LOGIN');
        $response = runhours_smtp_read($socket);
        if (!runhours_smtp_expect($response, [334])) {
            fclose($socket);

            return false;
        }

        runhours_smtp_write($socket, base64_encode($smtp['username']));
        $response = runhours_smtp_read($socket);
        if (!runhours_smtp_expect($response, [334])) {
            fclose($socket);

            return false;
        }

        runhours_smtp_write($socket, base64_encode($smtp['password']));
        $response = runhours_smtp_read($socket);
        if (!runhours_smtp_expect($response, [235])) {
            fclose($socket);

            return false;
        }
    }

    runhours_smtp_write($socket, 'MAIL FROM:<' . $fromEmail . '>');
    $response = runhours_smtp_read($socket);
    if (!runhours_smtp_expect($response, [250])) {
        fclose($socket);

        return false;
    }

    $recipients = preg_split('/\s*,\s*/', $to) ?: [];
    $anyRecipient = false;
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        runhours_smtp_write($socket, 'RCPT TO:<' . $recipient . '>');
        $response = runhours_smtp_read($socket);
        if (!runhours_smtp_expect($response, [250, 251])) {
            fclose($socket);

            return false;
        }
        $anyRecipient = true;
    }
    if (!$anyRecipient) {
        fclose($socket);

        return false;
    }

    runhours_smtp_write($socket, 'DATA');
    $response = runhours_smtp_read($socket);
    if (!runhours_smtp_expect($response, [354])) {
        fclose($socket);

        return false;
    }

    $payload = 'From: ' . sprintf('%s <%s>', $fromName, $fromEmail) . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . $mimeHeaders . "\r\n\r\n"
        . runhours_smtp_dot_stuff($body);
    fwrite($socket, $payload . "\r\n.\r\n");
    $response = runhours_smtp_read($socket);
    if (!runhours_smtp_expect($response, [250])) {
        fclose($socket);

        return false;
    }

    runhours_smtp_write($socket, 'QUIT');
    fclose($socket);

    return true;
}

function runhours_send_email(string $subject, string $htmlBody): bool
{
    $cfg = runhours_mail_config();
    $to = trim((string) ($cfg['to'] ?? ''));
    if ($to === '') {
        return false;
    }

    $fromEmail = trim((string) ($cfg['from_email'] ?? 'kvtbot@kvt.nl'));
    $fromName = trim((string) ($cfg['from_name'] ?? '101Connect'));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $mimeHeaders = 'Content-Type: text/html; charset=UTF-8';

    if (runhours_smtp_settings() !== null) {
        if (runhours_send_smtp($to, $fromEmail, $fromName, $encodedSubject, $mimeHeaders, $htmlBody)) {
            return true;
        }
    }

    $mailHeaders = "MIME-Version: 1.0\r\n"
        . $mimeHeaders . "\r\n"
        . 'From: ' . sprintf('%s <%s>', $fromName, $fromEmail);

    return @mail($to, $encodedSubject, $htmlBody, $mailHeaders);
}

function runhours_email_marker_path(): string
{
    return runhours_cache_dir() . '/email_last_sent.txt';
}

function runhours_amsterdam_date_key(?DateTimeInterface $when = null): string
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $date = $when instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($when)->setTimezone($tz)
        : new DateTimeImmutable('now', $tz);

    return $date->format('Y-m-d');
}

function runhours_is_monday_amsterdam(?DateTimeInterface $when = null): bool
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $date = $when instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($when)->setTimezone($tz)
        : new DateTimeImmutable('now', $tz);

    return $date->format('N') === '1';
}

/**
 * @return array{ok:bool, skipped?:bool, reason?:string, error?:string, subject?:string}
 */
function runhours_send_weekly_report_if_due(bool $force = false): array
{
    $cfg = runhours_mail_config();
    if (!(bool) ($cfg['enabled'] ?? true)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'Mail uitgeschakeld in config.'];
    }

    if (!$force && !runhours_is_monday_amsterdam()) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'Geen maandag — geen weekrapport.'];
    }

    $todayKey = runhours_amsterdam_date_key();
    $markerPath = runhours_email_marker_path();
    if (!$force && is_file($markerPath)) {
        $last = trim((string) @file_get_contents($markerPath));
        if ($last === $todayKey) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'Weekrapport vandaag al verstuurd.'];
        }
    }

    $fetch = runhours_fetch();
    if (!($fetch['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($fetch['error'] ?? 'Ophalen mislukt.')];
    }

    $processed = runhours_process_rows($fetch['data'] ?? []);
    $email = runhours_build_email($processed);
    if (!runhours_send_email($email['subject'], $email['html'])) {
        return ['ok' => false, 'error' => 'Versturen van weekrapport mislukt.'];
    }

    @file_put_contents($markerPath, $todayKey);

    return ['ok' => true, 'skipped' => false, 'subject' => $email['subject']];
}
