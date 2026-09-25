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
```

Met `$mimirApi` gezet zijn `$auth_list`, `$environment`, `$baseUrl` en `$auth` ongebruikt voor Business Central — company-discovery en alle OData-fetches (`odata_get_all`) lopen via Mímir. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd. 101Connect (`$connect101Api`) blijft een aparte data-bron.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | doet géén BC-OData vandaag (weekrapport uit cache) — constant `VOLUNDR_NIGHTLY_MAX_AGE` (**14400**, 4u) gereserveerd |
| `hourly.php` | 101Connect-refresh, géén BC-OData — constant `VOLUNDR_HOURLY_MAX_AGE` (**1800**, ≤30 min) gereserveerd |
| UI / on-demand | bestaande TTL **300** (`VOLUNDR_ODATA_TTL` / `odata_get_all`-default) |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server de Mímir-sleutel zetten zoals hierboven; legacy BC-credentials alleen nodig zonder `$mimirApi`. 101Connect-token blijft in `$connect101Api`.
