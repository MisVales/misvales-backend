<?php

namespace App\Services\Conciliacion;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class LectorXlsxBancario
{
    public function leer(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('BANK_FILE_CORRUPT');
        }$shared = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml !== false) {
            $doc = new SimpleXMLElement($xml);
            foreach ($doc->si as $si) {
                $shared[] = (string) ($si->t ?? $si->r->t);
            }
        }$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('BANK_FILE_CORRUPT');
        }$rows = [];
        foreach ((new SimpleXMLElement($sheet))->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                preg_match('/^[A-Z]+/', $ref, $m);
                $column = $m[0];
                $value = (string) $cell->v;
                if ((string) $cell['t'] === 's') {
                    $value = $shared[(int) $value] ?? '';
                } elseif ((string) $cell['t'] === 'inlineStr') {
                    $value = (string) $cell->is->t;
                }$values[$column] = $value;
            }$rows[] = $values;
        }

        return $rows;
    }
}
