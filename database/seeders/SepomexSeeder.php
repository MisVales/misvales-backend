<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SepomexSeeder extends Seeder
{
    private const BATCH_SIZE = 2_000;

    public function run(): void
    {
        $path = database_path('seeders/data/sepomex_seeder.csv');

        if (! is_file($path)) {
            throw new RuntimeException("No existe el CSV de SEPOMEX requerido: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("El CSV de SEPOMEX no es legible: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No fue posible abrir el CSV de SEPOMEX: {$path}");
        }

        DB::disableQueryLog();

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            $this->validateHeader($header);

            [$states, $municipalities, $postalCodes] = $this->existingCatalog();
            $nextStateId = ((int) DB::table('estados')->max('id')) + 1;
            $nextMunicipalityId = ((int) DB::table('municipios')->max('id')) + 1;
            $nextPostalCodeId = ((int) DB::table('codigos_postales')->max('id')) + 1;
            $stateBatch = [];
            $municipalityBatch = [];
            $postalCodeBatch = [];
            $settlementBatch = [];
            $processed = 0;
            $inserted = 0;
            $rowNumber = 1;
            $timestamp = now();

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                $this->validateRow($row, $rowNumber);

                $postalCode = trim((string) $row[0]);
                $settlementName = trim((string) $row[1]);
                $settlementType = trim((string) $row[2]);
                $municipalityName = trim((string) $row[3]);
                $stateName = trim((string) $row[4]);

                if (! isset($states[$stateName])) {
                    $states[$stateName] = $nextStateId++;
                    $stateBatch[] = ['id' => $states[$stateName], 'name' => $stateName, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }

                $stateId = $states[$stateName];
                $municipalityKey = $this->key($stateId, $municipalityName);

                if (! isset($municipalities[$municipalityKey])) {
                    $municipalities[$municipalityKey] = $nextMunicipalityId++;
                    $municipalityBatch[] = ['id' => $municipalities[$municipalityKey], 'estado_id' => $stateId, 'name' => $municipalityName, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }

                $municipalityId = $municipalities[$municipalityKey];
                $postalCodeKey = $this->key($municipalityId, $postalCode);

                if (! isset($postalCodes[$postalCodeKey])) {
                    $postalCodes[$postalCodeKey] = $nextPostalCodeId++;
                    $postalCodeBatch[] = ['id' => $postalCodes[$postalCodeKey], 'municipio_id' => $municipalityId, 'code' => $postalCode, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }

                $postalCodeId = $postalCodes[$postalCodeKey];
                $settlementBatch[] = [
                    'codigo_postal_id' => $postalCodeId,
                    'name' => $settlementName,
                    'settlement_type' => $settlementType !== '' ? $settlementType : null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $inserted++;

                $processed++;

                if (count($settlementBatch) >= self::BATCH_SIZE) {
                    $this->flush($stateBatch, $municipalityBatch, $postalCodeBatch, $settlementBatch);
                }
            }

            $this->flush($stateBatch, $municipalityBatch, $postalCodeBatch, $settlementBatch);
            $this->synchronizeSequences();
            $this->command?->info("SEPOMEX: {$processed} filas procesadas; {$inserted} colonias nuevas.");
        } finally {
            fclose($handle);
        }
    }

    /** @return array{array<string, int>, array<string, int>, array<string, int>} */
    private function existingCatalog(): array
    {
        $states = DB::table('estados')->pluck('id', 'name')->map(fn ($id): int => (int) $id)->all();
        $municipalities = [];
        $postalCodes = [];

        foreach (DB::table('municipios')->get(['id', 'estado_id', 'name']) as $municipality) {
            $municipalities[$this->key($municipality->estado_id, $municipality->name)] = (int) $municipality->id;
        }

        foreach (DB::table('codigos_postales')->get(['id', 'municipio_id', 'code']) as $postalCode) {
            $postalCodes[$this->key($postalCode->municipio_id, $postalCode->code)] = (int) $postalCode->id;
        }

        return [$states, $municipalities, $postalCodes];
    }

    /** @param list<string>|false $header */
    private function validateHeader(array|false $header): void
    {
        if ($header === false) {
            throw new RuntimeException('El CSV de SEPOMEX está vacío.');
        }

        $header[0] = ltrim((string) ($header[0] ?? ''), "\xEF\xBB\xBF");
        $expected = ['codigo_postal', 'asentamiento', 'tipo_asentamiento', 'municipio', 'estado'];

        if (array_slice($header, 0, 5) !== $expected) {
            throw new RuntimeException('El encabezado del CSV de SEPOMEX no coincide con el contrato esperado.');
        }
    }

    /** @param list<string|null> $row */
    private function validateRow(array $row, int $rowNumber): void
    {
        if (count($row) < 5) {
            throw new RuntimeException("Fila {$rowNumber} inválida en el CSV de SEPOMEX: se esperaban al menos cinco columnas.");
        }

        foreach ([0, 1, 3, 4] as $requiredColumn) {
            if (trim((string) ($row[$requiredColumn] ?? '')) === '') {
                throw new RuntimeException("Fila {$rowNumber} inválida en el CSV de SEPOMEX: contiene una columna obligatoria vacía.");
            }
        }
    }

    /** @param list<array<string, mixed>> $states
     * @param  list<array<string, mixed>>  $municipalities
     * @param  list<array<string, mixed>>  $postalCodes
     * @param  list<array<string, mixed>>  $settlements
     */
    private function flush(array &$states, array &$municipalities, array &$postalCodes, array &$settlements): void
    {
        DB::transaction(function () use (&$states, &$municipalities, &$postalCodes, &$settlements): void {
            if ($states !== []) {
                DB::table('estados')->insert($states);
            }
            if ($municipalities !== []) {
                DB::table('municipios')->insert($municipalities);
            }
            if ($postalCodes !== []) {
                DB::table('codigos_postales')->insert($postalCodes);
            }
            if ($settlements !== []) {
                DB::table('colonias')->insertOrIgnore($settlements);
            }
        });

        $states = [];
        $municipalities = [];
        $postalCodes = [];
        $settlements = [];
    }

    private function synchronizeSequences(): void
    {
        foreach (['estados', 'municipios', 'codigos_postales', 'colonias'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)");
        }
    }

    private function key(int|string $first, int|string $second, int|string $third = ''): string
    {
        return implode("\x1F", [(string) $first, (string) $second, (string) $third]);
    }
}
