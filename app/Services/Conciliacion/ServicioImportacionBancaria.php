<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\ImportacionArchivoBancario;
use App\Models\MovimientoBancario;
use App\Models\RelacionDistribuidora;
use App\Models\User;
use App\Services\Pago\ServicioAplicacionPago;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ServicioImportacionBancaria
{
    private const HEADERS = ['referencia de pago', 'monto', 'fecha', 'folio bancario', 'concepto'];

    public function __construct(
        private LectorXlsxBancario $reader,
        private ServicioAplicacionPago $payments,
        private AuditorConciliacion $auditor
    ) {}

    public function importar(UploadedFile $file, User $actor, string $branchId): ImportacionArchivoBancario
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $existingImport = ImportacionArchivoBancario::query()->where('file_hash', $hash)->first();

        if ($existingImport !== null) {
            if ($existingImport->branch_id !== $branchId) {
                throw new ExcepcionConciliacion('BANK_FILE_ALREADY_IMPORTED_OUTSIDE_SCOPE', 'El archivo ya fue registrado fuera de la sucursal autorizada.', 409);
            }

            $existingImport->setAttribute('replayed', true);
            $this->auditor->registrar('BANK_FILE_REPLAYED', 'bank_file_import', $existingImport->id, $actor, $branchId);

            return $existingImport;
        }

        $path = $file->store('bank-imports', 'local');
        if ($path === false) {
            throw new ExcepcionConciliacion('BANK_FILE_STORAGE_FAILED', 'No fue posible conservar el archivo bancario.', 500);
        }

        $import = ImportacionArchivoBancario::query()->create([
            'private_path' => $path,
            'original_name' => basename($file->getClientOriginalName()),
            'file_size' => $file->getSize(),
            'file_hash' => $hash,
            'uploaded_by' => $actor->id,
            'branch_id' => $branchId,
            'status' => 'REJECTED',
        ]);

        try {
            $rows = $this->reader->leer(Storage::disk('local')->path($path));
            [$map, $movements] = $this->validarArchivo($rows);
            $summary = ['partial_payments' => 0, 'settlements' => 0, 'surpluses' => 0, 'unreconciled' => 0, 'duplicates' => 0];

            DB::transaction(function () use ($movements, $map, $import, $actor, $branchId, &$summary): void {
                foreach ($movements as $row) {
                    $movement = $this->procesarFila($row['values'], $row['row_number'], $map, $import, $actor);
                    $summary[$this->summaryKey($movement->classification)]++;
                }

                $import->update([
                    'status' => 'PROCESSED',
                    'row_count' => count($movements),
                    'summary' => $summary,
                    'error' => null,
                    'processed_at' => now(),
                ]);
                $this->auditor->registrar('BANK_FILE_PROCESSED', 'bank_file_import', $import->id, $actor, $branchId, null, [
                    'row_count' => count($movements),
                    'summary' => $summary,
                ]);
            }, 3);

            return $import->fresh()->setAttribute('replayed', false);
        } catch (Throwable $exception) {
            $domainException = $this->normalizarError($exception);
            $import->update(['status' => 'REJECTED', 'error' => $domainException->errorCode, 'processed_at' => now()]);
            $this->auditor->registrar(
                'BANK_FILE_REJECTED',
                'bank_file_import',
                $import->id,
                $actor,
                $branchId,
                null,
                ['error_code' => $domainException->errorCode],
                null,
                null,
                null,
                'REJECTED'
            );

            throw $domainException;
        }
    }

    private function validarArchivo(array $rows): array
    {
        $headers = array_map(
            fn ($value): string => Str::of((string) $value)->squish()->lower()->toString(),
            array_values($rows[0] ?? [])
        );
        $missing = array_values(array_diff(self::HEADERS, $headers));
        if ($missing !== []) {
            throw new ExcepcionConciliacion(
                'BANK_FILE_REQUIRED_COLUMNS_MISSING',
                'El archivo no contiene todas las columnas obligatorias.',
                422,
                ['file' => ['Faltan columnas obligatorias: '.implode(', ', $missing).'.']],
                ['missing_columns' => $missing]
            );
        }

        $map = array_flip($headers);
        $movements = [];
        $rowErrors = [];
        foreach (array_slice($rows, 1) as $offset => $raw) {
            $values = array_replace(array_fill(0, count($headers), ''), $raw);
            ksort($values);
            $values = array_values($values);
            if (collect($values)->every(fn ($value): bool => trim((string) $value) === '')) {
                continue;
            }

            $rowNumber = $offset + 2;
            $reference = trim((string) Arr::get($values, $map['referencia de pago'], ''));
            $folio = trim((string) Arr::get($values, $map['folio bancario'], ''));
            $concept = trim((string) Arr::get($values, $map['concepto'], ''));
            $amount = $this->normalizarMonto(Arr::get($values, $map['monto']));
            $date = $this->normalizarFecha(Arr::get($values, $map['fecha']));
            $errors = [];

            if ($reference === '' || Str::length($reference) > 64) {
                $errors[] = 'referencia de pago';
            }
            if ($folio === '' || Str::length($folio) > 100) {
                $errors[] = 'folio bancario';
            }
            if ($amount === null || bccomp($amount, '0', 4) <= 0) {
                $errors[] = 'monto';
            }
            if ($date === null) {
                $errors[] = 'fecha';
            }
            if ($concept === '') {
                $errors[] = 'concepto';
            }

            if ($errors !== []) {
                $rowErrors[(string) $rowNumber] = $errors;
            } else {
                $movements[] = ['row_number' => $rowNumber, 'values' => $values];
            }
        }

        if ($rowErrors !== []) {
            throw new ExcepcionConciliacion(
                'BANK_FILE_INVALID_ROWS',
                'El archivo contiene filas con datos obligatorios inválidos.',
                422,
                ['file' => ['Corrige las filas indicadas y vuelve a cargar el archivo.']],
                ['rows' => $rowErrors]
            );
        }
        if ($movements === []) {
            throw new ExcepcionConciliacion('BANK_FILE_EMPTY', 'El archivo no contiene movimientos bancarios.', 422);
        }

        return [$map, $movements];
    }

    private function procesarFila(array $values, int $rowNumber, array $map, ImportacionArchivoBancario $import, User $actor): MovimientoBancario
    {
        $folio = trim((string) Arr::get($values, $map['folio bancario']));
        $reference = trim((string) Arr::get($values, $map['referencia de pago']));
        $amount = $this->normalizarMonto(Arr::get($values, $map['monto']));
        $date = $this->normalizarFecha(Arr::get($values, $map['fecha']));
        $concept = trim((string) Arr::get($values, $map['concepto']));
        $rowData = compact('reference', 'amount', 'date', 'concept');
        $existingMovement = MovimientoBancario::query()->where('idempotency_bank_folio', $folio)->first();

        if ($existingMovement !== null) {
            return $this->registrarDuplicado($import, $rowNumber, $values, $rowData, $existingMovement, $actor);
        }

        try {
            $movement = DB::transaction(fn (): MovimientoBancario => MovimientoBancario::query()->create([
                'import_id' => $import->id,
                'row_number' => $rowNumber,
                'original_row' => $values,
                'payment_reference' => $reference,
                'amount' => $amount,
                'paid_at' => $date,
                'bank_folio' => $folio,
                'idempotency_bank_folio' => $folio,
                'concept' => $concept,
                'classification' => 'UNRECONCILED',
                'reconciliation_status' => 'UNRECONCILED',
            ]));
        } catch (QueryException $exception) {
            $existingMovement = MovimientoBancario::query()->where('idempotency_bank_folio', $folio)->first();
            if ($existingMovement === null) {
                throw $exception;
            }

            return $this->registrarDuplicado($import, $rowNumber, $values, $rowData, $existingMovement, $actor);
        }

        $relation = RelacionDistribuidora::query()->where('payment_reference', $reference)->lockForUpdate()->first();
        if ($relation === null) {
            $this->auditor->registrar('BANK_MOVEMENT_UNRECONCILED', 'bank_movement', $movement->id, $actor, $import->branch_id, null, [
                'bank_folio' => $folio,
                'payment_reference' => $reference,
                'amount' => $amount,
            ]);

            return $movement;
        }

        $balanceBefore = $relation->balance;
        $movement->update(['relation_id' => $relation->id, 'distributor_id' => $relation->distributor_id, 'balance_before' => $balanceBefore]);
        $this->payments->aplicar($movement, $relation);
        $movement->refresh();
        $movement->update(['reconciliation_status' => 'RECONCILED', 'reconciled_by' => $actor->id, 'reconciled_at' => now()]);

        $this->auditor->registrar(
            'BANK_MOVEMENT_AUTOMATICALLY_RECONCILED',
            'bank_movement',
            $movement->id,
            $actor,
            $import->branch_id,
            ['balance' => $balanceBefore],
            [
                'classification' => $movement->classification,
                'applied_amount' => $movement->applied_amount,
                'surplus_amount' => $movement->surplus_amount,
                'relation_id' => $relation->id,
                'distributor_id' => $relation->distributor_id,
            ]
        );

        return $movement->fresh();
    }

    private function registrarDuplicado(ImportacionArchivoBancario $import, int $rowNumber, array $values, array $rowData, MovimientoBancario $canonical, User $actor): MovimientoBancario
    {
        $duplicate = MovimientoBancario::query()->create([
            'import_id' => $import->id,
            'row_number' => $rowNumber,
            'original_row' => $values,
            'payment_reference' => $rowData['reference'],
            'amount' => $rowData['amount'],
            'paid_at' => $rowData['date'],
            'bank_folio' => $canonical->bank_folio,
            'duplicate_of_id' => $canonical->id,
            'concept' => $rowData['concept'],
            'classification' => 'DUPLICATE',
            'reconciliation_status' => 'DUPLICATE',
            'relation_id' => $canonical->relation_id,
            'distributor_id' => $canonical->distributor_id,
            'balance_before' => $canonical->balance_before,
            'errors' => ['duplicate_of_id' => $canonical->id],
        ]);

        $this->auditor->registrar('BANK_MOVEMENT_DUPLICATE_DETECTED', 'bank_movement', $duplicate->id, $actor, $import->branch_id, null, [
            'bank_folio' => $canonical->bank_folio,
            'duplicate_of_id' => $canonical->id,
        ]);

        return $duplicate;
    }

    private function normalizarMonto(mixed $value): ?string
    {
        $normalized = str_replace([',', '$', ' '], '', (string) $value);

        return $normalized === '' || ! is_numeric($normalized) ? null : bcadd($normalized, '0', 4);
    }

    private function normalizarFecha(mixed $value): ?CarbonImmutable
    {
        try {
            if (is_numeric($value) && (float) $value > 0) {
                return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, config('app.timezone'))
                    ->addSeconds((int) round(((float) $value) * 86400));
            }

            $raw = trim((string) $value);

            return $raw === '' ? null : CarbonImmutable::parse($raw, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function summaryKey(string $classification): string
    {
        return match ($classification) {
            'PARTIAL_PAYMENT' => 'partial_payments',
            'SETTLEMENT' => 'settlements',
            'SURPLUS' => 'surpluses',
            'DUPLICATE' => 'duplicates',
            default => 'unreconciled',
        };
    }

    private function normalizarError(Throwable $exception): ExcepcionConciliacion
    {
        if ($exception instanceof ExcepcionConciliacion) {
            return $exception;
        }

        return match ($exception->getMessage()) {
            'BANK_FILE_CORRUPT' => new ExcepcionConciliacion('BANK_FILE_CORRUPT', 'El archivo XLSX está dañado o no puede leerse.', 422),
            default => new ExcepcionConciliacion('BANK_FILE_PROCESSING_FAILED', 'No fue posible procesar el archivo bancario.', 500),
        };
    }
}
