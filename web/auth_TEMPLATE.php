<?php
/**
 * Fragment voor web/auth.php van Völundr. Dit bestand vervangt auth.php niet.
 *
 * Zet $mimirApi én de Business Central-credentials naast elkaar.
 * Met $mimirApi proberen fetches eerst Mímir en vallen terug op het BC-blok hieronder.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 *
 * 101Connect ($connect101Api), mail en overige secrets blijven in de echte auth.php
 * op de server staan. auth.php wordt niet gecommit.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct pad, én fallback als Mímir faalt) ---
// $auth_list = [
//     'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
// ];
// $environment = 'Production';
// $auth = $auth_list[$environment];
// $baseUrl = 'https://my-bc-domain.com:7148/';

// Toegang tot de UI en tot directe HTTP op api.php loopt via login/lib.php
// en $allowedUsers (staat al in de echte auth.php). Geen aparte API-key voor
// api.php: de pagina's roepen die URL niet aan.
//
// Optioneel, alleen als een HTTP-runner odata.php?action=… moet aanroepen.
// Header X-Volundr-Secret moet exact dit geheim zijn. Weglaten: geen anonieme toegang.
// CLI (php web/nightly.php, php web/hourly.php) heeft dit niet nodig.
// $volundrRunnerSecret = '';
