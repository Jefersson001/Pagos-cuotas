<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class XlsxReader
{
    /** @return array{rows: array<int, array<string, string>>, date1904: bool} */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Excel.');
        }
        try {
            $xml = function (string $name) use ($zip): SimpleXMLElement {
                $value = $zip->getFromName($name);
                if ($value === false || strlen($value) > 10000000) {
                    throw new RuntimeException('El Excel no tiene una estructura compatible.');
                }
                $parsed = simplexml_load_string($value, SimpleXMLElement::class, LIBXML_NONET);
                if ($parsed === false) {
                    throw new RuntimeException('El Excel contiene XML inválido.');
                }

                return $parsed;
            };
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($xml('xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $item) {
                    $strings[] = implode('', array_map(fn ($node) => (string) $node, $item->xpath('.//*[local-name()="t"]')));
                }
            }
            $rows = [];
            foreach ($xml('xl/worksheets/sheet1.xml')->xpath('//*[local-name()="row"]') as $row) {
                $values = [];
                foreach ($row->children() as $cell) {
                    $column = preg_replace('/[0-9]/', '', (string) $cell['r']);
                    $values[$column] = match ((string) $cell['t']) {
                        's' => $strings[(int) $cell->v] ?? '',
                        'inlineStr' => implode('', array_map(fn ($node) => (string) $node, $cell->xpath('.//*[local-name()="t"]'))),
                        default => (string) $cell->v,
                    };
                }
                $rows[(int) $row['r']] = $values;
            }
            $properties = $xml('xl/workbook.xml')->xpath('//*[local-name()="workbookPr"]');

            return ['rows' => $rows, 'date1904' => isset($properties[0]) && in_array((string) $properties[0]['date1904'], ['1', 'true'], true)];
        } finally {
            $zip->close();
        }
    }
}
