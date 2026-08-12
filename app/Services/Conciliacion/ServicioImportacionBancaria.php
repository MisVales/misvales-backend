<?php

namespace App\Services\Conciliacion;

use App\Models\ImportacionArchivoBancario;
use App\Models\MovimientoBancario;
use App\Models\RelacionDistribuidora;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ServicioImportacionBancaria
{
    private const HEADERS = ['referencia de pago', 'monto', 'fecha', 'folio bancario', 'concepto'];

    public function __construct(private LectorXlsxBancario $reader) {}

    public function importar(UploadedFile $file, User $actor, string $branchId): ImportacionArchivoBancario
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if (ImportacionArchivoBancario::where('file_hash', $hash)->exists()) {
            throw new RuntimeException('BANK_FILE_ALREADY_IMPORTED');
        }$path = $file->store('bank-imports', 'local');
        $import = ImportacionArchivoBancario::create(['private_path' => $path, 'file_hash' => $hash, 'uploaded_by' => $actor->id, 'branch_id' => $branchId, 'status' => 'REJECTED']);
        try {
            $rows = $this->reader->leer(Storage::disk('local')->path($path));
            $headers = array_map(fn ($v) => mb_strtolower(trim($v)), array_values($rows[0] ?? []));
            if (array_diff(self::HEADERS, $headers)) {
                throw new RuntimeException('BANK_FILE_REQUIRED_COLUMNS_MISSING');
            }$map = array_flip($headers);
            $summary = ['partial_payments' => 0, 'settlements' => 0, 'surpluses' => 0, 'unreconciled' => 0, 'duplicates' => 0, 'errors' => 0];
            DB::transaction(function () use ($rows, $map, $import, &$summary) {
                foreach (array_slice($rows, 1) as $offset => $raw) {
                    $values = array_values($raw);
                    $folio = trim($values[$map['folio bancario']] ?? '');
                    if ($folio === '' || MovimientoBancario::where('bank_folio', $folio)->exists()) {
                        $summary['duplicates']++;

                        continue;
                    }$amount = bcadd((string) ($values[$map['monto']] ?? '0'), '0', 4);
                    $reference = trim($values[$map['referencia de pago']] ?? '');
                    $relation = RelacionDistribuidora::where('payment_reference', $reference)->lockForUpdate()->first();
                    $classification = 'UNRECONCILED';
                    $applied = '0.0000';
                    $surplus = '0.0000';
                    if ($relation) {
                        $cmp = bccomp($amount, $relation->balance, 4);
                        $applied = $cmp > 0 ? $relation->balance : $amount;
                        $surplus = $cmp > 0 ? bcsub($amount, $relation->balance, 4) : '0.0000';
                        $classification = $cmp < 0 ? 'PARTIAL_PAYMENT' : ($cmp === 0 ? 'SETTLEMENT' : 'SURPLUS');
                        $relation->reconciled_total = bcadd($relation->reconciled_total, $applied, 4);
                        $relation->balance = bcsub($relation->balance, $applied, 4);
                        $relation->financial_status = $relation->balance === '0.0000' ? 'SETTLED' : 'PARTIALLY_PAID';
                        $relation->save();
                    }$key = ['PARTIAL_PAYMENT' => 'partial_payments', 'SETTLEMENT' => 'settlements', 'SURPLUS' => 'surpluses', 'UNRECONCILED' => 'unreconciled'][$classification];
                    $summary[$key]++;
                    MovimientoBancario::create(['import_id' => $import->id, 'row_number' => $offset + 2, 'original_row' => $raw, 'payment_reference' => $reference, 'amount' => $amount, 'paid_at' => CarbonImmutable::parse($values[$map['fecha']] ?? null), 'bank_folio' => $folio, 'concept' => $values[$map['concepto']] ?? '', 'classification' => $classification, 'relation_id' => $relation?->id, 'applied_amount' => $applied, 'surplus_amount' => $surplus]);
                }
            });
            $import->update(['status' => 'PROCESSED', 'row_count' => max(0, count($rows) - 1), 'summary' => $summary]);

            return $import->fresh();
        } catch (\Throwable $e) {
            $import->update(['status' => 'REJECTED', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
