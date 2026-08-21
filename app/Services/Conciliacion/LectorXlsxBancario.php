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
                $text = (string) ($si->t ?? '');
                foreach ($si->r as $fragment) {
                    $text .= (string) $fragment->t;
                }
                $shared[] = $text;
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
                $column = $this->columnIndex($m[0]);
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

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
