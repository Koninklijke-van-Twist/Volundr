<?php

// kvt_protect_dirs.php: zet .htaccess/index.* neer als ze ontbreken, laat bestaande bestanden staan.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kvt-protect-' . bin2hex(random_bytes(4));
foreach (['cache/odata', 'cache/usersettings', 'data/user_prefs'] as $d) {
    mkdir($tmp . '/' . $d, 0777, true);
}
file_put_contents($tmp . '/data/index.html', 'bestaand');
require $root . '/web/kvt_protect_dirs.php';
// De automatische aanroep draaide op web/; de testmap draait in een subproces.
$helper = file_get_contents($root . '/web/kvt_protect_dirs.php');
$helper = str_replace("register_shutdown_function('kvt_ensure_protected_dirs');", '', str_replace('    kvt_ensure_protected_dirs();', '    kvt_ensure_protected_dirs(' . var_export($tmp, true) . ');', $helper));
$script = $tmp . '/run.php';
file_put_contents($script, $helper);
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
foreach (['cache', 'cache/odata', 'cache/usersettings', 'data', 'data/user_prefs'] as $d) {
    foreach (['.htaccess', 'index.html', 'index.php'] as $f) {
        if (!is_file("$tmp/$d/$f")) {
            fwrite(STDERR, "FAIL: $d/$f ontbreekt\n");
            exit(1);
        }
    }
    if (strpos((string) file_get_contents("$tmp/$d/.htaccess"), 'Require all denied') === false) {
        fwrite(STDERR, "FAIL: $d/.htaccess weigert niet\n");
        exit(1);
    }
}
if (file_get_contents($tmp . '/data/index.html') !== 'bestaand') {
    fwrite(STDERR, "FAIL: bestaand bestand overschreven\n");
    exit(1);
}

// Zelfde request: eerste scan ziet de map niet, mkdir daarna, tweede scan schermt af.
$late = sys_get_temp_dir() . '/kvt-protect-late-' . bin2hex(random_bytes(4));
mkdir($late, 0777, true);
kvt_ensure_protected_dirs($late);
foreach (['cache/runhours', 'cache/odata', 'data/user_prefs'] as $rel) {
    $dir = $late . '/' . $rel;
    mkdir($dir, 0775, true);
    file_put_contents($dir . '/secret.json', '{}');
}
kvt_ensure_protected_dirs($late);
foreach (['cache', 'cache/runhours', 'cache/odata', 'data', 'data/user_prefs'] as $d) {
    foreach (['.htaccess', 'index.html', 'index.php'] as $f) {
        if (!is_file("$late/$d/$f")) {
            fwrite(STDERR, "FAIL: late $d/$f ontbreekt\n");
            exit(1);
        }
    }
    if (strpos((string) file_get_contents("$late/$d/.htaccess"), 'Require all denied') === false) {
        fwrite(STDERR, "FAIL: late $d/.htaccess weigert niet\n");
        exit(1);
    }
}
if (file_get_contents($late . '/cache/runhours/secret.json') !== '{}') {
    fwrite(STDERR, "FAIL: late json overschreven\n");
    exit(1);
}

foreach ([
    'web/runhours_data.php' => ['runhours_cache_dir', 'runhours_maintenance_ensure_writable'],
    'web/api.php' => ['connect_runhours_history_path'],
    'web/odata.php' => ['cache_base_dir'],
    'web/localization.php' => ['saveUserPref'],
] as $file => $fns) {
    $source = (string) file_get_contents($root . '/' . $file);
    foreach ($fns as $fn) {
        $start = strpos($source, 'function ' . $fn . '(');
        if ($start === false) {
            fwrite(STDERR, "FAIL: $fn ontbreekt\n");
            exit(1);
        }
        $next = strpos($source, "\nfunction ", $start + 10);
        $body = $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
        $mkdirAt = strpos($body, 'mkdir(');
        $shieldAt = strpos($body, 'kvt_ensure_protected_dirs(');
        if ($mkdirAt === false || $shieldAt === false || $shieldAt < $mkdirAt) {
            fwrite(STDERR, "FAIL: $fn schermt een nieuwe map niet af\n");
            exit(1);
        }
    }
}
$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
if (strpos($workflow, '--include-glob') !== false || strpos($workflow, 'put -O') !== false) {
    fwrite(STDERR, "FAIL: ftp-deploy mag geen includes/put hebben (--delete wiste server-only mappen)\n");
    exit(1);
}
echo "OK protect dirs\n";
