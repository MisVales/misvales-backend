<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Corrections;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ExpedientFieldAccessor;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationCorrection;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationDifference;
use Illuminate\Support\Facades\DB;

/** Conserva el original, aplica la versión vigente y nunca modifica correcciones anteriores. */
final readonly class RecordCorrection
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private ExpedientFieldAccessor $fields,
        private IdempotencyService $idempotency,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(RecordCorrectionCommand $command): ApplicationCorrection
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
            'section' => $command->section->value,
            'field_path' => $command->fieldPath,
            'expected_original_value' => $command->expectedOriginalValue,
            'corrected_value' => $command->correctedValue,
            'reason' => $command->reason,
            'difference_id' => $command->differencePublicId,
        ];
        $replay = $this->idempotency->replay('RECORD_CORRECTION', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return ApplicationCorrection::query()->where('public_id', $replay['correction_id'])->firstOrFail();
        }

        return DB::transaction(function () use ($command): ApplicationCorrection {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertResponsibleCoordinator(
                $command->actor,
                $application,
                PermissionCode::ONBOARDING_APPLICATIONS_CORRECT,
            );
            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) {
                throw OnboardingDomainException::invalidState();
            }

            $difference = null;
            if ($command->differencePublicId !== null) {
                $difference = VerificationDifference::query()
                    ->where('public_id', $command->differencePublicId)
                    ->where('application_id', $application->id)
                    ->lockForUpdate()
                    ->first();
                if ($difference === null) {
                    throw OnboardingDomainException::scopeDenied();
                }
            }

            $current = $this->fields->read($application, $command->section, $command->fieldPath);
            $comparable = $current instanceof \DateTimeInterface
                ? $current->format('Y-m-d')
                : ($current instanceof \Stringable ? (string) $current : (string) ($current ?? ''));
            if (! hash_equals($comparable, $command->expectedOriginalValue)) {
                throw OnboardingDomainException::versionConflict();
            }

            $reservation = $this->idempotency->reserve('RECORD_CORRECTION', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'lock_version' => $command->lockVersion,
                'section' => $command->section->value,
                'field_path' => $command->fieldPath,
                'expected_original_value' => $command->expectedOriginalValue,
                'corrected_value' => $command->correctedValue,
                'reason' => $command->reason,
                'difference_id' => $command->differencePublicId,
            ], $application->id);
            if ($reservation->isReplay()) {
                return ApplicationCorrection::query()->where('public_id', $reservation->replayedPayload['correction_id'])->firstOrFail();
            }

            $correction = new ApplicationCorrection;
            $correction->forceFill([
                'application_id' => $application->id,
                'difference_id' => $difference?->id,
                'section' => $command->section,
                'field_path' => $command->fieldPath,
                'original_value' => $comparable,
                'corrected_value' => $command->correctedValue,
                'reason' => $command->reason,
                'corrected_by' => $command->actor->userId,
                'corrected_at' => now(),
                'request_id' => $command->metadata->requestId,
            ])->save();

            $this->fields->write(
                $application,
                $command->actor->userId,
                $command->section,
                $command->fieldPath,
                $command->correctedValue,
            );
            if ($difference !== null && $difference->resolved_at === null) {
                $difference->forceFill(['resolved_at' => now()])->save();
            }
            $application->forceFill(['lock_version' => $application->lock_version + 1])->save();

            $this->recorder->mutation(
                $application,
                $command->actor,
                'M04_CORRECTION_RECORDED',
                'application_correction',
                $correction->public_id,
                $command->reason,
                $command->metadata,
            );
            $this->idempotency->complete($reservation->record, 'application_correction', $correction->public_id, [
                'correction_id' => $correction->public_id,
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $correction;
        }, 3);
    }
}
