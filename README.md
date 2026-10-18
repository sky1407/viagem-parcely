# Parcely okresu Jičín

Webová aplikácia, ktorá na mape zobrazuje všetky parcely katastra nehnuteľností v okrese Jičín
(111 obcí, 240 katastrálnych území, 271 995 parciel). Po kliknutí na parcelu zobrazí jej detail.

- Celý okres je dostupný bez obmedzenia rozsahu. Parcely sa kreslia od zoomu 14, pri oddialení sú vidieť hranice katastrálnych území.
- Detail parcely obsahuje číslo, KÚ, obec, výmeru, druh pozemku, spôsob využitia, ochranu, platnosť a odkaz do Nahlížení do KN.
- Od zoomu 17 sú v mape čísla parciel. Pod kurzorom sa parcela zvýrazní a ukáže sa jej číslo.
- Parcelu sa dá vyhľadať podľa KÚ a čísla (napr. `Jičín` + `st. 1`). Názov KÚ sa dá písať aj bez diakritiky.
- Vybraná parcela je v URL, takže odkaz sa dá poslať ďalej. Označenie parcely aj odkaz sa dajú skopírovať jedným klikom.
- Podklad sa dá prepnúť medzi OpenStreetMap a ortofotom ČÚZK.
- Rozhranie funguje aj na mobile: panely sa zbalia a vybraná parcela nezostane schovaná pod detailom.

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

Aplikácia beží na <http://127.0.0.1:8080/>. Pohľad na mapu aj vybraná parcela sa ukladajú do URL, takže sa dajú poslať odkazom:
`/#map=17/50.4363/15.3525&parcel=1753220604`. Stačí aj samotné `/#parcel=1753220604`, mapa na parcelu priletí.

### Testy

```bash
vendor/bin/phpunit
```

Unit testy (PHPUnit 12) pokrývajú prevod GML → WKT vrátane oblúkov, parsovanie čísla parcely, validáciu nakreslenej línie alebo oblasti a router.
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

### Prečo Render

Projekt som nasadil na Render, aby som ho otestoval v reálnom prostredí a odladil chyby, ktoré sa pri lokálnom
spustení neprejavia: obmedzené práva kontajnera, pomalé CPU a DB na free pláne, health checky platformy,
cache prehliadača medzi nasadeniami a skutočné mobilné zariadenia. Chyby nájdené po nasadení
(podrobnosti v [zápisníku rozhodnutí](docs/decisions.md), sekcie 20 a 21):

| Commit | Chyba | Oprava |
|---|---|---|
| `6b46176` | Kontajner sa hlásil ako `unhealthy`, zdedený HEALTHCHECK sa pýtal vypnutého admin API Caddy | healthcheck volá priamo aplikáciu |
| `fd30317` | `exec: frankenphp: Operation not permitted`, Render nepovoľuje binárky s file capabilities | `setcap -r` a beh pod neprivilegovaným používateľom |
| `a942098` | Predgenerovanie všetkých 12 498 dlaždíc by na free pláne trvalo ~1,5 h | na Renderi iba z8–z14 (895 dlaždíc) |
| `ac2f22c` | Databáza bola dostupná z internetu | prístup iba z internej siete Renderu |
| `652b546` | Výpadky 502: health check na `/` čakal za pomalými dlaždicami na 2 PHP vláknach | `/` ako statický súbor, 8 PHP vlákien, predgenerovanie 1 procesom |
| `d4c4b5b` | Server ukladal dlaždice až do z22, disk sa dal zaplniť | limit `TileService::MAX_ZOOM = 16` |
| `a383a67` | `HEAD` vracal 405 a `/api/health` trval 1,8 s (`count(*)`) | HEAD cez trasu GET, odhad počtu zo štatistík DB (11 ms) |
| `51656c2` | „Platí od“ ukazovalo západne od UTC o deň skôr | dátum v miestnom čase |
| `d2e41e3` | Chýbali bezpečnostné hlavičky, odpovede prezrádzali verziu PHP | CSP, `nosniff`, `Referrer-Policy`, bez `X-Powered-By` |
| `4061e8d` | Natívny `<datalist>` nenašiel KÚ pri písaní bez diakritiky | vlastný našepkávač bez ohľadu na diakritiku |
| `9afaa4d` | Na mobile ostalo pre mapu ~100 px a vybraná parcela bola pod detailom | panely sa zbalia, parcela sa posunie do viditeľnej časti |
| `ed6e117` | Pomalé načítanie dlaždíc nebolo nijako vidieť a chyby servera sa stratili | indikátor načítavania a hlásenie chyby nad mapou |
| `f05fdda` | Po nasadení spadol prelet na parcelu, prehliadač mal v cache detail zo starej verzie | verzia v URL API (`?v=2`), `no-cache` pre `index.html`, `app.js`, `style.css` |

### Rýchlosť na free pláne

Namerané 17. 10. 2026 na prebudenej inštancii:

| | čas |
|---|---|
| `index.html`, `app.js`, `/api/health` | 60–100 ms |
| dlaždica z cache (z8–z14 predgenerované) | 55–120 ms |
| dlaždica z15–z16 pri prvom zobrazení | 0,3–3,7 s |
| prebudenie uspatej inštancie | ~1 min |

Pomalé prvé načítanie je teda vlastnosť free plánu, nie aplikácie: inštancia sa po 15 minútach nečinnosti uspí,
po prebudení sa cache dlaždíc vytvára znova (disk nie je trvalý) a dlaždice z15–z16 sa generujú až pri prvom zobrazení.
Na platenom pláne s trvalým diskom by sa celá cache predgenerovala raz a každá dlaždica by išla z cache.

## API

| Endpoint | Odpoveď |
|---|---|
| `GET /api/tiles/{z}/{x}/{y}.pbf` | Mapbox Vector Tile s vrstvami `cadastral_areas` (vždy) a `parcels` (z ≥ 14); prázdna dlaždica vracia `204` |
| `GET /api/parcels/{id}` | detail parcely (JSON) |
| `GET /api/parcels/search?cadastral_area={kód}&number={číslo}` | `{id, bbox}` nájdenej parcely; číslo vo formáte `st. 12/3`, `791/34`, `15` |
| `GET /api/cadastral-areas` | zoznam KÚ okresu pre našepkávač vrátane `bbox` (WGS84) |
| `GET /api/cadastral-areas/{kód}/parcels` | `[{id, number}]` všetkých parciel KÚ pre našepkávač čísla parcely; neznáme KÚ vracia `404` |
| `POST /api/parcels/intersect` | telo je GeoJSON `LineString` alebo `Polygon` (jeden prstenec, najviac 200 bodov, v okrese); vracia `{truncated, parcels: [{id, number, cadastral_area, land_type, area_m2}]}`, najviac 500 parciel |
| `GET /api/health` | stav aplikácie a odhad počtu parciel zo štatistík DB (bez prechodu tabuľkou) |

Chyby majú tvar `{"error": "..."}` s kódom 400, 404, 405, 413 alebo 500. Pri chybe 500 sa detail zapíše iba do logu servera.

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
  Domain/DrawnGeometry.php validácia nakreslenej línie alebo oblasti pred dotazom do PostGIS
tests/                    PHPUnit testy
sql/schema.sql            schéma PostGIS (spúšťa sa pri prvom štarte kontajnera)
storage/                  stiahnuté dáta a cache dlaždíc (mimo gitu)
```

## Zdroje dát

- RÚIAN, výmenný formát VFR po obciach (`vdp.cuzk.gov.cz`): parcely, hranice KÚ.
- Číselníky ČÚZK (`services.cuzk.gov.cz/sestavy/cis`): druhy pozemkov, spôsoby využitia a ochrany, zoznam KÚ.
- Ortofoto ČÚZK (`ags.cuzk.gov.cz`) a OpenStreetMap ako podkladové mapy.
