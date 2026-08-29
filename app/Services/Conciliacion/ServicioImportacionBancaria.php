<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\ImportacionArchivoBancario;
use App\Models\MovimientoBancario;
use App\Models\PagoRelacion;
use App\Models\RelacionDistribuidora;
use App\Models\User;
use App\Services\Excedente\ServicioExcedente;
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
    private const HEADER_ALIASES = [
        'reference' => ['referencia de pago', 'referencia'],
        'amount' => ['monto', 'pago'],
        'date' => ['fecha', 'fecha de pago'],
        'folio' => ['folio bancario', 'folio de pago'],
        'concept' => ['concepto'],
        'time' => ['hora'],
    ];

    public function __construct(
        private LectorXlsxBancario $reader,
        private ServicioAplicacionPago $payments,
        private ServicioExcedente $surpluses,
        private AuditorConciliacion $auditor
    ) {}

    public function importar(UploadedFile $file, User $actor, string $branchId, ?string $processRunId = null): ImportacionArchivoBancario
    {
        $this->validarArchivoXlsx($file);
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
            'process_run_id' => $processRunId,
            'status' => 'REJECTED',
        ]);

        try {
            $rows = $this->reader->leer(Storage::disk('local')->path($path));
            [$map, $movements] = $this->validarArchivo($rows);
            $summary = ['partial_payments' => 0, 'settlements' => 0, 'surpluses' => 0, 'unreconciled' => 0, 'duplicates' => 0];

            DB::transaction(function () use ($movements, $map, $import, $actor, $branchId, $processRunId, &$summary): void {
                foreach ($movements as $row) {
                    $movement = $this->procesarFila($row['values'], $row['row_number'], $map, $import, $actor, $processRunId);
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
        $map = $this->resolverColumnas($headers);
        $missing = collect(['reference', 'amount', 'date', 'folio', 'concept'])
            ->reject(fn (string $key): bool => array_key_exists($key, $map))
            ->map(fn (string $key): string => self::HEADER_ALIASES[$key][0])
            ->values()
            ->all();
        if ($missing !== []) {
            throw new ExcepcionConciliacion(
                'BANK_FILE_REQUIRED_COLUMNS_MISSING',
                'El archivo no contiene todas las columnas obligatorias.',
                422,
                ['file' => ['Faltan columnas obligatorias: '.implode(', ', $missing).'.']],
                ['missing_columns' => $missing]
            );
        }

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
            $reference = trim((string) Arr::get($values, $map['reference'], ''));
            $folio = trim((string) Arr::get($values, $map['folio'], ''));
            $concept = trim((string) Arr::get($values, $map['concept'], ''));
            $amount = $this->normalizarMonto(Arr::get($values, $map['amount']));
            $date = $this->normalizarFecha(
                Arr::get($values, $map['date']),
                isset($map['time']) ? Arr::get($values, $map['time']) : null
            );
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

        return [$map, $movements];
    }

    private function procesarFila(array $values, int $rowNumber, array $map, ImportacionArchivoBancario $import, User $actor, ?string $selectedProcessRunId): MovimientoBancario
    {
        $folio = trim((string) Arr::get($values, $map['folio']));
        $reference = trim((string) Arr::get($values, $map['reference']));
        $amount = $this->normalizarMonto(Arr::get($values, $map['amount']));
        $date = $this->normalizarFecha(
            Arr::get($values, $map['date']),
            isset($map['time']) ? Arr::get($values, $map['time']) : null
        );
        $concept = trim((string) Arr::get($values, $map['concept']));
        $rowData = compact('reference', 'amount', 'date', 'concept');
        $existingMovement = MovimientoBancario::query()->where('idempotency_bank_folio', $folio)->first();

        if ($existingMovement !== null) {
            if ($existingMovement->reconciliation_status === 'UNRECONCILED') {
                $existingMovement->update([
                    'process_run_id' => $selectedProcessRunId ?? $existingMovement->process_run_id,
                    'errors' => null,
                ]);

                return $this->conciliarMovimiento($existingMovement, $actor, $import->branch_id);
            }

            return $this->registrarDuplicado($import, $rowNumber, $values, $rowData, $existingMovement, $actor);
        }

        try {
            $movement = DB::transaction(fn (): MovimientoBancario => MovimientoBancario::query()->create([
                'import_id' => $import->id,
                'process_run_id' => $selectedProcessRunId,
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

        return $this->conciliarMovimiento($movement, $actor, $import->branch_id);
    }

    private function conciliarMovimiento(MovimientoBancario $movement, User $actor, string $branchId): MovimientoBancario
    {
        $relation = RelacionDistribuidora::query()->where('payment_reference', $movement->payment_reference)->lockForUpdate()->first();
        if ($relation === null) {
            $this->auditor->registrar('BANK_MOVEMENT_UNRECONCILED', 'bank_movement', $movement->id, $actor, $branchId, null, [
                'bank_folio' => $movement->bank_folio,
                'payment_reference' => $movement->payment_reference,
                'amount' => $movement->amount,
            ]);

            return $movement;
        }

        $relation = $this->relacionVigente($relation);

        $balanceBefore = $relation->balance;
        $movement->update(['relation_id' => $relation->id, 'distributor_id' => $relation->distributor_id, 'balance_before' => $balanceBefore]);
        if (str_starts_with($movement->bank_folio, 'SALDO-FAVOR-')) {
            $creditPayment = $this->surpluses->aplicarDisponibles($relation)
                ?? PagoRelacion::query()
                    ->where('relation_id', $relation->id)
                    ->where('source_type', 'CREDIT_BALANCE')
                    ->where('source_id', $relation->id)
                    ->first();
            $relation->refresh();
            $applied = $creditPayment?->amount ?? '0.0000';
            $movement->update([
                'classification' => bccomp($relation->balance, '0', 4) === 0 ? 'SETTLEMENT' : 'PARTIAL_PAYMENT',
                'applied_amount' => $applied,
                'surplus_amount' => '0.0000',
            ]);
        } else {
            $this->payments->aplicar($movement, $relation);
        }
        $movement->refresh();
        $movement->update(['reconciliation_status' => 'RECONCILED', 'reconciled_by' => $actor->id, 'reconciled_at' => now()]);

        $this->auditor->registrar(
            'BANK_MOVEMENT_AUTOMATICALLY_RECONCILED',
            'bank_movement',
            $movement->id,
            $actor,
            $branchId,
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

    private function relacionVigente(RelacionDistribuidora $relation): RelacionDistribuidora
    {
        $visited = [];
        while ($relation->financial_status === 'ROLLED_FORWARD' && $relation->rolled_forward_to_id !== null) {
            if (isset($visited[$relation->id])) {
                throw new ExcepcionConciliacion('RELATION_ROLLOVER_CYCLE', 'La cadena de traslado de la relación es inválida.', 409);
            }

            $visited[$relation->id] = true;
            $relation = RelacionDistribuidora::query()
                ->whereKey($relation->rolled_forward_to_id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $relation;
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

    private function normalizarFecha(mixed $value, mixed $time = null): ?CarbonImmutable
    {
        try {
            if (is_numeric($value) && (float) $value > 0) {
                $date = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, config('app.timezone'))
                    ->addDays((int) floor((float) $value));

                return $this->aplicarHora($date, $time ?? ((float) $value - floor((float) $value)));
            }

            $raw = trim((string) $value);
            if ($raw === '') {
                return null;
            }
            $date = preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $raw) === 1
                ? CarbonImmutable::createFromFormat('!d/m/Y', $raw, config('app.timezone'))
                : CarbonImmutable::parse($raw, config('app.timezone'))->startOfDay();

            return $this->aplicarHora($date, $time);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolverColumnas(array $headers): array
    {
        $available = array_flip($headers);
        $map = [];
        foreach (self::HEADER_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $available)) {
                    $map[$key] = $available[$alias];
                    break;
                }
            }
        }

        return $map;
    }

    private function aplicarHora(CarbonImmutable $date, mixed $time): CarbonImmutable
    {
        if ($time === null || trim((string) $time) === '') {
            return $date;
        }
        if (is_numeric($time)) {
            return $date->addSeconds((int) round(((float) $time) * 86400));
        }

        $parsed = CarbonImmutable::parse((string) $time, config('app.timezone'));

        return $date->setTime($parsed->hour, $parsed->minute, $parsed->second);
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

    private function validarArchivoXlsx(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $mimesPermitidos = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
        ];

        if (! $file->isValid() || ! $file->getSize() || $file->getSize() > 10 * 1024 * 1024) {
            throw new ExcepcionConciliacion(
                'BANK_FILE_INVALID_SIZE',
                'El archivo bancario debe ser válido y no exceder 10 MB.',
                422,
                ['file' => ['El archivo bancario debe ser válido y no exceder 10 MB.']],
            );
        }

        if ($extension !== 'xlsx' || ! in_array($mime, $mimesPermitidos, true)) {
            throw new ExcepcionConciliacion(
                'BANK_FILE_INVALID_FORMAT',
                'Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.',
                422,
                ['file' => ['Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.']],
            );
        }

        try {
            $this->reader->validar($file->getRealPath());
        } catch (Throwable) {
            throw new ExcepcionConciliacion(
                'BANK_FILE_INVALID_FORMAT',
                'Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.',
                422,
                ['file' => ['Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.']],
            );
        }
    }

    private function normalizarError(Throwable $exception): ExcepcionConciliacion
    {
        if ($exception instanceof ExcepcionConciliacion) {
            return $exception;
        }

        return match ($exception->getMessage()) {
            'BANK_FILE_INVALID_FORMAT' => new ExcepcionConciliacion(
                'BANK_FILE_INVALID_FORMAT',
                'Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.',
                422,
                ['file' => ['Archivo inválido. Solo se aceptan archivos Excel XLSX válidos.']],
            ),
            'BANK_FILE_CORRUPT' => new ExcepcionConciliacion(
                'BANK_FILE_CORRUPT',
                'El archivo XLSX está dañado o no puede leerse.',
                422,
                ['file' => ['El archivo XLSX está dañado o no puede leerse. Verifica que sea un libro Excel válido.']],
            ),
            default => new ExcepcionConciliacion('BANK_FILE_PROCESSING_FAILED', 'No fue posible procesar el archivo bancario.', 500),
        };
    }
}
