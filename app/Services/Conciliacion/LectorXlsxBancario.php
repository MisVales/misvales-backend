<?php

namespace App\Services\Conciliacion;

use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

final class LectorXlsxBancario
{
    /** Verifica que el archivo sea un libro XLSX real antes de procesarlo. */
    public function validar(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('BANK_FILE_INVALID_FORMAT');
        }

        try {
            $contentTypes = $zip->getFromName('[Content_Types].xml');
            $isXlsx = $contentTypes !== false
                && str_contains(
                    $contentTypes,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
                )
                && $zip->locateName('xl/workbook.xml') !== false
                && $zip->locateName('xl/worksheets/sheet1.xml') !== false;
        } finally {
            $zip->close();
        }

        if (! $isXlsx) {
            throw new RuntimeException('BANK_FILE_INVALID_FORMAT');
        }
    }

    public function leer(string $path): array
    {
        $this->validar($path);

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('BANK_FILE_CORRUPT');
        }

        try {
            $shared = $this->leerCadenasCompartidas($zip);
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
        }

        if ($sheet === false) {
            throw new RuntimeException('BANK_FILE_CORRUPT');
        }

        try {
            $rows = [];
            foreach ((new SimpleXMLElement($sheet))->sheetData->row as $row) {
                $values = [];
                foreach ($row->c as $cell) {
                    $ref = (string) $cell['r'];
                    if (preg_match('/^[A-Z]+/', $ref, $matches) !== 1) {
                        throw new RuntimeException('BANK_FILE_CORRUPT');
                    }

                    $column = $this->columnIndex($matches[0]);
                    $value = (string) $cell->v;
                    if ((string) $cell['t'] === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ((string) $cell['t'] === 'inlineStr') {
                        $value = (string) $cell->is->t;
                    }
                    $values[$column] = $value;
                }
                $rows[] = $values;
            }

            return $rows;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('BANK_FILE_CORRUPT', 0, $exception);
        }
    }

    /** @return array<int, string> */
    private function leerCadenasCompartidas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        try {
            $shared = [];
            foreach ((new SimpleXMLElement($xml))->si as $item) {
                $text = (string) ($item->t ?? '');
                foreach ($item->r as $fragment) {
                    $text .= (string) $fragment->t;
                }
                $shared[] = $text;
            }

            return $shared;
        } catch (Throwable $exception) {
            throw new RuntimeException('BANK_FILE_CORRUPT', 0, $exception);
        }
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
