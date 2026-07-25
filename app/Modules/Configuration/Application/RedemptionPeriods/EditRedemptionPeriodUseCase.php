<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\RedemptionPeriods;

use App\Modules\Configuration\Application\DTOs\EditRedemptionPeriodData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentRedemptionPeriodRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Edita un borrador de periodo de canje.
 */
final class EditRedemptionPeriodUseCase
{
    public function __construct(
        private readonly EloquentRedemptionPeriodRepository $repository,
    ) {}

    public function execute(EditRedemptionPeriodData $data): RedemptionPeriodModel
    {
        if ($data->startsAt->greaterThanOrEqualTo($data->endsAt)) {
            throw ConfigurationException::redemptionPeriodInvalid(
                'La fecha de inicio debe ser estrictamente anterior a la fecha de fin.'
            );
        }

        return DB::transaction(function () use ($data): RedemptionPeriodModel {
            $period = $this->repository->lockById($data->periodPublicId);

            if ($period === null) {
                throw ConfigurationException::notFound('El periodo de canje no existe.');
            }

            if ($period->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            if ($period->lock_version !== $data->lockVersion) {
                throw ConfigurationException::versionConflict();
            }

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $beforeState = [
                'starts_at' => $period->starts_at->toIso8601String(),
                'ends_at' => $period->ends_at->toIso8601String(),
            ];

            $period->starts_at = $data->startsAt;
            $period->ends_at = $data->endsAt;
            $period->lock_version = $period->lock_version + 1;
            $period->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'REDEMPTION_PERIOD_DRAFT_EDITED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'redemption_period',
                'resource_id' => $period->public_id,
                'before_state' => $beforeState,
                'after_state' => [
                    'starts_at' => $data->startsAt->toIso8601String(),
                    'ends_at' => $data->endsAt->toIso8601String(),
                ],
                'status_before' => VersionStatus::DRAFT->value,
                'status_after' => VersionStatus::DRAFT->value,
                'correlation_id' => $correlationId,
                'request_id' => (string) Str::uuid(),
                'occurred_at' => $now,
            ]);

            return $period;
        });
    }
}
