<?php

declare(strict_types=1);

use App\Database;
use App\Import\CodeListImporter;
use App\Import\Downloader;
use App\Import\GmlToWkt;
use App\Import\ParcelWriter;
use App\Import\VfrParcelReader;

require __DIR__ . '/../vendor/autoload.php';

const JICIN_DISTRICT_CODE = 3604;
const VFR_URL = 'https://vdp.cuzk.gov.cz/vymenny_format/soucasna/%s_OB_%d_UKSH.xml.zip';

$config = require __DIR__ . '/../config.php';
$options = getopt('', ['municipality:', 'date:']);

try {
    $date = $options['date'] ?? (new DateTimeImmutable('last day of previous month'))->format('Ymd');
    $parsedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Ymd', $date) : false;
    if ($parsedDate === false || $parsedDate->format('Ymd') !== $date) {
        throw new InvalidArgumentException('Option --date must be a single date in YYYYMMDD format');
    }

    $pdo = Database::connect(...$config['db']);
    $downloader = new Downloader(__DIR__ . '/../storage/import');
    $codeLists = new CodeListImporter($pdo, $downloader);
    $reader = new VfrParcelReader(new GmlToWkt());
    $writer = new ParcelWriter($pdo);

    $codeLists->importCodeLists();
    echo "Code lists imported\n";

    $municipalityCodes = $codeLists->importDistrict(JICIN_DISTRICT_CODE);
    printf("District imported: %d municipalities\n", count($municipalityCodes));

    if (isset($options['municipality'])) {
        $selected = filter_var($options['municipality'], FILTER_VALIDATE_INT);
        if ($selected === false || !in_array($selected, $municipalityCodes, true)) {
            throw new InvalidArgumentException('Option --municipality must be a municipality code from the Jičín district');
        }
        $municipalityCodes = [$selected];
    }

    $total = 0;
    $started = microtime(true);
    foreach ($municipalityCodes as $index => $municipalityCode) {
        $zipPath = $downloader->fetch(sprintf(VFR_URL, $date, $municipalityCode));
        $municipalityStarted = microtime(true);
        $count = $writer->replaceMunicipality($municipalityCode, $reader->read($zipPath));
        $total += $count;

        printf(
            "[%d/%d] municipality %d: %d parcels in %.1fs\n",
            $index + 1,
            count($municipalityCodes),
            $municipalityCode,
            $count,
            microtime(true) - $municipalityStarted,
        );
    }

    $pdo->exec('ANALYZE parcel, parcel_protection');
    printf("Done: %d parcels in %.1fs (VFR %s)\n", $total, microtime(true) - $started, $date);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
