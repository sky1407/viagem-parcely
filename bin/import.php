<?php

declare(strict_types=1);

use App\Database;
use App\Import\CodeListImporter;
use App\Import\Downloader;

require __DIR__ . '/../vendor/autoload.php';

const JICIN_DISTRICT_CODE = 3604;

$config = require __DIR__ . '/../config.php';

try {
    $pdo = Database::connect(...$config['db']);
    $downloader = new Downloader(__DIR__ . '/../storage/import');
    $codeLists = new CodeListImporter($pdo, $downloader);

    $codeLists->importCodeLists();
    echo "Code lists imported\n";

    $municipalityCodes = $codeLists->importDistrict(JICIN_DISTRICT_CODE);
    printf("District imported: %d municipalities\n", count($municipalityCodes));
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
