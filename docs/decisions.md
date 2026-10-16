# Zápisník rozhodnutí

Rozhodnutia sú zoradené chronologicky, tak ako vznikali počas vývoja. Pri každom je uvedené, čo som zvažoval,
čo som zvolil a prečo. Kde to šlo, rozhodnutie stojí na nameranom čísle, nie na odhade.
Hlavným kritériom zadania je plynulosť pri celom zvolenom rozsahu, preto väčšina rozhodnutí rieši výkon.

## 1. Architektúra: dáta vopred do vlastnej DB, nie živé dotazy na ČÚZK

**Možnosti**
- Frontend sa pýta priamo WMS/WFS služieb ČÚZK.
- PHP proxy, ktorá na každý pohyb mapy volá WFS.
- Dáta vopred naimportovať do PostGIS a servírovať z vlastného API.

**Voľba:** import do PostGIS + vlastné API.

**Prečo**
- WFS vracia GML s obmedzeným počtom objektov na požiadavku a odozva sa mení podľa záťaže ČÚZK. Plynulosť by teda závisela od cudzej služby.
- WMS vracia iba obrázok, takže by nebolo jasné, na ktorú parcelu používateľ klikol.
- Kataster sa mení pomaly a mesačná aktualizácia (VFR) na prehliadanie stačí.

## 2. Zdroj dát: RÚIAN VFR po obciach

**Možnosti:** WFS katastrálnej mapy, INSPIRE parcely, RÚIAN výmenný formát (VFR).

**Voľba:** VFR súbory `YYYYMMDD_OB_{kód obce}_UKSH.xml.zip`.

**Prečo**
- Jeden súbor na obec obsahuje parcely s geometriou, číslom, výmerou, druhom pozemku, spôsobom využitia aj ochranou, a k tomu oficiálne hranice KÚ.
- Okres sa dá poskladať zo 111 obcí, pričom ich zoznam pochádza z číselníka `SC_SEZNAMKUKRA_DOTAZ` (okres 3604, kódovanie cp1250).

**Úskalie:** pri neexistujúcom súbore ČÚZK vracia HTTP 200 a HTML stránku. `Downloader` preto overuje ZIP signatúru (`PK\x03\x04`) a nespolieha sa na stavový kód.

## 3. Rozsah: celý okres

Zadanie vyžaduje minimum 4 KÚ, celý okres je bonus. Keďže import beží po obciach a dlaždice sa generujú
z indexovaných dát, náklady na celý okres sú takmer rovnaké ako pri 4 KÚ.
Výsledok: 111 obcí, 240 KÚ, 271 995 parciel, 0 nevalidných geometrií.

## 4. Databáza: PostGIS, geometria v dvoch súradnicových systémoch

- `geom` je v **EPSG:5514 (S-JTSK)**, v pôvodnom systéme katastra, takže plochy a vzdialenosti sú v metroch bez skreslenia.
- `geom_3857` je **generovaný stĺpec** (`GENERATED ALWAYS AS (ST_Transform(geom, 3857)) STORED`) v súradniciach webovej mapy. Transformácia prebehne raz pri zápise, nie pri každej dlaždici.
- GIST index je na `geom_3857`, lebo práve podľa neho sa vyberajú objekty do dlaždice.

## 5. Parsovanie XML: XMLReader + generátor

**Možnosti:** `DOMDocument` / `SimpleXML` načítajú celý súbor do pamäte, `XMLReader` číta prúdovo.

**Voľba:** `XMLReader` prechádza súbor a každý element `Parcela` rozbalí (`expand()`) do malého DOM stromu.
Parcely sa odovzdávajú cez PHP generátor (`yield`), takže pamäť je konštantná bez ohľadu na veľkosť obce.
Súbor sa číta priamo zo ZIP-u cez `zip://`, bez rozbaľovania na disk.

## 6. Geometria s oblúkmi: vlastný prevod GML → WKT

717 parciel v okrese (0,26 %) má hranice s kruhovými oblúkmi (`ArcString`, `Circle`). Je ich málo, ale
`ST_GeomFromGML` v PostGIS ich nevie načítať, takže pri bežnom prístupe by import na nich spadol alebo ich vynechal.

**Voľba:** trieda `GmlToWkt` skladá `CURVEPOLYGON` / `COMPOUNDCURVE` / `CIRCULARSTRING`.
V DB sa oblúky zlinearizujú cez `ST_CurveToLine` a výsledok sa uloží ako `MultiPolygon`.
Oblúky sa tak nestratia ani nenahradia priamkou.

## 7. Zápis: transakcia na obec, dávky po 500

- Každá obec sa zapíše v **jednej transakcii** (`DELETE` jej parciel + `INSERT`). Import je preto idempotentný a pri chybe ostane predchádzajúci stav obce nedotknutý.
- `INSERT` ide po dávkach 500 riadkov jedným príkazom s viacerými `VALUES`. Pripravené príkazy sa cachujú podľa veľkosti dávky.
- Stiahnuté ZIP-y sa cachujú v `storage/import`.

**Namerané:** prvý beh 56 s (so sťahovaním), opakovaný beh ~33 s.

## 8. Hranice KÚ: oficiálne z VFR, nie zjednotenie parciel

**Možnosti:**
- `ST_Union` všetkých parciel KÚ: drahé a pri medzerách medzi parcelami vznikajú diery.
- Oficiálna hranica `kui:OriginalniHranice` z VFR.

**Voľba:** oficiálna hranica. Načíta ju druhý prechod `XMLReader`-om, ktorý skončí hneď po elemente
`KatastralniUzemi` (v súbore je pred parcelami), takže zvyšok súboru nečíta.

**Namerané:** 240 hraníc zo všetkých 111 súborov za 0,4 s. Súčet plôch je 887 km², čo zodpovedá rozlohe okresu. Žiadna parcela neleží mimo svojho KÚ.

## 9. Backend: čisté PHP s vlastným routerom

**Možnosti:** framework (Symfony, Laravel, Slim), knižnica FastRoute, vlastný router.

**Voľba:** front controller `public/index.php` a vlastný `Router` (~40 riadkov).
- Cesty sú regulárne výrazy s pomenovanými skupinami, ktoré sa odovzdajú handleru ako pomenované argumenty.
- Router rozlišuje 404 a 405 (s hlavičkou `Allow`).
- Celé spracovanie obaľuje `try/catch (Throwable)`. Klient dostane generické 500 a detail chyby ide iba do logu.

Zadanie preferuje čisté PHP. API má 5 endpointov, takže framework by pridal viac kódu, než by ušetril.
Composer slúži iba na PSR-4 autoload.

## 10. Webový server: `php -S` s viacerými workermi

**Možnosti:** vstavaný server PHP, nginx + php-fpm v Dockeri, Apache + mod_php.

**Voľba:** `php -S` s `PHP_CLI_SERVER_WORKERS=4`.
- Spustenie je jednoduché a nič netreba konfigurovať.
- Workery riešia hlavnú slabinu vstavaného servera, ktorý by inak spracúval iba jednu požiadavku naraz. Mapa pritom ťahá desiatky dlaždíc paralelne.

**V produkcii** by som nasadil nginx + php-fpm. Nginx by cez `try_files` servíroval hotové dlaždice
zo `storage/tiles` priamo z disku, bez spustenia PHP.

## 11. Dlaždice: Mapbox Vector Tiles priamo z PostGIS

**Možnosti:**
- GeoJSON podľa výrezu mapy: veľké odpovede a prekresľovanie na CPU.
- Rastrové dlaždice: nedá sa kliknúť na parcelu.
- Statické dlaždice vopred (napr. tippecanoe): ďalší nástroj mimo PHP.
- MVT generované v PostGIS.

**Voľba:** jeden SQL dotaz s `ST_TileEnvelope` → `&&` (GIST index) → `ST_AsMVTGeom` → `ST_AsMVT`.
- Dlaždica je binárny protobuf s geometriou zaokrúhlenou na mriežku 4096 × 4096 a kreslí ju GPU.
- Dve vrstvy (`cadastral_areas`, `parcels`) sa spoja operátorom `||`.
- Dlaždica nesie iba `id` (ako feature id) a `land_type_code` na farbu. Ostatné údaje sa načítajú až pri kliknutí.

## 12. Parcely až od zoomu 14

**Namerané** (centrum Jičína, bez kompresie):

| zoom | veľkosť dlaždice |
|---|---|
| 13 (s parcelami) | 476 kB |
| 14 | 208 kB |
| 16 | 23 kB |

Obrazovka pri z13 potrebuje 12–15 dlaždíc, teda 4–6 MB, a parcely sú pritom len pár pixelov veľké.
**Voľba:** parcely od z14, pod tým iba hranice KÚ. Dlaždica z13 má po zmene 2 kB.
Frontend pri oddialení zobrazí výzvu „Přibližte mapu“.

## 13. Cache dlaždíc na disku, uložená už skomprimovaná

- **Disková cache** `storage/tiles/z/x/y.pbf.gz`: prvá požiadavka dlaždicu vygeneruje (20–160 ms), každá ďalšia ju iba prečíta (~2–3 ms).
- **Atomický zápis:** dlaždica sa zapíše do dočasného súboru a potom sa premenuje (`rename`). Paralelný worker tak nikdy neprečíta rozpísaný súbor.
- **Gzip pri uložení:** šetrí 25–50 % prenosu (z14: 208 kB → 141 kB) a CPU na kompresiu sa minie iba raz. Klientovi bez `Accept-Encoding: gzip` sa dlaždica rozbalí za behu.
- **Prázdne dlaždice sa necachujú** a vracajú `204`. Inak by sa disk dal zaplniť požiadavkami na dlaždice mimo okresu.
- **Lenivé pripojenie k DB:** pri zásahu do cache PHP vôbec neotvorí spojenie s Postgresom.
- **`Cache-Control: public, max-age=86400`:** opakovane zobrazené dlaždice si drží aj prehliadač.
- **Invalidácia:** import na konci cache vymaže. Priečinok sa najprv premenuje a až potom zmaže, takže bežiaci server nikdy nevidí zmes starých a nových dlaždíc.

## 14. Predgenerovanie dlaždíc celého okresu

`bin/warm-tiles.php` vypočíta z bboxu okresu všetky dlaždice z8–16 a rozdelí ich medzi procesy (`pcntl_fork`).
- Každý proces má vlastné DB spojenie, lebo zdieľaný socket by sa po forku rozbil.
- Dlaždice sa delia striedavo, takže každý proces dostane rovnaký mix ľahkých aj ťažkých zoomov.
- Logiku „cache → generovanie → gzip“ zdieľa web aj skript cez `TileService`.

**Namerané:** 12 498 dlaždíc, z toho 8 413 neprázdnych (37,5 MB), za **7,3 s** so 6 procesmi.
Po predgenerovaní príde každá dlaždica okresu z disku.

**Nájdená chyba:** súbežné procesy vytvárali rovnaký priečinok a `mkdir` hlásil warning. Logika už bola
odolná (`!is_dir() && !mkdir() && !is_dir()`), warning som potlačil iba na tomto mieste.

## 15. Frontend: MapLibre GL JS bez build kroku

**Možnosti:** Leaflet (vektorové dlaždice iba cez pluginy), OpenLayers, MapLibre GL JS. Načítanie cez CDN alebo npm + bundler.

**Voľba:** MapLibre GL JS 5.24.0 z CDN s **pevnou verziou a SRI hashom**, jeden súbor `app.js` bez frameworku.
- Vektorové dlaždice sa kreslia na GPU. Farbu podľa druhu pozemku počíta výraz `match` priamo v štýle.
- Zdroj má `maxzoom: 16`, pri väčšom priblížení sa dlaždice z16 iba zväčšujú. Server tak nikdy negeneruje z17+.
- Aktuálny pohľad sa ukladá do URL (`hash: true`), dá sa zdieľať a prežije obnovenie stránky.

## 16. Detail parcely

- **Výber bez servera:** parcelu pod kurzorom nájde `queryRenderedFeatures` v už stiahnutej dlaždici. Server sa pýta iba na detail.
- **Jeden SQL dotaz** spojí parcelu s KÚ, obcou a číselníkmi. Spôsoby ochrany vráti Postgres ako JSON pole (`json_agg`). Odozva je ~10 ms.
- **Zvýraznenie** robí filter na samostatnej vrstve podľa feature id, takže sa nič nesťahuje znova.
- **Race condition:** pri rýchlom klikaní `AbortController` zruší predchádzajúcu požiadavku, aby starší pomalší výsledok neprepísal novší.
- **XSS:** údaje sa vkladajú iba cez `textContent`.
- **Vlastníci** sa nezobrazujú. Služba ČÚZK (WSDP) vyžaduje registrovaný účet, preto detail obsahuje odkaz do Nahlížení do KN.

## 17. Hľadanie parcely

**Možnosti:** jedno textové pole („Jičín st. 1“) alebo dve polia: KÚ s našepkávačom a číslo parcely.

**Voľba:** dve polia. Je to jednoznačné a zodpovedá to tomu, ako hľadá Nahlížení do KN.
- Číslo sa parsuje na serveri (`st. 12/3`, `ST12`, `791 / 34`). Zlý vstup vracia 400, vrátane podvrhnutého poľa `?number[]=`.
- Index `UNIQUE (cadastral_area_code, numbering_type, stem_number, subdivision_number) NULLS NOT DISTINCT`:
  - zrýchli hľadanie (Index Scan);
  - zaručí, že sa číslo parcely v KÚ neopakuje (`NULLS NOT DISTINCT` je nutné, lebo poddelenie býva `NULL`);
  - nahradil starší index na `cadastral_area_code`, lebo jeho prvý stĺpec pokrýva aj mazanie pri importe.
- Dotaz má dve podoby (`subdivision_number IS NULL` / `= ?`). Kratší zápis `IS NOT DISTINCT FROM ?` by index nepoužil.
- **Našepkávač KÚ je vlastný, nie natívny `<datalist>`.** Datalist filtruje podľa presného textu, takže pri „jicin“
  „Jičín“ neponúkne, a v každom prehliadači vyzerá inak. Vlastný ARIA combobox:
  - porovnáva bez diakritiky a podľa ľubovoľnej časti názvu, pričom zhody na začiatku názvu idú prvé;
  - zvýrazní zhodu a ovláda sa šípkami, Enterom a Escape.
  - Overil som, že žiadne dve zo 240 KÚ po odstránení diakritiky nesplynú.
- Po nájdení mapa priletí na parcelu a otvorí detail. Zoom je zdola obmedzený na 14, aby boli parcely vidieť aj pri veľkých lesných a poľných parcelách.

## 18. Ortofoto ČÚZK ako alternatívny podklad

Dlaždicová služba ČÚZK `ORTOFOTO_WM` je priamo v EPSG:3857, takže stačí bežný rastrový zdroj.
Vrstva je predvolene vypnutá a dlaždice sa začnú sťahovať až po prepnutí.
Pri ortofote sa výplň parciel stlmí na 20 %.

## 19. Testy: unit testy na miestach s najvyšším rizikom

Počas vývoja som každý krok overoval ručne: `curl` na API, kontroly v DB (počty, `ST_IsValid`, súčet plôch)
a headless prehliadač so screenshotmi. Na záver som doplnil PHPUnit (jediná vývojová závislosť) na tri miesta,
kde by sa chyba najľahšie skryla:
- **`GmlToWkt`:**
  - polygón, diera, kružnica a skutočná parcela z VFR s oblúkom (`ArcString`);
  - multi-polygón;
  - 8 druhov neplatného vstupu.
- **`ParcelNumber`:**
  - parsovanie a formátovanie čísla parcely (`st. 12/3`, `ST12`, `791 / 34`);
  - spätný prevod text → číslo → text dá rovnakú hodnotu;
  - 11 neplatných vstupov vrátane pokusu o SQL injection.
  - Logiku som kvôli tomu vytiahol z controllera do samostatnej triedy, ktorú používa aj formátovanie v detaile.
- **`Router`:**
  - pomenované parametre nezávisle od poradia skupín v regexe;
  - ukotvenie regexu, poradie trás, 404 a 405.

Spolu 41 testov. Či testy naozaj niečo strážia, som overil mutáciami: odstránenie `^…$` v routeri aj
povolenie neobmedzenej dĺžky čísla parcely testy zachytili.

**Nájdená chyba:** test kružnice odhalil, že pri výpočte stredu môže vzniknúť záporná nula a WKT obsahovalo `-0`.
PostGIS ju načíta ako 0, ale výstup nebol kanonický. Všetky súradnice teraz prechádzajú jednou formátovacou
metódou. Po oprave som zopakoval celý import: 271 995 parciel, 0 nevalidných.

## 20. Nasadenie: Docker image s FrankenPHP na Renderi

**Možnosti**
- **Vercel:** nemá natívne PHP ani PostgreSQL a disk je dočasný, takže cache dlaždíc by nefungovala.
- **PaaS s Dockerom (Render, Railway).**
- **Vlastný VPS.**
- **Tunel z lokálneho PC.**

**Voľba:** Render, ktorý ponúka zadarmo web service z Dockerfile aj PostgreSQL s PostGIS.
Infraštruktúra je popísaná v `render.yaml`.

- **Server:** FrankenPHP (Caddy so vstavaným PHP) v jednom kontajneri.
  - Statické súbory servíruje sám a odpovede komprimuje (zstd/gzip).
  - Port berie z premennej `PORT`, ktorú PaaS nastavuje.
  - Tým odpadol rozdiel medzi `php -S` lokálne a nginx v produkcii.
  - Rovnaký image beží aj v `docker compose`, takže na spustenie stačí Docker.
- **Konfigurácia:** `config.php` číta okrem `DB_*` aj `DATABASE_URL`, ktorý PaaS poskytuje ako jednu premennú.
- **Dáta:** import sa na serveri nespúšťa. Lokálna DB sa prenesie cez `pg_dump`/`pg_restore` (dump má 29 MB).

**Úskalia pri nasadení**
- **`exec: frankenphp: Operation not permitted`:**
  - Binárka FrankenPHP má capability `cap_net_bind_service` (port 80 bez roota) a Render spúšťa kontajnery bez práva spúšťať takéto programy.
  - Chybu som lokálne zreprodukoval cez `docker run --cap-drop ALL`.
  - Oprava: `setcap -r` (port je nad 1024, capability netreba) a beh pod neprivilegovaným používateľom.
- **Zdedený HEALTHCHECK** z base image sa pýtal admin API Caddy, ktoré je vypnuté, a kontajner sa hlásil ako `unhealthy`. Healthcheck teraz volá priamo aplikáciu.
- **Prerušený prenos dát:**
  - Prvý pokus (dump posielaný cez rúru priamo do vzdialenej DB) sa prerušil v polovici.
  - Druhý zlyhal na schéme `tiger`, ktorú lokálny image `postgis/postgis` zakladá a Render ju už má.
  - Riešenie: dump do súboru a obnova iba objektov schémy `public` (`pg_restore -L`), 4 paralelné úlohy, ~2 min.
  - Overenie: počty riadkov aj indexy zhodné s lokálnou DB.

- **Výpadky (502) po nasadení:**
  - Render hlásil „HTTP health check failed (timed out after 5 seconds)“. Health check volal `/` a túto stránku vracalo PHP.
  - FrankenPHP mal na free inštancii iba 2 PHP vlákna. Tie obsadili dlaždice, ktoré na pomalej DB (počas predgenerovania) trvali sekundy, takže health check čakal v rade.
  - Chybu som lokálne zreprodukoval zamknutím tabuľky `parcel`: `/` odpovedal za 13,4 s.
  - Oprava:
    - Caddy vracia `/` ako statický `index.html` bez PHP. Health check teraz dokazuje, že beží server, a nezávisí od DB.
    - 8 PHP vlákien, pretože dlaždice čakajú na DB, nie na CPU.
    - Predgenerovanie na Renderi 1 procesom.
  - Po oprave `/` pri zamknutej DB odpovedá za 4 ms.

**Namerané na free pláne** (z ČR do Frankfurtu):

| | čas |
|---|---|
| dlaždica pri prvom vygenerovaní | 0,8–1,8 s |
| dlaždica z cache | 70–180 ms |
| detail parcely | ~0,6 s |

Pomalé CPU a DB na free pláne potvrdzujú, že predgenerovanie dlaždíc pri štarte (`WARM_TILES=1`) je tu dôležitejšie ako lokálne.

Celý okres má 12 498 dlaždíc, z toho 9 243 na z16. Pri ~1 s na dlaždicu by predgenerovanie trvalo ~1,5 hodiny,
a free web sa pritom po 15 minútach nečinnosti uspí a disk nie je trvalý. Na Renderi sa preto predgenerováva iba z8–z14
(`WARM_MAX_ZOOM=14`, 895 dlaždíc, niekoľko minút). Pokrýva to pohľad na okres aj prvé priblíženie s parcelami.
z15–z16 sa vygenerujú pri prvom zobrazení. Na platenom pláne s trvalým diskom by sa cache predgenerovala raz celá.

## 21. Kontrola celého projektu: chyby, bezpečnosť a každodenná práca

Na záver som prešiel celý projekt znova. Nálezy som overil na bežiacej aplikácii a až potom opravil.

**Chyby**
- **Neobmedzená cache:**
  - Server generoval a ukladal dlaždice až do z22, hoci mapa používa najviac z16. Dlaždica z20 sa naozaj uložila na disk, takže požiadavkami na z17–z22 v okrese sa dal zaplniť disk a zahltiť DB.
  - Limit je teraz jedna konštanta `TileService::MAX_ZOOM = 16`, ktorú používa controller aj predgenerovanie.
- **Dátum „Platí od“:** `new Date('2006-05-28')` je polnoc v UTC, takže v pásmach západne od UTC sa zobrazil predchádzajúci deň. Dátum sa teraz skladá v miestnom čase (overené pre New York, Prahu a Auckland).
- **`HEAD`:** vracal 405, takže monitorovacie nástroje by hlásili výpadok. Router teraz obsluhuje HEAD rovnakou trasou ako GET a v hlavičke `Allow` uvádza obe metódy.
- **`/api/health`:** počítal všetky riadky (`count(*)`), na Renderi 1,8 s. Teraz berie odhad zo štatistík DB (11 ms).

**Bezpečnosť a kvalita**
- **HTTP hlavičky v Caddy:**
  - CSP povoľuje iba vlastné skripty, MapLibre z unpkg a podklady OSM a ČÚZK; MapLibre potrebuje `blob:` pre web workery.
  - Ďalej `nosniff`, `Referrer-Policy` (OSM vyžaduje pôvod), `frame-ancestors 'none'` a odstránená hlavička `X-Powered-By`.
  - Celé UI som overil v prehliadači bez porušení CSP.
- **`LazyConnection`:** typovaná trieda nahradila netypované closures a trikrát opakovanú kontrolu `instanceof PDO` v repository.

**Každodenná práca s mapou**
- **Čísla parciel v mape od z17:**
  - Dlaždice z16 majú textové označenie parcely a samostatnú vrstvu bodov popisiek (`ST_PointOnSurface`). Bod je vždy vo vnútri parcely a každá parcela má jednu popisku bez duplikátov na hranách dlaždíc.
  - Popisky sú iba v z16, lebo v z14 by dlaždicu zväčšili o 30 %. Cache narástla o 12 %.
  - Font (Noto Sans, licencia OFL) je pribalený v projekte. Popisky obsahujú iba základné znaky, takže stačí jeden 77 kB súbor a CSP nemusí povoliť cudzí server.
- **Zvýraznenie pod kurzorom:** cez `feature-state` sa prepína iba stav na GPU. Od z16 sa zobrazí aj bublina s číslom parcely.
- **Odkaz na parcelu:**
  - URL má tvar `#map=…&parcel=…`, s pomenovaným hash parametrom MapLibre. Stačí aj `#parcel=…`, detail obsahuje bbox a mapa na parcelu priletí.
  - Pri ladení som našiel súbeh: o prelete som rozhodoval až po načítaní mapy, keď už MapLibre do URL zapísal úvodnú pozíciu. Rozhoduje sa preto ešte pred vytvorením mapy.
- **Kopírovanie:** tlačidlá skopírujú označenie parcely (`parc. č. st. 1, k. ú. Jičín [659541]`) alebo krátky odkaz.

**Mobil a spätná väzba**
- **Panely** sú natívne `<details>`: bez vlastného JS, prístupné z klávesnice a na mobile zbalené.
- **Viditeľná časť mapy:** výpočet zohľadňuje ovládanie aj detail. Prelet po hľadaní sa spustí až po zobrazení detailu, keď je známa jeho výška. Kliknutá parcela, ktorú by detail zakryl, sa posunie do viditeľnej časti.
- **Chyba na mobile:** pri otvorenom formulári a detaile ostalo pre mapu ~100 px a `cameraForBounds` nevrátil nič. Formulár sa preto po úspešnom hľadaní zbalí a pre takýto prípad je záložné vycentrovanie.
- **Indikátor načítavania a chyby:** „Načítám parcely…“ sa zobrazí, ak načítanie trvá dlhšie ako 300 ms, aby krátke načítania neblikali. Chyba dlaždíc sa zobrazí ako hlásenie nad mapou. Overené simulovaným pomalým serverom a odpoveďou 500.

**Cache v prehliadači po nasadení**
- **Chyba po nasadení:** po pridaní `bbox` do detailu parcely spadol prelet na parcelu („Cannot read properties of undefined“). Prehliadač mal detail z predchádzajúcej verzie ešte v cache (`max-age=3600`) a v tej `bbox` chýbal.
- **Rovnaké riziko** platilo pre dlaždice (cache 1 deň, bez čísel parciel) aj pre `app.js`, ktorý nemal nastavené cachovanie, takže si ho prehliadač mohol heuristicky podržať.
- **Riešenie:**
  - Všetky volania API a dlaždíc nesú verziu (`?v=2`). Pri zmene formátu dát sa zvýši `API_VERSION` a prehliadač stiahne nové odpovede, pričom dlhú cache pre nezmenené dáta netreba skracovať.
  - `index.html`, `app.js` a `style.css` majú `Cache-Control: no-cache`. Prehliadač sa vždy spýta, či sú aktuálne, a pri nezmenenom súbore dostane 304.
  - Prelet bez platného `bbox` sa preskočí, takže detail sa zobrazí aj vtedy.

## Známe obmedzenia a ďalšie kroky

- **Integračné testy API** (s testovacou DB) chýbajú. Endpointy som overoval ručne cez `curl` vrátane chybových stavov.
- **OSM dlaždice** (`tile.openstreetmap.org`) sú podľa podmienok použitia vhodné iba na demo. V produkcii by bol vlastný alebo komerčný podklad.
- **`php -S` je vývojový server.** V produkcii nginx + php-fpm, pričom hotové dlaždice by servíroval nginx.
- **Aktualizácia dát** je zatiaľ úplný reimport (~35 s). VFR ponúka aj zmenové dávky a cache by sa dala invalidovať iba pre dlaždice dotknutej obce.
- **Názvy KÚ** na mape chýbajú. Stačil by rovnaký mechanizmus ako pri číslach parciel (bod popisky v dlaždici). Font by však potreboval aj súbor so znakmi s diakritikou (rozsah 256–511).
