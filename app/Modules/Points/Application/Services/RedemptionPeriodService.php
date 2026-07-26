<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use App\Modules\Points\Domain\Enums\RedemptionPeriodStatus;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Integra la ventana funcional de M13 con la tabla de periodos ya publicada por M03. */
final readonly class RedemptionPeriodService
{
    public function __construct(
        private PointsAccessService $access,
        private TemporaryAuthorization $reauthentication,
        private PointRecorder $recorder,
        private PointHttpIdempotency $idempotency,
    ) {}

    public function create(
        User $actor,
        string $name,
        ?string $description,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $reason,
    ): RedemptionPeriodModel {
        $this->access->assertGeneralManager($actor);
        if ($startsAt->gte($endsAt)) {
            throw new PointsDomainException(
                'REDEMPTION_PERIOD_INVALID',
                'El inicio del periodo debe ser anterior al fin.',
            );
        }

        return DB::transaction(function () use ($actor, $name, $description, $startsAt, $endsAt, $reason): RedemptionPeriodModel {
            $publicId = (string) Str::uuid();
            $period = new RedemptionPeriodModel;
            $period->forceFill([
                'public_id' => $publicId,
                'public_folio' => $publicId,
                'name' => $name,
                'description' => $description,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'status' => RedemptionPeriodStatus::DRAFT->value,
                'version' => 1,
                'lock_version' => 1,
                'reason' => $reason,
                'created_by' => $actor->id,
            ])->save();
            $this->recorder->audit(
                'REDEMPTION_PERIOD_CREATED',
                'SUCCESS',
                'redemption_periods',
                $publicId,
                $actor,
                null,
                null,
                after: ['status' => RedemptionPeriodStatus::DRAFT->value],
                reason: $reason,
            );
            $this->recorder->outbox('RedemptionPeriodCreated', 'period-created:'.$publicId, [
                'actor' => (string) $actor->public_id,
                'redemption_period_id' => $publicId,
            ]);

            return $period;
        });
    }

    public function publish(
        User $actor,
        string $publicId,
        string $reauthenticationToken,
        string $idempotencyKey,
        ?string $reason,
    ): RedemptionPeriodModel {
        $this->access->assertGeneralManager($actor);

        return DB::transaction(function () use ($actor, $publicId, $reauthenticationToken, $idempotencyKey, $reason): RedemptionPeriodModel {
            $claim = $this->idempotency->claim($actor, 'point-redemption-periods.publish', $idempotencyKey, [
                'period_id' => $publicId,
                'reason' => $reason,
            ]);
            if ($claim['replayed'] && $claim['resource_id'] !== null) {
                return RedemptionPeriodModel::query()->where('public_id', $claim['resource_id'])->firstOrFail();
            }

            $period = RedemptionPeriodModel::query()->where('public_id', $publicId)->lockForUpdate()->first();
            if ($period === null) {
                throw new PointsDomainException('REDEMPTION_PERIOD_INVALID', 'El periodo no existe.', 404);
            }
            if ((string) $period->status !== RedemptionPeriodStatus::DRAFT->value) {
                throw new PointsDomainException(
                    'REDEMPTION_PERIOD_NOT_PUBLISHED',
                    'Solo un periodo en borrador puede publicarse.',
                    409,
                );
            }
            if ($period->starts_at->gte($period->ends_at)) {
                throw new PointsDomainException('REDEMPTION_PERIOD_INVALID', 'La vigencia del periodo no es válida.');
            }

            $this->reauthentication->consumeReauth(
                $actor,
                $reauthenticationToken,
                CriticalAction::REDEMPTION_PERIOD_PUBLISH->value,
                $publicId,
            );
            $period->forceFill([
                'status' => RedemptionPeriodStatus::PUBLISHED->value,
                'published_by' => $actor->id,
                'published_at' => now('UTC'),
                'reason' => $reason,
                'version' => (int) ($period->getAttribute('version') ?? 1),
                'lock_version' => (int) $period->lock_version + 1,
            ])->save();
            $this->recorder->audit(
                'REDEMPTION_PERIOD_PUBLISHED',
                'SUCCESS',
                'redemption_periods',
                $publicId,
                $actor,
                null,
                null,
                before: ['status' => RedemptionPeriodStatus::DRAFT->value],
                after: ['status' => RedemptionPeriodStatus::PUBLISHED->value],
                idempotencyKey: $idempotencyKey,
                reason: $reason,
            );
            $this->recorder->outbox('RedemptionPeriodPublished', 'period-published:'.$publicId, [
                'actor' => (string) $actor->public_id,
                'redemption_period_id' => $publicId,
            ]);
            $this->idempotency->complete($actor, 'point-redemption-periods.publish', $idempotencyKey, $publicId);

            return $period;
        });
    }

    public function current(CarbonImmutable $at): ?RedemptionPeriodModel
    {
        return RedemptionPeriodModel::query()
            ->where('status', RedemptionPeriodStatus::PUBLISHED->value)
            ->where('starts_at', '<=', $at->utc())
            ->where('ends_at', '>=', $at->utc())
            ->orderByDesc('starts_at')
            ->first();
    }

    public function closeExpired(): int
    {
        return DB::transaction(function (): int {
            $periods = RedemptionPeriodModel::query()
                ->where('status', RedemptionPeriodStatus::PUBLISHED->value)
                ->where('ends_at', '<', now('UTC'))
                ->lockForUpdate()
                ->get();
            foreach ($periods as $period) {
                $period->forceFill([
                    'status' => RedemptionPeriodStatus::CLOSED->value,
                    'closed_at' => now('UTC'),
                    'lock_version' => (int) $period->lock_version + 1,
                ])->save();
                $this->recorder->audit(
                    'REDEMPTION_PERIOD_CLOSED',
                    'SUCCESS',
                    'redemption_periods',
                    $period->public_id,
                    null,
                    null,
                    null,
                    before: ['status' => RedemptionPeriodStatus::PUBLISHED->value],
                    after: ['status' => RedemptionPeriodStatus::CLOSED->value],
                );
                $this->recorder->outbox('RedemptionPeriodClosed', 'period-closed:'.$period->public_id, [
                    'redemption_period_id' => $period->public_id,
                    'actor' => 'SYSTEM',
                ]);
            }

            return $periods->count();
        });
    }
}
