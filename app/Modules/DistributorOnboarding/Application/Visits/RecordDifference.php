<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Visits;

use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\ExpedientFieldAccessor;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\DifferenceCatalogPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\MediaPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationDifference;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationVisit;
use Illuminate\Support\Facades\DB;

/** Registra una diferencia protegida, validando catálogo y evidencia por sus módulos propietarios. */
final readonly class RecordDifference
{
    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private ExpedientFieldAccessor $fields,
        private DifferenceCatalogPort $catalog,
        private MediaPort $media,
        private IdempotencyService $idempotency,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(RecordDifferenceCommand $command): VerificationDifference
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'visit_id' => $command->visitPublicId,
            'section' => $command->section->value,
            'field_path' => $command->fieldPath,
            'declared_value' => $command->declaredValue,
            'observed_value' => $command->observedValue,
            'classification_code' => $command->classificationCode,
            'evidence_media_id' => $command->evidenceMediaId,
        ];
        $replay = $this->idempotency->replay('RECORD_DIFFERENCE', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return VerificationDifference::query()->where('public_id', $replay['difference_id'])->firstOrFail();
        }

        return DB::transaction(function () use ($command): VerificationDifference {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertAssignedVerifier($command->actor, $application);
            if ($application->status !== ApplicationStatus::PHYSICAL_VERIFICATION) {
                throw OnboardingDomainException::invalidState();
            }
            $visit = VerificationVisit::query()
                ->where('public_id', $command->visitPublicId)
                ->where('application_id', $application->id)
                ->lockForUpdate()
                ->first();
            if ($visit === null) {
                throw OnboardingDomainException::scopeDenied();
            }
            if ($visit->completed_at !== null) {
                throw OnboardingDomainException::visitAlreadyCompleted();
            }

            $this->catalog->assertApproved($command->classificationCode);
            $current = $this->fields->read($application, $command->section, $command->fieldPath);
            $comparable = $current instanceof \DateTimeInterface
                ? $current->format('Y-m-d')
                : ($current instanceof \Stringable ? (string) $current : (string) ($current ?? ''));
            if (! hash_equals($comparable, $command->declaredValue)) {
                throw OnboardingDomainException::versionConflict();
            }
            if ($command->evidenceMediaId !== null) {
                $this->media->assertReady($command->evidenceMediaId, $application->public_id, $command->actor->userId);
            }

            $reservation = $this->idempotency->reserve('RECORD_DIFFERENCE', $command->metadata->idempotencyKey, [
                'application_id' => $application->public_id,
                'visit_id' => $visit->public_id,
                'section' => $command->section->value,
                'field_path' => $command->fieldPath,
                'declared_value' => $command->declaredValue,
                'observed_value' => $command->observedValue,
                'classification_code' => $command->classificationCode,
                'evidence_media_id' => $command->evidenceMediaId,
            ], $application->id);
            if ($reservation->isReplay()) {
                return VerificationDifference::query()->where('public_id', $reservation->replayedPayload['difference_id'])->firstOrFail();
            }

            $difference = new VerificationDifference;
            $difference->forceFill([
                'application_id' => $application->id,
                'visit_id' => $visit->id,
                'section' => $command->section,
                'field_path' => $command->fieldPath,
                'declared_value' => $command->declaredValue,
                'observed_value' => $command->observedValue,
                'description' => $command->description,
                'evidence_media_id' => $command->evidenceMediaId,
                'classification_code' => $command->classificationCode,
                'verifier_user_id' => $command->actor->userId,
                'recorded_at' => now(),
            ])->save();

            $this->recorder->mutation(
                $application,
                $command->actor,
                'M04_DIFFERENCE_RECORDED',
                'verification_difference',
                $difference->public_id,
                null,
                $command->metadata,
            );
            $this->idempotency->complete($reservation->record, 'verification_difference', $difference->public_id, [
                'difference_id' => $difference->public_id,
            ]);

            return $difference;
        }, 3);
    }
}
