<?php
/**
 * Directe HTTP op api.php lekt geen draaiuren. Include vanuit hourly blijft werken.
 * Run: php tests/api_access_test.php
 */

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fail($label . ' mist "' . $needle . '"');
    }
}

function assert_not_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fail($label . ' bevat "' . $needle . '"');
    }
}

$root = dirname(__DIR__);
$apiFile = $root . '/web/api.php';
$apiSource = (string) file_get_contents($apiFile);
assert_not_contains($apiSource, 'Access-Control-Allow-Origin', 'geen CORS');
assert_not_contains($apiSource, 'is_trusted_requester', 'geen localhost-bypass');
assert_contains($apiSource, 'auth.php', 'auth.php blijft geladen');
assert_contains($apiSource, 'session_write_close', 'sessie vrij na login');
assert_contains($apiSource, "require_once \$loginLib", 'login/lib.php');
assert_contains($apiSource, 'connect_api_email_is_allowed', 'allowedUsers-check');

$direct = substr($apiSource, (int) strpos($apiSource, '$connectApiIsDirect'));
$loginAt = strpos($direct, 'connect_api_require_http_login()');
$buildAt = strpos($direct, 'connect_runhours_build(');
if ($loginAt === false || $buildAt === false || $loginAt > $buildAt) {
    fail('login moet vóór connect_runhours_build bij een directe aanroep');
}

$authPath = $root . '/web/auth.php';
$authExisted = is_file($authPath);
$authBackup = null;
if ($authExisted) {
    $authBackup = (string) file_get_contents($authPath);
}
file_put_contents($authPath, "<?php\n\$allowedUsers = ['analyst@kvt.nl'];\n\$connect101Api = ['access_token' => 'not-a-real-token', 'url' => 'http://127.0.0.1:9/unused'];\n");

$historyPath = $root . '/web/cache/runhours/mock-runtime-history.json';
@unlink($historyPath);

try {
    ob_start();
    require $apiFile;
    $includedOutput = ob_get_clean();
    if (!is_string($includedOutput) || $includedOutput !== '') {
        fail('include van api.php dumpte HTTP-output');
    }

    assert_same(true, connect_api_direct_needs_login('apache2handler'), 'web heeft login nodig');
    assert_same(true, connect_api_direct_needs_login('cgi-fcgi'), 'cgi heeft login nodig');
    assert_same(true, connect_api_direct_needs_login('fpm-fcgi'), 'fpm heeft login nodig');
    assert_same(false, connect_api_direct_needs_login('cli'), 'cli-operator');
    assert_same(false, connect_api_direct_needs_login('CLI'), 'cli hoofdletters');

    $allowed = [
        'analyst@kvt.nl',
        'planner@kvt.nl' => [15, 40],
    ];
    assert_same(true, connect_api_email_is_allowed('Analyst@kvt.nl', $allowed), 'lijst-gebruiker');
    assert_same(true, connect_api_email_is_allowed('planner@kvt.nl', $allowed), 'map-gebruiker');
    assert_same(false, connect_api_email_is_allowed('andere@kvt.nl', $allowed), 'niet op de lijst');
    assert_same(false, connect_api_email_is_allowed('', $allowed), 'lege sessie');
    assert_same(false, connect_api_email_is_allowed('analyst@kvt.nl', null), 'geen whitelist');

    $built = connect_runhours_build(true);
    if (!($built['ok'] ?? false) || !is_array($built['data'] ?? null) || $built['data'] === []) {
        fail('include-pad connect_runhours_build(mock) schrijft geen tabel meer');
    }
    if (!is_file($historyPath)) {
        fail('include-pad schrijft runtime-history niet meer');
    }

    $uiFiles = [
        $root . '/web/index.php',
        $root . '/web/predict.php',
        $root . '/web/assets/runhours.js',
        $root . '/web/maintenance.php',
    ];
    foreach ($uiFiles as $uiFile) {
        $uiSource = (string) file_get_contents($uiFile);
        assert_not_contains($uiSource, 'api.php', basename($uiFile) . ' roept api.php niet via HTTP aan');
    }
    $hourly = (string) file_get_contents($root . '/web/runhours_data.php');
    assert_contains($hourly, "require_once __DIR__ . '/api.php'", 'hourly-pad include blijft');

    $phpCgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
    if ($phpCgi !== '') {
        $script = sys_get_temp_dir() . '/volundr-api-cgi-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($apiFile, true) . ";\n"
            . '$_SERVER["SCRIPT_NAME"] = "/volundr/api.php";' . "\n"
            . '$_SERVER["PHP_SELF"] = "/volundr/api.php";' . "\n"
            . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
            . '$_SERVER["SERVER_ADDR"] = "198.51.100.10";' . "\n"
            . '$_GET["test"] = "1";' . "\n"
            . 'require ' . var_export($apiFile, true) . ";\n");
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$phpCgi, '-q', '-d', 'display_errors=0', $script], $descriptor, $pipes, $root);
        if (!is_resource($process)) {
            @unlink($script);
            fail('php-cgi startte niet');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($script);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        if ($exitCode !== 0 && strpos($stdout, 'Geen toegang') === false) {
            fail('anonieme api.php exit ' . $exitCode . ' ' . $stderr . ' ' . $stdout);
        }
        assert_contains($stdout, 'Geen toegang', 'anonieme api.php');
        assert_not_contains($stdout, 'Access-Control-Allow-Origin', 'anonieme api.php');
        assert_not_contains($stdout, 'Fuenix', 'anonieme api.php');
        assert_not_contains($stdout, '359804085215319', 'anonieme api.php');
        assert_not_contains($stdout, 'Boskalis', 'anonieme api.php');
    }
} finally {
    @unlink($historyPath);
    if ($authExisted) {
        if ($authBackup !== null) {
            file_put_contents($authPath, $authBackup);
        }
    } else {
        @unlink($authPath);
    }
}

echo "OK api access\n";
