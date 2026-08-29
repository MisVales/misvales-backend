<?php

namespace App\Services\Recargo;

use App\Models\AuditLog;
use App\Models\ConfigurationVersion;
use App\Models\RelacionDistribuidora;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServicioEvaluacionRecargo
{
    public function __construct(
        private readonly ServicioConfiguracionRecargo $configuracion,
        private readonly ServicioCalculoRecargoRelacion $calculoRecargo,
    ) {}

    public function evaluar(CarbonImmutable $now): array
    {
        return $this->evaluarInterno($now);
    }

    public function aplicarAntesDeRollover(RelacionDistribuidora $relation, CarbonImmutable $cutoff): bool
    {
        if ($relation->payment_deadline_at === null || ! $relation->payment_deadline_at->lessThan($cutoff)) {
            return false;
        }

        $config = $this->configuracion->resolver($cutoff->utc());

        return DB::transaction(function () use ($relation, $cutoff, $config): bool {
            $locked = RelacionDistribuidora::query()->whereKey($relation->id)->lockForUpdate()->firstOrFail();
            if (bccomp((string) $locked->balance, '0', 4) <= 0) {
                return false;
            }

            $outcome = $this->aplicarCanonico($locked, $cutoff, $config);
            if ($outcome === 'applied') {
                AuditLog::create([
                    'entity_type' => 'distributor_relation',
                    'entity_id' => $locked->id,
                    'event_name' => 'LateFeeAppliedBeforeRollover',
                    'result' => 'SUCCESS',
                    'new_value' => ['cutoff_at' => $cutoff->toIso8601String(), 'amount' => $locked->surcharge_total],
                ]);
            }

            return $outcome === 'applied';
        });
    }

    /** @param list<string> $relationIds */
    public function evaluarRelacionesSimuladas(CarbonImmutable $now, array $relationIds, CarbonImmutable $importsSince, ?string $processRunId = null): array
    {
        return $this->evaluarInterno($now, $relationIds, $importsSince, $processRunId);
    }

    /** @param list<string>|null $relationIds */
    private function evaluarInterno(CarbonImmutable $now, ?array $relationIds = null, ?CarbonImmutable $importsSince = null, ?string $processRunId = null): array
    {
        $config = $this->configuracion->resolver($now->utc());
        $tz = $config['timezone'];
        $now = $now->setTimezone($tz);
        $result = ['applied' => 0, 'deferred' => 0];
        $relations = RelacionDistribuidora::query()->where('balance', '>', 0)->where('payment_deadline_at', '<=', $now)
            ->when($relationIds !== null, fn ($query) => $query->whereIn('id', $relationIds))->get();
        foreach ($relations as $relation) {
            [$hour, $minute] = array_map('intval', explode(':', $config['bank_deadline_time']));
            $imports = DB::table('bank_file_imports')->where('branch_id', $relation->branch_id)->where('status', 'PROCESSED');
            $hasFile = $processRunId !== null
                ? (clone $imports)->where('process_run_id', $processRunId)->exists() || (clone $imports)->whereNull('process_run_id')->where('created_at', '>=', $importsSince->setTimezone($tz))->exists()
                : ($importsSince === null
                    ? $imports->whereBetween('created_at', [$relation->payment_deadline_at, $relation->payment_deadline_at->addDay()->setTimezone($tz)->setTime($hour, $minute)->utc()])->exists()
                    : $imports->where('created_at', '>=', $importsSince->setTimezone($tz))->where('created_at', '<=', CarbonImmutable::now($tz))->exists());
            if (! $hasFile) {
                $result['deferred']++;
                AuditLog::firstOrCreate(['entity_type' => 'distributor_relation', 'entity_id' => $relation->id, 'event_name' => 'LateFeeDeferredMissingBankFile'], ['result' => 'DEFERRED', 'new_value' => ['evaluated_at' => $now->toIso8601String()]]);

                continue;
            }
            DB::transaction(function () use ($relation, $now, $config, &$result): void {
                $locked = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
                $outcome = $this->aplicarCanonico($locked, $now, $config);
                if ($outcome === 'applied') {
                    $result['applied']++;
                }
                if ($outcome === 'deferred') {
                    $result['deferred']++;
                }
            });
        }

        return $result;
    }

    /** @param array<string,mixed> $config */
    private function aplicarCanonico(RelacionDistribuidora $locked, CarbonImmutable $appliedAt, array $config): string
    {
        $existingFee = DB::table('relation_late_fees')
            ->where('relation_id', $locked->id)
            ->where('type', 'LATE_FEE')
            ->lockForUpdate()
            ->first();
        if ($existingFee !== null && $existingFee->voided_at === null) {
            return 'existing';
        }

        $lateFee = $this->resolveLateFee($locked);
        if ($lateFee === null) {
            $locked->forceFill(['review_status' => 'MANUAL_REVIEW'])->save();
            AuditLog::firstOrCreate(['entity_type' => 'distributor_relation', 'entity_id' => $locked->id, 'event_name' => 'LateFeeManualReviewRequired'], ['result' => 'DEFERRED', 'new_value' => ['cutoff_at' => $locked->cutoff_at?->toIso8601String()]]);

            return 'deferred';
        }
        if ($lateFee['recovered']) {
            $header = $locked->header_snapshot;
            $header['late_fee'] = $lateFee['snapshot'];
            $locked->header_snapshot = $header;
            AuditLog::create(['entity_type' => 'distributor_relation', 'entity_id' => $locked->id, 'event_name' => 'LateFeeLegacyConfigurationRecovered', 'result' => 'SUCCESS', 'new_value' => $lateFee['snapshot']]);
        }
        $breakdown = $this->calculoRecargo->calcular($locked, $lateFee['snapshot']['amount']);
        $amount = $breakdown['amount'];
        if (bccomp($amount, '0', 4) <= 0) {
            return 'existing';
        }
        $snapshot = array_merge($config, [
            'late_fee' => $lateFee['snapshot'],
            'late_fee_unit_amount' => $breakdown['unit_amount'],
            'late_fee_units' => $breakdown['units'],
            'relation_item_ids' => $breakdown['item_ids'],
            'total_late_fee' => $amount,
        ]);
        if ($existingFee === null) {
            DB::table('relation_late_fees')->insert([
                'id' => (string) Str::uuid(), 'relation_id' => $locked->id, 'type' => 'LATE_FEE',
                'amount' => $amount, 'applied_at' => $appliedAt, 'configuration_snapshot' => json_encode($snapshot),
                'voided_at' => null, 'void_reason' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('relation_late_fees')->where('id', $existingFee->id)->update([
                'amount' => $amount, 'applied_at' => $appliedAt, 'configuration_snapshot' => json_encode($snapshot),
                'voided_at' => null, 'void_reason' => null, 'updated_at' => now(),
            ]);
        }
        $locked->surcharge_total = bcadd((string) $locked->surcharge_total, $amount, 4);
        $locked->balance = bcadd((string) $locked->balance, $amount, 4);
        $locked->financial_status = 'OVERDUE';
        $locked->save();

        return 'applied';
    }

    /** @return array{snapshot:array<string,string>,recovered:bool}|null */
    private function resolveLateFee(RelacionDistribuidora $relation): ?array
    {
        $header = $relation->header_snapshot;
        $snapshot = $header['late_fee'] ?? null;
        if (is_array($snapshot) && is_numeric($snapshot['amount'] ?? null)) {
            $snapshot['amount'] = bcadd((string) $snapshot['amount'], '0', 4);

            return ['snapshot' => $snapshot, 'recovered' => false];
        }
        $versionId = $snapshot['configuration_version_id'] ?? $header['configuration_versions']['LATE_FEE_AMOUNT'] ?? null;
        $version = $versionId ? ConfigurationVersion::query()->with('definition')->find($versionId) : null;
        $source = 'legacy_configuration_version';
        if (! $version || $version->definition?->key !== 'LATE_FEE_AMOUNT') {
            $versions = ConfigurationVersion::query()->with('definition')->whereHas('definition', fn ($query) => $query->where('key', 'LATE_FEE_AMOUNT'))->where('status', 'PUBLISHED')
                ->where('effective_from', '<=', $relation->cutoff_at)->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $relation->cutoff_at))->get();
            if ($versions->count() !== 1) {
                return null;
            }
            $version = $versions->first();
            $source = 'legacy_cutoff_configuration';
        }
        if (! is_numeric($version->value) || bccomp((string) $version->value, '0', 4) < 0) {
            return null;
        }

        return ['snapshot' => ['amount' => bcadd((string) $version->value, '0', 4), 'configuration_version_id' => (string) $version->id, 'resolved_at' => now()->utc()->toIso8601String(), 'source' => $source], 'recovered' => true];
    }
}
