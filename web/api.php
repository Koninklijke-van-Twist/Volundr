<?php

/**
 * 101Connect run hours table API.
 * HTTP: retourneert JSON. Inclusie: gebruik connect_runhours_build().
 */

require_once __DIR__ . '/auth.php';

/**
 * Functies
 */

function connect_runhours_history_path(bool $useMock = false): string
{
    $dir = __DIR__ . '/cache/runhours';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/' . ($useMock ? 'mock-runtime-history.json' : 'runtime-history.json');
}

function connect_runhours_config(): array
{
    global $connect101Api;

    return is_array($connect101Api ?? null) ? $connect101Api : [];
}

function connect_runhours_fetch_xml(bool $useMock = false): array
{
    if ($useMock) {
        $xmlString = '<?xml version="1.0" encoding="utf-8"?><response xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><success>true</success><objects><object><id>136240</id><name>Fuenix QVT400</name><imei>359804085215319</imei><lastContact>2024-07-24T18:30:58.397Z</lastContact><variables>        <variable>          <id>597040</id>          <name>Run Hours</name>          <unit>h</unit>          <value>            <id>11774840</id>            <date>2023-05-08T06:12:05.763Z</date>            <value xsi:type="xsd:double">3578</value>          </value>        </variable>      </variables>    </object>    <object>      <id>168140</id>      <name>A3031 - GMB - QVT45 BioGas</name>      <imei>357042065898943</imei>      <lastContact>2025-03-13T11:44:33.940Z</lastContact>      <variables>        <variable>          <id>2966</id>          <name>Run Hours</name>          <unit>h</unit>          <value>            <id>12352240</id>            <date>2025-03-12T14:14:11.817Z</date>            <value xsi:type="xsd:double">3415</value>          </value>        </variable>      </variables>    </object>     <object>      <id>607340</id>      <name>689465</name>      <imei>866442070689465</imei>      <lastContact>2025-02-06T13:26:05.370Z</lastContact>      <variables />      <children>        <object>          <id>799640</id>          <name>Easygen</name>          <imei>866442070689465</imei>          <lastContact>2025-02-06T13:26:05.370Z</lastContact>          <variables>            <variable>              <id>1037740</id>              <name>Gen. hours of operation</name>              <unit>h</unit>              <value>                <id>34052540</id>                <date>1900-01-01T00:00:00.000Z</date>                <value xsi:type="xsd:double">0</value>              </value>            </variable>          </variables>        </object>      </children>    </object>    <object>      <id>829240</id>      <name>Boskalis TwinSet Charger</name>      <imei>866442075172897</imei>      <lastContact>2025-07-24T10:30:16.004Z</lastContact>      <variables />      <children>        <object>          <id>842940</id>          <name>Comap 1</name>          <imei>866442075172897</imei>          <lastContact>2025-07-24T10:30:16.144Z</lastContact>          <variables>            <variable>              <id>1313140</id>              <name>Run Hours </name>              <unit>h</unit>              <value>                <id>35325440</id>                <date>2025-07-24T10:25:12.467Z</date>                <value xsi:type="xsd:double">254.1</value>              </value>            </variable>          </variables>        </object>        <object>          <id>843040</id>          <name>Comap 2</name>          <imei>866442075172897</imei>          <lastContact>2025-07-24T10:30:16.269Z</lastContact>          <variables>            <variable>              <id>1313140</id>              <name>Run Hours </name>              <unit>h</unit>              <value>                <id>35329640</id>                <date>2025-07-24T10:28:11.337Z</date>                <value xsi:type="xsd:double">210.1</value>              </value>            </variable>          </variables>        </object>      </children>    </object>    <object>      <id>889040</id>      <name>273524</name>      <imei>866442072273524</imei>      <lastContact>2025-04-29T08:39:36.953Z</lastContact>      <variables />    </object>    <object>      <id>908940</id>      <name>428690</name>      <imei>860147055428690</imei>      <lastContact>2025-07-15T01:05:35.223Z</lastContact>      <variables />    </object>    <object>      <id>932240</id>      <name>893812</name>      <imei>868140076893812</imei>      <lastContact>2025-07-16T08:11:44.043Z</lastContact>      <variables />    </object>  </objects></response>';

        return ['ok' => true, 'xml' => $xmlString];
    }

    $config = connect_runhours_config();
    $url = trim((string) ($config['url'] ?? 'https://API.101connect.nl/objectvalue/getvalues'));
    $token = trim((string) ($config['access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => '101Connect access_token ontbreekt in auth.php ($connect101Api).'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Access-Token {$token}\r\nAccept: application/xml\r\n",
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false || $response === '') {
        return ['ok' => false, 'error' => 'Failed to retrieve XML from API'];
    }

    return ['ok' => true, 'xml' => $response];
}

function connect_runhours_is_runhours_variable(string $varName): bool
{
    $normalized = strtolower(preg_replace('/[\s_\-]+/', '', $varName) ?? $varName);

    return stripos($varName, 'run hours') !== false
        || stripos($varName, 'hours of operation') !== false
        || stripos($varName, 'engine run time') !== false
        || stripos($varName, 'running hours') !== false
        || str_contains($normalized, 'enginerunhours')
        || str_contains($normalized, 'runninghours');
}

function connect_runhours_parse_value(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }

    $hours = (float) $value;
    // Comap Modbus OC stuurt soms INT_MIN bij ongeldige waarde.
    if ($hours < 0 || $hours >= 2147480000) {
        return null;
    }

    return $hours;
}

function connect_runhours_process_object(
    object $obj,
    array &$flatList,
    array &$history,
    string $today,
    ?string $setupName = null,
    string $additionalNameIfBlank = '',
    bool $forceShow = false
): void {
    $name = trim((string) ($obj->name ?? ''));
    $additionalName = trim((string) ($obj->additionalName ?? $additionalNameIfBlank));
    $imei = trim((string) ($obj->imei ?? ''));
    $id = trim((string) ($obj->id ?? $imei));
    $lastContact = trim((string) ($obj->lastContact ?? ''));
    $runHours = null;

    if (isset($obj->variables->variable)) {
        $variables = $obj->variables->variable;
        if (!is_array($variables)) {
            $variables = [$variables];
        }

        foreach ($variables as $var) {
            $varName = trim((string) ($var->name ?? ''));
            if (connect_runhours_is_runhours_variable($varName)) {
                $runHours = connect_runhours_parse_value($var->value->value ?? null);
                break;
            }
        }
    }

    if (!$name || !$imei || $runHours === null) {
        if (!$forceShow) {
            return;
        }
    }

    if (!isset($history[$id])) {
        $history[$id] = [];
    }

    $history[$id][$today] = $runHours;

    $history[$id] = array_filter(
        $history[$id],
        static function ($date) {
            return strtotime((string) $date) >= strtotime('-1 year');
        },
        ARRAY_FILTER_USE_KEY
    );

    ksort($history[$id]);

    $dates = array_keys($history[$id]);
    $values = array_values($history[$id]);

    $deltas = [];
    for ($i = 1; $i < count($dates); $i++) {
        $prev = $values[$i - 1];
        $curr = $values[$i];
        $date = $dates[$i];
        $delta = $curr - $prev;
        if ($delta >= 0) {
            $deltas[$date] = $delta;
        }
    }

    $last7Days = array_filter(
        $deltas,
        static fn ($k) => strtotime((string) $k) >= strtotime('-7 days'),
        ARRAY_FILTER_USE_KEY
    );
    $last4Weeks = array_filter(
        $deltas,
        static fn ($k) => strtotime((string) $k) >= strtotime('-28 days'),
        ARRAY_FILTER_USE_KEY
    );
    $last12Months = array_filter(
        $deltas,
        static fn ($k) => strtotime((string) $k) >= strtotime('-1 year'),
        ARRAY_FILTER_USE_KEY
    );

    $dailyAvg7 = count($last7Days) ? array_sum($last7Days) / count($last7Days) : null;
    $weeklyAvg4 = count($last4Weeks) ? array_sum($last4Weeks) / 4 : null;
    $monthlyAvg12 = count($last12Months) ? array_sum($last12Months) / 12 : null;

    $daysMeasured = count($values);

    $flatList[] = [
        'setup_name' => $setupName,
        'name' => $name,
        'additionalName' => $additionalName,
        'imei' => $imei,
        'last_contact' => $lastContact,
        'current_run_hours' => $runHours,
        'avg_daily_7d' => $dailyAvg7 !== null ? round($dailyAvg7, 2) : null,
        'avg_weekly_4w' => $weeklyAvg4 !== null ? round($weeklyAvg4, 2) : null,
        'avg_monthly_12m' => $monthlyAvg12 !== null ? round($monthlyAvg12, 2) : null,
        'full_week_measured' => $daysMeasured >= 7,
        'full_month_measured' => $daysMeasured >= 30,
        'full_year_measured' => $daysMeasured >= 365,
    ];
}

/**
 * Bouwt de flat runhours-tabel (zelfde output als voorheen via sleutels).
 *
 * @return array{ok:bool, data?:list<array>, error?:string}
 */
function connect_runhours_build(bool $useMock = false): array
{
    $fetched = connect_runhours_fetch_xml($useMock);
    if (!($fetched['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($fetched['error'] ?? 'XML ophalen mislukt.')];
    }

    $xmlString = (string) ($fetched['xml'] ?? '');
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml || !$xml->objects) {
        return ['ok' => false, 'error' => 'Invalid or empty XML received'];
    }

    $storageFile = connect_runhours_history_path($useMock);
    $history = [];
    $historyLoadedOk = !is_file($storageFile);
    if (is_file($storageFile)) {
        $rawHistory = file_get_contents($storageFile);
        $decodedHistory = is_string($rawHistory) ? json_decode($rawHistory, true) : null;
        if (is_array($decodedHistory)) {
            $history = $decodedHistory;
            $historyLoadedOk = true;
        } else {
            return [
                'ok' => false,
                'error' => 'runtime-history.json is ongeldig JSON; bestand niet overschreven.',
            ];
        }
    }

    $flatList = [];
    $today = date('Y-m-d');
    $objectList = json_decode(json_encode($xml))->objects->object ?? [];
    if (!is_array($objectList)) {
        $objectList = [$objectList];
    }

    foreach ($objectList as $obj) {
        if (!is_object($obj)) {
            continue;
        }

        connect_runhours_process_object(
            $obj,
            $flatList,
            $history,
            $today,
            null,
            '',
            !isset($obj->children->object)
        );

        if (isset($obj->children->object)) {
            $children = is_array($obj->children->object) ? $obj->children->object : [$obj->children->object];
            foreach ($children as $child) {
                if (!is_object($child)) {
                    continue;
                }
                if (!isset($obj->additionalName)) {
                    $obj->additionalName = '';
                }
                connect_runhours_process_object(
                    $child,
                    $flatList,
                    $history,
                    $today,
                    (string) ($obj->name ?? ''),
                    (string) ($obj->additionalName ?? '')
                );
            }
        }
    }

    if ($historyLoadedOk) {
        @file_put_contents($storageFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    return ['ok' => true, 'data' => $flatList];
}

/**
 * Page load (alleen bij directe HTTP-aanroep van api.php)
 */
$connectApiIsDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if ($connectApiIsDirect) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $useMock = isset($_GET['test']);
    $result = connect_runhours_build($useMock);
    if (!($result['ok'] ?? false)) {
        $message = (string) ($result['error'] ?? 'Unknown error');
        $code = str_contains($message, 'XML') && str_contains($message, 'Invalid') ? 400 : 500;
        http_response_code($code);
        echo json_encode(['error' => $message], JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode($result['data'] ?? [], JSON_PRETTY_PRINT);
}
