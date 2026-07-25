<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\RedemptionPeriods;

use App\Modules\Configuration\Application\DTOs\PublishRedemptionPeriodData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\RedemptionPeriodPublished;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentRedemptionPeriodRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publica un periodo de canje (C10).
 */
final class PublishRedemptionPeriodUseCase
{
    public function __construct(
        private readonly EloquentRedemptionPeriodRepository $repository,
    ) {}

    public function execute(PublishRedemptionPeriodData $data): RedemptionPeriodModel
    {
        return DB::transaction(function () use ($data): RedemptionPeriodModel {
            $period = $this->repository->lockById($data->periodPublicId);

            if ($period === null) {
                throw ConfigurationException::notFound('El periodo de canje no existe.');
            }

            if ($period->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            $now = CarbonImmutable::now();

            if ($period->starts_at->lessThan($now)) {
                throw ConfigurationException::retroactivePublicationForbidden();
            }

            // Validar que no haya otro periodo publicado superpuesto.
            // Para canjes, la superposición es starts_at y ends_at, sin versionamiento continuo.
            $hasOverlap = RedemptionPeriodModel::query()
                ->where('status', VersionStatus::PUBLISHED->value)
                ->where('id', '!=', $period->id)
                ->where('starts_at', '<', $period->ends_at)
                ->where('ends_at', '>', $period->starts_at)
                ->exists();

            if ($hasOverlap) {
                throw ConfigurationException::versionOverlap('periodo de canje');
            }

            $correlationId = (string) Str::uuid();

            $period->status = VersionStatus::PUBLISHED->value;
            $period->published_by = $data->actorUserId;
            $period->published_at = $now;
            $period->reason = $data->reason;
            $period->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'REDEMPTION_PERIOD_PUBLISHED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'redemption_period',
                'resource_id' => $period->public_id,
                'before_state' => ['status' => VersionStatus::DRAFT->value],
                'after_state' => ['status' => VersionStatus::PUBLISHED->value],
                'status_before' => VersionStatus::DRAFT->value,
                'status_after' => VersionStatus::PUBLISHED->value,
                'reason' => $data->reason,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            RedemptionPeriodPublished::dispatch(
                $period->public_id,
                $period->starts_at->toIso8601String(),
                $period->ends_at->toIso8601String(),
                (string) $data->actorUserId,
                $now->toIso8601String(),
            );

            return $period;
        });
    }
}
