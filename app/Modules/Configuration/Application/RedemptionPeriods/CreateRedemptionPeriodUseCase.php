<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\RedemptionPeriods;

use App\Modules\Configuration\Application\DTOs\CreateRedemptionPeriodData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea un borrador de periodo de canje.
 */
final class CreateRedemptionPeriodUseCase
{
    public function execute(CreateRedemptionPeriodData $data): RedemptionPeriodModel
    {
        if ($data->startsAt->greaterThanOrEqualTo($data->endsAt)) {
            throw ConfigurationException::redemptionPeriodInvalid(
                'La fecha de inicio debe ser estrictamente anterior a la fecha de fin.'
            );
        }

        return DB::transaction(function () use ($data): RedemptionPeriodModel {
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $period = new RedemptionPeriodModel;
            $period->public_id = (string) Str::uuid();
            $period->starts_at = $data->startsAt;
            $period->ends_at = $data->endsAt;
            $period->status = VersionStatus::DRAFT->value;
            $period->created_by = $data->actorUserId;
            $period->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'REDEMPTION_PERIOD_DRAFT_CREATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'redemption_period',
                'resource_id' => $period->public_id,
                'after_state' => [
                    'starts_at' => $data->startsAt->toIso8601String(),
                    'ends_at' => $data->endsAt->toIso8601String(),
                ],
                'status_after' => VersionStatus::DRAFT->value,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            return $period;
        });
    }
}
