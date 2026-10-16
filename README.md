# Parcely okresu Jičín

Webová aplikácia, ktorá na mape zobrazuje všetky parcely katastra nehnuteľností v okrese Jičín
(111 obcí, 240 katastrálnych území, 271 995 parciel). Po kliknutí na parcelu zobrazí jej detail.

- Celý okres je dostupný bez obmedzenia rozsahu. Parcely sa kreslia od zoomu 14, pri oddialení sú vidieť hranice katastrálnych území.
- Detail parcely obsahuje číslo, KÚ, obec, výmeru, druh pozemku, spôsob využitia, ochranu, platnosť a odkaz do Nahlížení do KN.
- Parcelu sa dá vyhľadať podľa KÚ a čísla (napr. `Jičín` + `st. 1`).
- Podklad sa dá prepnúť medzi OpenStreetMap a ortofotom ČÚZK.

Backend je čisté PHP bez frameworku, dáta sú v PostgreSQL/PostGIS a frontend používa MapLibre GL JS bez build kroku.

**Živá ukážka:** <https://viagem-parcely.onrender.com>. Beží na free pláne Renderu: po 15 minútach nečinnosti sa uspí
a prvé načítanie potom trvá asi minútu.
Rozhodnutia aj s nameranými číslami sú v [zápisníku rozhodnutí](docs/decisions.md).

## Predpoklady

- **PHP 8.3+** (vyvíjané na 8.5) s rozšíreniami `curl`, `dom`, `iconv`, `libxml`, `pcntl`, `pdo_pgsql`, `xmlreader`, `zip`, `zlib`.
  Rozšírenie `pcntl` existuje iba na Linuxe a macOS, na Windows použite WSL.
- **Composer 2**, ktorý tu slúži na PSR-4 autoload. Aplikácia nemá žiadne runtime knižnice, jediná vývojová závislosť je PHPUnit.
- **Docker + Docker Compose** pre PostGIS.
- Prístup na internet: dáta ČÚZK pri importe, MapLibre z CDN a podkladové mapy v prehliadači.

## Spustenie

### Iba s Dockerom

```bash
docker compose up -d --build                        # PostGIS + aplikácia (FrankenPHP) na porte 8080
docker compose exec app php bin/import.php          # import celého okresu (~1 min)
docker compose exec app php bin/warm-tiles.php      # voliteľné: predgenerovanie dlaždíc
```

Aplikácia beží na <http://127.0.0.1:8080/>. Port sa dá zmeniť premennou `APP_PORT`.

### S lokálnym PHP (vývoj)

```bash
docker compose up -d db           # PostGIS 17 + 3.5 na porte 5433, schéma sa vytvorí automaticky
composer install                  # autoload + PHPUnit
php bin/import.php                # stiahne a naimportuje celý okres (~1 min, ~54 MB do storage/import)
php bin/warm-tiles.php            # voliteľné: predgeneruje dlaždice celého okresu (~7 s)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t public public/index.php
```

Aplikácia beží na <http://127.0.0.1:8080/>. Pohľad na mapu sa ukladá do URL (napr. `/#17/50.4363/15.3525`).

### Testy

```bash
vendor/bin/phpunit
```

Unit testy (PHPUnit 12) pokrývajú prevod GML → WKT vrátane oblúkov, parsovanie čísla parcely a router.
Nepotrebujú databázu ani sieť.

### Voľby skriptov

| Príkaz | Voľba | Význam |
|---|---|---|
| `bin/import.php` | `--municipality=572659` | importuje iba jednu obec (kód RÚIAN) |
| | `--date=20260930` | dátum súboru VFR; predvolene posledný deň minulého mesiaca |
| `bin/warm-tiles.php` | `--workers=6` | počet paralelných procesov |
| | `--min-zoom=8 --max-zoom=16` | rozsah zoomov |

Import je idempotentný: každá obec sa nahradí v jednej transakcii a stiahnuté ZIP súbory sa pri ďalšom behu
berú z `storage/import`. Na konci importu sa vymaže cache dlaždíc.

### Konfigurácia

Pripojenie k DB sa číta z premenných prostredia `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
(predvolene `127.0.0.1:5433`, `parcels`/`parcels`/`parcels`), pozri [config.php](config.php).
Rovnaké premenné používa `docker-compose.yml`.

Na prezeranie databázy je k dispozícii voliteľný pgAdmin: `docker compose --profile tools up -d`, potom <http://127.0.0.1:5050/>.

## Nasadenie (Render)

[render.yaml](render.yaml) popisuje web service z [Dockerfile](Dockerfile) (FrankenPHP, Frankfurt) a PostgreSQL 17.

1. V Render Dashboarde zvoľte **New → Blueprint** a vyberte tento repozitár.
2. Po vytvorení databázy nahrajte dáta z lokálnej DB. External Database URL nájdete v Renderi v sekcii databázy → Connect.
   Databáza je predvolene dostupná iba z internej siete Renderu (`ipAllowList: []`). Na nahranie dát dočasne pridajte
   svoju IP v sekcii databázy → Networking → PostgreSQL Inbound IP Rules.
   Obnovujú sa iba objekty schémy `public`, lebo schémy geokódera (`tiger`, `topology`) už Render má:

   ```bash
   docker compose exec -T -e URL="<External Database URL>?sslmode=require" db sh -c '
     pg_dump -U parcels -d parcels -Fc --no-owner --no-acl -f /tmp/parcels.dump &&
     pg_restore -l /tmp/parcels.dump | grep -E " (TABLE|MATERIALIZED VIEW|INDEX|CONSTRAINT|FK CONSTRAINT|TABLE DATA|MATERIALIZED VIEW DATA|SEQUENCE) public " | grep -v spatial_ref_sys > /tmp/public.list &&
     pg_restore --no-owner --no-acl --exit-on-error -j 4 -L /tmp/public.list -d "$URL" /tmp/parcels.dump'
   ```

3. Aplikácia pri štarte predgeneruje dlaždice (`WARM_TILES=1`). Disk na Renderi nie je trvalý, preto sa cache po reštarte vytvorí znova.

Pri nasadení s PHP serverom (Caddy/FrankenPHP, nginx, Apache) musia všetky požiadavky na neexistujúce súbory smerovať na `public/index.php`.

## API

| Endpoint | Odpoveď |
|---|---|
| `GET /api/tiles/{z}/{x}/{y}.pbf` | Mapbox Vector Tile s vrstvami `cadastral_areas` (vždy) a `parcels` (z ≥ 14); prázdna dlaždica vracia `204` |
| `GET /api/parcels/{id}` | detail parcely (JSON) |
| `GET /api/parcels/search?cadastral_area={kód}&number={číslo}` | `{id, bbox}` nájdenej parcely; číslo vo formáte `st. 12/3`, `791/34`, `15` |
| `GET /api/cadastral-areas` | zoznam KÚ okresu pre našepkávač |
| `GET /api/health` | stav aplikácie a odhad počtu parciel zo štatistík DB (bez prechodu tabuľkou) |

Chyby majú tvar `{"error": "..."}` s kódom 400, 404, 405 alebo 500. Pri chybe 500 sa detail zapíše iba do logu servera.

## Štruktúra

```
bin/
  import.php              import číselníkov, obcí, KÚ a parciel z RÚIAN VFR
  warm-tiles.php          paralelné predgenerovanie dlaždíc
public/
  index.php               front controller (routy, DI, globálne ošetrenie chýb)
  index.html, app.js, style.css
src/
  Router.php              regex router s pomenovanými parametrami, 404/405
  Database.php            továreň na PDO
  Http/JsonResponse.php
  Controller/             HTTP vrstva: validácia vstupov, hlavičky
  Repository/             SQL dotazy (dlaždice, parcely, KÚ)
  Service/TileService.php cache → generovanie → gzip
  Cache/TileCache.php     disková cache dlaždíc s atomickým zápisom
  Import/                 Downloader, CodeListImporter, VfrParcelReader, GmlToWkt, ParcelWriter
  Domain/ParcelNumber.php parsovanie a formátovanie čísla parcely („st. 12/3“)
tests/                    PHPUnit testy
sql/schema.sql            schéma PostGIS (spúšťa sa pri prvom štarte kontajnera)
storage/                  stiahnuté dáta a cache dlaždíc (mimo gitu)
```

## Zdroje dát

- RÚIAN, výmenný formát VFR po obciach (`vdp.cuzk.gov.cz`): parcely, hranice KÚ.
- Číselníky ČÚZK (`services.cuzk.gov.cz/sestavy/cis`): druhy pozemkov, spôsoby využitia a ochrany, zoznam KÚ.
- Ortofoto ČÚZK (`ags.cuzk.gov.cz`) a OpenStreetMap ako podkladové mapy.
