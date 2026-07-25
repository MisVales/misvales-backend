<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\RedemptionPeriods;

use App\Modules\Configuration\Application\DTOs\DeactivateRedemptionPeriodData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\RedemptionPeriodDeactivated;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentRedemptionPeriodRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Desactiva un periodo de canje (C10).
 */
final class DeactivateRedemptionPeriodUseCase
{
    public function __construct(
        private readonly EloquentRedemptionPeriodRepository $repository,
    ) {}

    public function execute(DeactivateRedemptionPeriodData $data): RedemptionPeriodModel
    {
        return DB::transaction(function () use ($data): RedemptionPeriodModel {
            $period = $this->repository->lockById($data->periodPublicId);

            if ($period === null) {
                throw ConfigurationException::notFound('El periodo de canje no existe.');
            }

            if ($period->versionStatus() === VersionStatus::INACTIVE) {
                return $period; // Idempotente
            }

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();
            $previousStatus = $period->status;

            $period->status = VersionStatus::INACTIVE->value;
            // Para periodos de canje publicados, limitamos su vigencia al momento de la desactivación
            // solo si todavía estaban vigentes.
            if ($previousStatus === VersionStatus::PUBLISHED->value && $period->ends_at->greaterThan($now)) {
                // Si aún no había empezado, el fin es igual al inicio para anularlo por completo.
                // Si ya había empezado, el fin es "ahora".
                $period->ends_at = $period->starts_at->greaterThan($now) ? $period->starts_at : $now;
            }
            $period->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'REDEMPTION_PERIOD_DEACTIVATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'redemption_period',
                'resource_id' => $period->public_id,
                'status_before' => $previousStatus,
                'status_after' => VersionStatus::INACTIVE->value,
                'reason' => $data->reason,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            RedemptionPeriodDeactivated::dispatch(
                $period->public_id,
                (string) $data->actorUserId,
                $data->reason,
                $now->toIso8601String(),
            );

            return $period;
        });
    }
}
