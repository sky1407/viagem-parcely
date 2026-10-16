<?php

declare(strict_types=1);

namespace App\Import;

use DOMDocument;
use DOMElement;
use Generator;
use RuntimeException;
use XMLReader;

final class VfrParcelReader
{
    private const VF_NAMESPACE = 'urn:cz:isvs:ruian:schemas:VymennyFormatTypy:v1';

    public function __construct(private readonly GmlToWkt $gmlToWkt)
    {
    }

    public function read(string $zipPath): Generator
    {
        foreach ($this->elements($zipPath, 'Parcela') as $element) {
            yield $this->parcel($element);
        }
    }

    public function readCadastralAreaBoundaries(string $zipPath): array
    {
        foreach ($this->elements($zipPath, 'KatastralniUzemi') as $container) {
            $boundaries = [];
            foreach ($container->getElementsByTagNameNS(self::VF_NAMESPACE, 'KatastralniUzemi') as $area) {
                $fields = $this->childrenByName($area);
                $code = $this->int($this->required($fields, 'Kod', $area)->textContent);
                $boundary = $this->required($fields, 'Geometrie', $area)
                    ->getElementsByTagNameNS('*', 'OriginalniHranice')
                    ->item(0);
                if (!$boundary instanceof DOMElement || $boundary->firstElementChild === null) {
                    throw new RuntimeException("Missing boundary geometry of cadastral area {$code}");
                }
                $boundaries[$code] = $this->gmlToWkt->multiSurface($boundary->firstElementChild);
            }

            return $boundaries;
        }

        throw new RuntimeException("No cadastral areas in {$zipPath}");
    }

    private function elements(string $zipPath, string $localName): Generator
    {
        $uri = 'zip://' . $zipPath . '#' . basename($zipPath, '.zip');
        $previousErrorMode = libxml_use_internal_errors(true);
        $reader = new XMLReader();

        try {
            if (!$reader->open($uri)) {
                throw new RuntimeException("Cannot open {$uri}");
            }

            $document = new DOMDocument();
            $hasNode = $reader->read();
            while ($hasNode) {
                if ($reader->nodeType === XMLReader::ELEMENT
                    && $reader->localName === $localName
                    && $reader->namespaceURI === self::VF_NAMESPACE
                ) {
                    $element = $reader->expand($document);
                    if (!$element instanceof DOMElement) {
                        throw new RuntimeException("Cannot expand {$localName} element in {$uri}");
                    }
                    yield $element;
                    $hasNode = $reader->next();
                } else {
                    $hasNode = $reader->read();
                }
            }

            $error = libxml_get_last_error();
            if ($error !== false) {
                throw new RuntimeException("Invalid XML in {$uri}: " . trim($error->message));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function parcel(DOMElement $element): Parcel
    {
        $fields = $this->childrenByName($element);
        $id = $this->int($this->required($fields, 'Id', $element)->textContent);

        try {
            $cadastralArea = $this->required($fields, 'KatastralniUzemi', $element)->firstElementChild
                ?? throw new RuntimeException('Missing cadastral area code');

            $boundary = $this->required($fields, 'Geometrie', $element)
                ->getElementsByTagNameNS('*', 'OriginalniHranice')
                ->item(0);
            if (!$boundary instanceof DOMElement || $boundary->firstElementChild === null) {
                throw new RuntimeException('Missing boundary geometry');
            }

            $protectionCodes = [];
            if (isset($fields['ZpusobyOchranyPozemku'])) {
                foreach ($fields['ZpusobyOchranyPozemku']->getElementsByTagNameNS('*', 'ZpusobOchrany') as $protection) {
                    $code = $protection->firstElementChild ?? throw new RuntimeException('Missing protection code');
                    $protectionCodes[] = $this->int($code->textContent);
                }
            }

            return new Parcel(
                id: $id,
                cadastralAreaCode: $this->int($cadastralArea->textContent),
                numberingType: $this->int($this->required($fields, 'DruhCislovaniKod', $element)->textContent),
                stemNumber: $this->int($this->required($fields, 'KmenoveCislo', $element)->textContent),
                subdivisionNumber: isset($fields['PododdeleniCisla']) ? $this->int($fields['PododdeleniCisla']->textContent) : null,
                areaM2: $this->int($this->required($fields, 'VymeraParcely', $element)->textContent),
                landTypeCode: $this->int($this->required($fields, 'DruhPozemkuKod', $element)->textContent),
                landUseCode: isset($fields['ZpusobyVyuzitiPozemku']) ? $this->int($fields['ZpusobyVyuzitiPozemku']->textContent) : null,
                validFrom: $this->required($fields, 'PlatiOd', $element)->textContent,
                protectionCodes: array_values(array_unique($protectionCodes)),
                geometryWkt: $this->gmlToWkt->polygon($boundary->firstElementChild),
            );
        } catch (RuntimeException $e) {
            throw new RuntimeException("Parcel {$id}: {$e->getMessage()}", previous: $e);
        }
    }

    private function childrenByName(DOMElement $element): array
    {
        $children = [];
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $children[$child->localName] = $child;
        }

        return $children;
    }

    private function required(array $fields, string $name, DOMElement $parcel): DOMElement
    {
        return $fields[$name] ?? throw new RuntimeException(
            "Missing element {$name} in parcel " . ($fields['Id']->textContent ?? $parcel->getAttribute('gml:id'))
        );
    }

    private function int(string $value): int
    {
        $result = filter_var(trim($value), FILTER_VALIDATE_INT);
        if ($result === false) {
            throw new RuntimeException("Invalid integer: {$value}");
        }

        return $result;
    }
}
