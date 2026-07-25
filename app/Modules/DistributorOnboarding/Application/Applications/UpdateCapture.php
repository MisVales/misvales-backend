<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Support\ApplicationLocker;
use App\Modules\DistributorOnboarding\Application\Support\IdempotencyService;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\Support\WorkflowRecorder;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Domain\Contracts\MediaPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationCaptureRevision;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationPersonalData;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

/** Actualiza contacto y sección personal en CAPTURE conservando cada revisión. */
final readonly class UpdateCapture
{
    /** @var list<string> */
    private const PERSONAL_FIELDS = [
        'first_name', 'paternal_surname', 'maternal_surname', 'curp', 'rfc',
        'birth_date', 'birth_place', 'birth_state', 'birth_city', 'declared_address',
        'official_identification_media_id',
    ];

    public function __construct(
        private ApplicationLocker $locker,
        private OnboardingAuthorizer $authorizer,
        private MediaPort $media,
        private IdempotencyService $idempotency,
        private WorkflowRecorder $recorder,
    ) {}

    public function execute(UpdateCaptureCommand $command): DistributorApplication
    {
        $payload = [
            'application_id' => $command->applicationPublicId,
            'lock_version' => $command->lockVersion,
            'contact_email' => $command->contactEmail,
            'account_name' => $command->accountName,
            'personal_data' => $command->personalData,
        ];
        $replay = $this->idempotency->replay('UPDATE_CAPTURE', $command->metadata->idempotencyKey, $payload);
        if ($replay !== null) {
            return DistributorApplication::query()->where('public_id', $command->applicationPublicId)->firstOrFail();
        }

        return DB::transaction(function () use ($command): DistributorApplication {
            $application = $this->locker->lock($command->applicationPublicId, $command->lockVersion);
            $this->authorizer->assertPermission($command->actor, PermissionCode::ONBOARDING_APPLICATIONS_UPDATE_CAPTURE);
            $this->authorizer->assertCanView($command->actor, $application);

            if ($application->status !== ApplicationStatus::CAPTURE) {
                throw OnboardingDomainException::invalidState();
            }

            $reservation = $this->idempotency->reserve('UPDATE_CAPTURE', $command->metadata->idempotencyKey, [
                'application_id' => $command->applicationPublicId,
                'lock_version' => $command->lockVersion,
                'contact_email' => $command->contactEmail,
                'account_name' => $command->accountName,
                'personal_data' => $command->personalData,
            ], $application->id);

            if ($reservation->isReplay()) {
                return $application;
            }

            if (array_diff(array_keys($command->personalData), self::PERSONAL_FIELDS) !== []) {
                throw OnboardingDomainException::incomplete();
            }
            $identificationMediaId = $command->personalData['official_identification_media_id'] ?? null;
            if (is_string($identificationMediaId)) {
                $this->media->assertReady($identificationMediaId, $application->public_id, $command->actor->userId);
            }

            $previousContact = [
                'contact_email' => $application->contact_email,
                'account_name' => $application->account_name,
            ];

            if ($command->contactEmail !== null) {
                $email = new NormalizedEmail($command->contactEmail);
                $application->contact_email = $email->value;
                $application->normalized_email_hash = $email->protectedHash((string) config('app.key'));
            }
            if ($command->accountName !== null) {
                $application->account_name = trim($command->accountName);
            }

            $personal = ApplicationPersonalData::query()
                ->where('application_id', $application->id)
                ->lockForUpdate()
                ->first();
            $previousPersonal = $personal?->only(self::PERSONAL_FIELDS);

            if ($command->personalData !== []) {
                $personal ??= new ApplicationPersonalData;
                $personal->forceFill([
                    'application_id' => $application->id,
                    ...$this->protectedPersonalData($command->personalData),
                    'lock_version' => ($personal->exists ? $personal->lock_version : 0) + 1,
                ])->save();
            }

            $application->lock_version++;
            $application->save();

            if ($command->contactEmail !== null || $command->accountName !== null) {
                $this->revision(
                    $application,
                    ExpedientSection::CONTACT,
                    $previousContact,
                    ['contact_email' => $application->contact_email, 'account_name' => $application->account_name],
                    $command,
                );
            }
            if ($command->personalData !== []) {
                $this->revision($application, ExpedientSection::PERSONAL, $previousPersonal, $personal?->only(self::PERSONAL_FIELDS), $command);
            }

            $this->recorder->mutation(
                $application,
                $command->actor,
                'M04_CAPTURE_UPDATED',
                'distributor_application',
                $application->public_id,
                null,
                $command->metadata,
            );
            $this->idempotency->complete($reservation->record, 'distributor_application', $application->public_id, [
                'application_id' => $application->public_id,
                'lock_version' => $application->lock_version,
            ]);

            return $application->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function protectedPersonalData(array $values): array
    {
        if (array_key_exists('curp', $values)) {
            $values['curp_hash'] = $values['curp'] === null
                ? null
                : hash_hmac('sha256', mb_strtoupper(trim((string) $values['curp'])), (string) config('app.key'));
        }
        if (array_key_exists('rfc', $values)) {
            $values['rfc_hash'] = $values['rfc'] === null
                ? null
                : hash_hmac('sha256', mb_strtoupper(trim((string) $values['rfc'])), (string) config('app.key'));
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $next
     */
    private function revision(
        DistributorApplication $application,
        ExpedientSection $section,
        ?array $previous,
        ?array $next,
        UpdateCaptureCommand $command,
    ): void {
        $revision = new ApplicationCaptureRevision;
        $revision->forceFill([
            'application_id' => $application->id,
            'section' => $section,
            'action' => $previous === null ? 'CREATE' : 'UPDATE',
            'previous_value' => $previous === null ? null : json_encode($previous, JSON_THROW_ON_ERROR),
            'new_value' => $next === null ? null : json_encode($next, JSON_THROW_ON_ERROR),
            'actor_user_id' => $command->actor->userId,
            'request_id' => $command->metadata->requestId,
            'recorded_at' => now(),
        ])->save();
    }
}
