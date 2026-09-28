# Völundr

101Connect-draaiuren (run hours) en onderhoudsvoorspelling op sleutels.kvt.nl/volundr. OData/company-discovery uit Business Central is optioneel via Mímir.

## Structuur

- `web/index.php` — UI (draaiuren / voorspelling)
- `web/api.php` / `web/runhours_data.php` — 101Connect-fetch + cache + weekrapport
- `web/odata.php` — OData-client, lokale filecache-widget, optionele Mímir-proxy
- `web/hourly.php` — incrementele 101Connect-refresh (geen BC-OData vandaag)
- `web/nightly.php` — weekrapport (maandag; data uit cache)
- `web/auth.php` — credentials (niet in git)

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// Business Central blijft naast $mimirApi staan (direct pad én fallback):
$baseUrl = 'https://my-bc-domain.com:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => '…', 'pass' => '…']];
$auth = $auth_list[$environment];
```

Met `$mimirApi` gezet proberen company-discovery en alle OData-fetches (`odata_get_all`) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Völundr dezelfde gegevens op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Laat die BC-credentials in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan gaat de oorspronkelijke Mímir-fout door. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd. 101Connect (`$connect101Api`) blijft een aparte data-bron.

Live pagina's (`index.php`, `predict.php`, `api.php`, `maintenance.php`) én cron/CLI (`nightly.php`, `hourly.php`, via `runhours_data.php`) laden `auth.php` volledig, ook als `$mimirApi` gezet is, zodat de fallback de BC-credentials heeft. Een CLI-run (`php nightly.php`, `PHP_SAPI=cli` / `php_sapi_name()`) houdt de lange Mímir-timeout; webverzoeken gebruiken een kortere (connect 10s, totaal ongeveer 90s).

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | doet géén BC-OData vandaag (weekrapport uit cache) — constant `VOLUNDR_NIGHTLY_MAX_AGE` (**14400**, 4u) gereserveerd |
| `hourly.php` | 101Connect-refresh, géén BC-OData — constant `VOLUNDR_HOURLY_MAX_AGE` (**1800**, ≤30 min) gereserveerd |
| UI / on-demand | bestaande TTL **300** (`VOLUNDR_ODATA_TTL` / `odata_get_all`-default) |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten, mét de BC-credentials ernaast; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie) en `web/auth_TEMPLATE.php`.

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server de Mímir-sleutel zetten zoals hierboven, en `$baseUrl`, `$auth` / `$auth_list` en `$environment` daarnaast laten staan voor de directe BC-fallback. 101Connect-token blijft in `$connect101Api`.
