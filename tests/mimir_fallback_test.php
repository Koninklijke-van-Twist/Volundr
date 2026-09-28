<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/volundr-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['VOLUNDR_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Volundr] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$root = dirname(__DIR__) . '/web';
foreach (['nightly.php', 'hourly.php', 'runhours_data.php', 'api.php', 'index.php', 'predict.php', 'maintenance.php'] as $entry) {
    $source = file_get_contents($root . '/' . $entry);
    if (!is_string($source) || strpos($source, 'auth.php') === false) {
        fail($entry . ' moet auth.php laden zodat BC-credentials voor de fallback beschikbaar zijn');
    }
    if (preg_match('/if\s*\([^)]*mimirApi[^)]*\)\s*\{[^}]*auth\.php/s', $source) === 1) {
        fail($entry . ' mag auth.php niet overslaan wanneer $mimirApi gezet is');
    }
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Volundr] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$loggedBeforeCaller = fallback_count();
$callsBeforeCaller = count($calls);
$callerError = null;
try {
    odata_mimir_fetch_all('https://mimir.invalid/not-an-odata-url', 10);
    fail('een onvertaalbare URL moet een fout geven');
} catch (Throwable $exception) {
    $callerError = $exception;
}
if (!$callerError instanceof Throwable || strpos($callerError->getMessage(), 'OData-URL') === false) {
    fail('caller-fout was niet de vertaalfout: ' . ($callerError instanceof Throwable ? $callerError->getMessage() : 'geen'));
}
if (odata_mimir_circuit_open()) {
    fail('een fout van de caller mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeCaller || count($calls) !== $callsBeforeCaller) {
    fail('een fout van de caller mag niet terugvallen op BC');
}

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$auth = $auth_list['Production'];
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource",
    ['user' => 'sandbox-user']
);
$placeholderKey = build_cache_key('https://mimir.invalid/mimir/ODataV4/Company', ['user' => 'bcuser']);
$environment = 'Production';
$cacheFragment = substr($cacheKey, (int) strrpos($cacheKey, '|') + 1);
$placeholderFragment = substr($placeholderKey, (int) strrpos($placeholderKey, '|') + 1);
if ($cacheFragment !== 'Sandbox') {
    fail('cache-key gebruikte niet het environment uit de URL: ' . $cacheKey);
}
if (strcasecmp($placeholderFragment, 'mimir') === 0 || stripos($placeholderFragment, 'mimir.invalid') !== false) {
    fail('cache-key fragment is een placeholder: ' . $placeholderKey);
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeCompanyEnv = count($calls);
$companyEnvRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
if (($companyEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-environment fallback gaf geen rijen');
}
$companyEnvCall = $calls[$beforeCompanyEnv] ?? null;
if (!is_array($companyEnvCall)
    || strpos((string) ($companyEnvCall['url'] ?? ''), "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0
    || ($companyEnvCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('query gebruikte niet het environment en de auth van het bedrijf: ' . json_encode($companyEnvCall));
}

odata_mimir_circuit_reset();
$beforeUrlEnv = count($calls);
$urlEnvRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($urlEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('URL-environment fallback gaf geen rijen');
}
$urlEnvCall = $calls[$beforeUrlEnv] ?? null;
if (!is_array($urlEnvCall)
    || ($urlEnvCall['url'] ?? '') !== "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No"
    || ($urlEnvCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('URL-segment werd vervangen door het primaire environment: ' . json_encode($urlEnvCall));
}

odata_mimir_circuit_reset();
$beforeMapped = count($calls);
$mappedRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($mappedRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-map fallback gaf geen rijen');
}
$mappedCall = $calls[$beforeMapped] ?? null;
if (!is_array($mappedCall)
    || strpos((string) ($mappedCall['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/') !== 0
    || ($mappedCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('placeholder-environment negeerde de company-map: ' . json_encode($mappedCall));
}
if (strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'bc-secret') !== false) {
    fail('log bevat een geheim na company-environment fallback');
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'primary-user', 'pass' => 'primary-secret'];
$auth_list = [];
$GLOBALS['demeter_company_environment_map'] = [
    'KVT Gas' => 'Production',
    'Hunter van Twist' => 'Sandbox',
];
if (!odata_bc_credentials_configured()) {
    fail('globale $auth moet als BC-credentials gelden zonder auth_list-entry');
}
$beforePrimaryAuth = count($calls);
$primaryAuthRows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    [],
    12
);
$primaryAuthCall = $calls[$beforePrimaryAuth] ?? null;
if (($primaryAuthRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($primaryAuthCall)
    || ($primaryAuthCall['url'] ?? '') !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No"
    || ($primaryAuthCall['user'] ?? '') !== 'primary-user'
) {
    fail('primair environment zonder auth_list-entry gebruikte $auth niet: ' . json_encode($primaryAuthCall));
}

odata_mimir_circuit_reset();
$beforePrimaryQuery = count($calls);
$primaryQueryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$primaryQueryCall = $calls[$beforePrimaryQuery] ?? null;
if (($primaryQueryRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($primaryQueryCall)
    || strpos((string) ($primaryQueryCall['url'] ?? ''), "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0
    || ($primaryQueryCall['user'] ?? '') !== 'primary-user'
) {
    fail('query naar het primaire environment negeerde globale $auth: ' . json_encode($primaryQueryCall));
}

odata_mimir_circuit_reset();
$beforePrimaryCompanies = count($calls);
$primaryCompanyRows = odata_mimir_companies_as_rows('Production');
$primaryCompanyCall = $calls[$beforePrimaryCompanies] ?? null;
if (($primaryCompanyRows[0]['Name'] ?? '') === ''
    || !is_array($primaryCompanyCall)
    || strpos((string) ($primaryCompanyCall['url'] ?? ''), 'https://bc.example:7148/Production/ODataV4/Company') !== 0
    || ($primaryCompanyCall['user'] ?? '') !== 'primary-user'
) {
    fail('companylijst van het primaire environment negeerde globale $auth: ' . json_encode($primaryCompanyCall));
}

odata_mimir_circuit_reset();
$loggedBeforeOtherEnv = fallback_count();
$beforeOtherEnv = count($calls);
$otherEnvRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    [],
    12
);
$otherEnvCall = $calls[$beforeOtherEnv] ?? null;
if (($otherEnvRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($otherEnvCall)
    || ($otherEnvCall['url'] ?? '') !== "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No"
    || ($otherEnvCall['user'] ?? '') !== 'primary-user'
) {
    fail('lege auth_list: niet-primair environment gebruikte $auth niet: ' . json_encode($otherEnvCall));
}
if (fallback_count() !== $loggedBeforeOtherEnv + 1) {
    fail('de eerste Mímir-fout voor een ander environment moet wel gelogd worden');
}

odata_mimir_circuit_reset();
$beforeOtherQuery = count($calls);
$otherQueryRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
$otherQueryCall = $calls[$beforeOtherQuery] ?? null;
if (($otherQueryRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($otherQueryCall)
    || strpos((string) ($otherQueryCall['url'] ?? ''), "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0
    || ($otherQueryCall['user'] ?? '') !== 'primary-user'
) {
    fail('lege auth_list: query naar een ander environment gebruikte $auth niet: ' . json_encode($otherQueryCall));
}

odata_mimir_circuit_reset();
$beforeOtherCompanies = count($calls);
$otherCompanyRows = odata_mimir_companies_as_rows('Sandbox');
$otherCompanyCall = $calls[$beforeOtherCompanies] ?? null;
if (($otherCompanyRows[0]['Name'] ?? '') === ''
    || !is_array($otherCompanyCall)
    || strpos((string) ($otherCompanyCall['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/Company') !== 0
    || ($otherCompanyCall['user'] ?? '') !== 'primary-user'
) {
    fail('lege auth_list: companylijst van een ander environment negeerde $auth: ' . json_encode($otherCompanyCall));
}
if (strpos(fallback_log(), 'primary-secret') !== false) {
    fail('log bevat het wachtwoord van de primaire $auth');
}

unset($auth_list);
unset($GLOBALS['auth_list']);
$environment = 'Production';
$baseUrl = 'https://bc.example:7148/';
$auth = ['mode' => 'basic', 'user' => 'only-auth', 'pass' => 'only-secret'];
$GLOBALS['demeter_company_environment_map'] = [
    'KVT Gas' => 'Production',
    'Hunter van Twist' => 'Sandbox',
];
odata_mimir_circuit_reset();
$beforeOnlyQuery = count($calls);
$onlyQueryRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
$onlyQueryCall = $calls[$beforeOnlyQuery] ?? null;
if (($onlyQueryRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($onlyQueryCall)
    || strpos((string) ($onlyQueryCall['url'] ?? ''), "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0
    || ($onlyQueryCall['user'] ?? '') !== 'only-auth'
) {
    fail('alleen $auth: query ging niet naar BC: ' . json_encode($onlyQueryCall));
}

odata_mimir_circuit_reset();
$beforeOnlyFetch = count($calls);
$onlyFetchRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    [],
    12
);
$onlyFetchCall = $calls[$beforeOnlyFetch] ?? null;
if (($onlyFetchRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($onlyFetchCall)
    || ($onlyFetchCall['url'] ?? '') !== "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No"
    || ($onlyFetchCall['user'] ?? '') !== 'only-auth'
) {
    fail('alleen $auth: URL-fetch ging niet naar BC: ' . json_encode($onlyFetchCall));
}

odata_mimir_circuit_reset();
$beforeOnlyCompanies = count($calls);
$onlyCompanyRows = odata_mimir_companies_as_rows(null);
$onlyCompanyCall = $calls[$beforeOnlyCompanies] ?? null;
if (($onlyCompanyRows[0]['Name'] ?? '') === ''
    || !is_array($onlyCompanyCall)
    || strpos((string) ($onlyCompanyCall['url'] ?? ''), 'https://bc.example:7148/Production/ODataV4/Company') !== 0
    || ($onlyCompanyCall['user'] ?? '') !== 'only-auth'
) {
    fail('alleen $auth: companylijst ging niet naar het primaire environment: ' . json_encode($onlyCompanyCall));
}
if (strpos(fallback_log(), 'only-secret') !== false) {
    fail('log bevat het wachtwoord van $auth zonder auth_list');
}

require_once dirname(__DIR__) . '/web/auth_helper.php';
$mimirApi = 'mimir_test_key_should_not_leak';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'only-auth', 'pass' => 'only-secret'];
unset($auth_list);
unset($GLOBALS['auth_list']);
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Sandbox'];
odata_mimir_circuit_reset();
$context = auth_set_current_company_context('Hunter van Twist');
if (($auth['user'] ?? '') !== 'only-auth') {
    fail('auth_set_current_company_context wiste globale $auth: ' . json_encode($auth));
}
if (($context['environment'] ?? '') !== 'Sandbox') {
    fail('company-context zette het environment niet: ' . json_encode($context));
}
$environment = 'Production';
$baseUrl = 'https://bc.example:7148/';
$auth = ['mode' => 'basic', 'user' => 'primary-user', 'pass' => 'primary-secret'];
$auth_list = [
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
];
odata_mimir_circuit_reset();
$beforeUnmapped = count($calls);
$unmappedRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$unmappedCall = $calls[$beforeUnmapped] ?? null;
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($unmappedCall)
    || strpos((string) ($unmappedCall['url'] ?? ''), "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0
    || ($unmappedCall['user'] ?? '') !== 'primary-user'
) {
    fail('unmapped bedrijf gebruikte niet de primaire $auth: ' . json_encode($unmappedCall));
}

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'primary-user', 'pass' => 'primary-secret'],
];
$auth = $auth_list['Production'];
$environment = 'Production';
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
odata_mimir_circuit_reset();
$callsBeforeSandboxUrl = count($calls);
$sandboxUrlError = null;
try {
    odata_get_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
        [],
        12
    );
    fail('Sandbox-URL zonder eigen auth_list-entry moet de Mímir-fout teruggeven');
} catch (Throwable $exception) {
    $sandboxUrlError = $exception;
}
if (!$sandboxUrlError instanceof Throwable || strpos($sandboxUrlError->getMessage(), 'Mímir') === false) {
    fail('Sandbox-URL gaf niet de Mímir-fout terug: ' . ($sandboxUrlError instanceof Throwable ? $sandboxUrlError->getMessage() : 'geen'));
}
if (count($calls) !== $callsBeforeSandboxUrl) {
    fail('Sandbox-URL met gevulde auth_list deed toch een BC-call');
}

unset($GLOBALS['demeter_company_environment_map']);
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$auth = $auth_list['Production'];
$environment = 'Production';
odata_mimir_circuit_reset();
$filteredMap = odata_mimir_company_environment_map('Production');
if (($filteredMap['KVT Gas'] ?? '') !== 'Production') {
    fail('gefilterde company-map gaf het environment niet terug: ' . json_encode($filteredMap));
}
if (array_key_exists('demeter_company_environment_map', $GLOBALS)) {
    fail('een gefilterde company-map mag niet globaal gecachet worden');
}

$GLOBALS['demeter_company_environment_map'] = ['KVT Gas' => 'Sandbox'];
odata_mimir_circuit_reset();
$fullMap = odata_mimir_company_environment_map(null);
$storedMap = $GLOBALS['demeter_company_environment_map'] ?? null;
if (!is_array($storedMap) || $storedMap !== $fullMap || !isset($storedMap['Hunter van Twist'])) {
    fail('ongefilterde company-map verving de gefilterde cache niet: ' . json_encode($storedMap));
}
if (odata_mimir_circuit_open() && odata_bc_mapped_environment('Hunter van Twist') === null) {
    fail('na het openen van het circuit moet de volledige map nog gelden');
}

odata_mimir_circuit_reset();
odata_mimir_company_environment_map('Sandbox');
if (($GLOBALS['demeter_company_environment_map'] ?? null) !== $storedMap) {
    fail('een latere gefilterde map overschreef de volledige globale map');
}
odata_mimir_company_environment_map('mimir');
if (($GLOBALS['demeter_company_environment_map'] ?? null) !== $fullMap) {
    fail('een aanvraag voor environment mimir moet de volledige map bewaren');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials moet een exception terugkomen');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$tmpAuth = sys_get_temp_dir() . '/volundr-auth-fallback-' . getmypid() . '.php';
file_put_contents($tmpAuth, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$base = 'https://loaded-base.example:7148/';
$environment = 'LoadedEnv';
$auth_list = [
    'LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'],
];
$auth = $auth_list['LoadedEnv'];
PHP);
unset($baseUrl, $environment, $auth, $auth_list, $base);
unset($GLOBALS['VOLUNDR_BC_AUTH_LOAD_TRIED']);
$GLOBALS['VOLUNDR_AUTH_PHP_PATH'] = $tmpAuth;
odata_import_bc_credentials();
$loadedBase = odata_bc_base_url();
$loadedUser = (string) ($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '');
$loadedEnv = odata_bc_environment();
if ($loadedBase !== 'https://loaded-bc.example:7148/') {
    fail('auth.php-variabelen bleven buiten $GLOBALS, base=' . var_export($loadedBase, true));
}
if ($loadedUser !== 'loaded-user' || $loadedEnv !== 'LoadedEnv') {
    fail('auth_list/environment uit auth.php zijn niet globaal: user=' . $loadedUser . ' env=' . var_export($loadedEnv, true));
}
if (($GLOBALS['base'] ?? '') !== 'https://loaded-base.example:7148/') {
    fail('base uit auth.php is niet naar $GLOBALS gekopieerd');
}
$baseUrl = 'https://keep.example:7148/';
unset($GLOBALS['VOLUNDR_BC_AUTH_LOAD_TRIED']);
odata_import_bc_credentials();
if (odata_bc_base_url() !== 'https://keep.example:7148/') {
    fail('een gezette baseUrl werd overschreven');
}
require_once $tmpAuth;
if (odata_bc_base_url() !== 'https://keep.example:7148/') {
    fail('tweede require_once maakte de BC-globals weer leeg');
}
@unlink($tmpAuth);
unset($GLOBALS['VOLUNDR_AUTH_PHP_PATH']);
if (strpos(fallback_log(), 'loaded-secret') !== false) {
    fail('log bevat het wachtwoord uit auth.php');
}

echo "OK\n";
