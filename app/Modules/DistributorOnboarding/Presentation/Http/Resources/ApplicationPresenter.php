<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Resources;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationAssetLiability;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationCommercialCredit;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationCorrection;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationEmployment;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationFamilyMember;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationFamilyReference;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationLaborReference;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationResidence;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationVehicle;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use App\Modules\DistributorOnboarding\Persistence\Models\VerificationDifference;

/** Transforma modelos internos en contratos públicos sin exponer claves o secretos. */
final class ApplicationPresenter
{
    /** @return array<string, mixed> */
    public function summary(DistributorApplication $application, ActorContext $actor): array
    {
        $assignment = $application->activeVerifierAssignment;

        return [
            'id' => $application->public_id,
            'folio' => $application->folio,
            'status' => $application->status->value,
            'result' => $application->result?->value,
            'branch' => $application->branch === null ? null : [
                'id' => $application->branch->public_id,
                'name' => $application->branch->name,
            ],
            'coordinator' => $application->coordinator === null ? null : [
                'id' => $application->coordinator->public_id,
                'name' => $application->coordinator->name,
            ],
            'verifier' => $assignment?->verifier === null ? null : [
                'id' => $assignment->verifier->public_id,
                'name' => $assignment->verifier->name,
            ],
            'allowed_actions' => $this->allowedActions($application, $actor),
            'lock_version' => $application->lock_version,
            'created_at' => $application->created_at?->toISOString(),
            'updated_at' => $application->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(DistributorApplication $application, ActorContext $actor): array
    {
        $data = $this->summary($application, $actor);
        $data['contact_email'] = $actor->role === RoleCode::ADMINISTRATOR
            ? $this->maskEmail($application->contact_email)
            : $application->contact_email;
        $data['account_name'] = $application->account_name;

        if ($application->personalData !== null) {
            $personal = $application->personalData;
            $data['personal_data'] = [
                'first_name' => $personal->first_name,
                'paternal_surname' => $personal->paternal_surname,
                'maternal_surname' => $personal->maternal_surname,
                'curp' => $actor->role === RoleCode::ADMINISTRATOR ? $this->mask((string) $personal->curp) : $personal->curp,
                'rfc' => $actor->role === RoleCode::ADMINISTRATOR ? $this->mask((string) $personal->rfc) : $personal->rfc,
                'birth_date' => $personal->birth_date?->format('Y-m-d'),
                'birth_place' => $personal->birth_place,
                'birth_state' => $personal->birth_state,
                'birth_city' => $personal->birth_city,
                'declared_address' => $actor->role === RoleCode::ADMINISTRATOR ? null : $personal->declared_address,
                'official_identification_media_id' => $personal->official_identification_media_id,
            ];
        }

        if ($actor->role === RoleCode::ADMINISTRATOR) {
            $data['expedient_counts'] = [
                'family_members' => $application->familyMembers->count(),
                'family_references' => $application->familyReferences->count(),
                'residences' => $application->residences->count(),
                'vehicles' => $application->vehicles->count(),
                'assets_liabilities' => $application->assetsLiabilities->count(),
                'employments' => $application->employments->count(),
                'labor_references' => $application->laborReferences->count(),
                'commercial_credits' => $application->commercialCredits->count(),
            ];
        } else {
            $data['family_members'] = $application->familyMembers->map(fn (ApplicationFamilyMember $member): array => [
                'id' => $member->public_id,
                'relationship_code' => $member->relationship_code,
                'name' => $member->name,
                'age' => $member->age,
                'school' => $member->school,
            ])->all();
            $data['family_references'] = $application->familyReferences->map(fn (ApplicationFamilyReference $reference): array => [
                'id' => $reference->public_id,
                'relationship_code' => $reference->relationship_code,
                'name' => $reference->name,
                'phone' => $reference->phone,
            ])->all();
            $data['residences'] = $application->residences->map(fn (ApplicationResidence $residence): array => [
                'id' => $residence->public_id,
                'structured_address' => $residence->structured_address,
                'housing_type_code' => $residence->housing_type_code,
                'tenure_code' => $residence->tenure_code,
                'financing_code' => $residence->financing_code,
                'dimensions' => $residence->dimensions,
            ])->all();
            $data['vehicles'] = $application->vehicles->map(fn (ApplicationVehicle $vehicle): array => [
                'id' => $vehicle->public_id,
                'declared_details' => $vehicle->declared_details,
            ])->all();
            $data['assets_liabilities'] = $application->assetsLiabilities->map(fn (ApplicationAssetLiability $entry): array => [
                'id' => $entry->public_id,
                'entry_type' => $entry->entry_type->value,
                'description' => $entry->description,
                'amount' => $entry->amount,
            ])->all();
            $data['employments'] = $application->employments->map(fn (ApplicationEmployment $employment): array => [
                'id' => $employment->public_id,
                'workplace' => $employment->workplace,
                'declared_details' => $employment->declared_details,
            ])->all();
            $data['labor_references'] = $application->laborReferences->map(fn (ApplicationLaborReference $reference): array => [
                'id' => $reference->public_id,
                'name' => $reference->name,
                'contact' => $reference->contact,
                'declared_details' => $reference->declared_details,
            ])->all();
            $data['commercial_credits'] = $application->commercialCredits->map(fn (ApplicationCommercialCredit $credit): array => [
                'id' => $credit->public_id,
                'company_name' => $credit->company_name,
                'credit_limit' => $credit->credit_limit,
                'proof_media_id' => $credit->proof_media_id,
            ])->all();
        }

        $data['visit'] = $application->visit === null ? null : [
            'id' => $application->visit->public_id,
            'started_at' => $application->visit->started_at->toISOString(),
            'completed_at' => $application->visit->completed_at?->toISOString(),
            'result' => $application->visit->result?->value,
            'observations' => $application->visit->observations,
            'lock_version' => $application->visit->lock_version,
        ];
        $data['differences'] = $application->differences->map(fn (VerificationDifference $difference): array => [
            'id' => $difference->public_id,
            'section' => $difference->section->value,
            'field_path' => $difference->field_path,
            'declared_value' => $difference->declared_value,
            'observed_value' => $difference->observed_value,
            'description' => $difference->description,
            'classification_code' => $difference->classification_code,
            'evidence_media_id' => $difference->evidence_media_id,
            'recorded_at' => $difference->recorded_at->toISOString(),
            'resolved_at' => $difference->resolved_at?->toISOString(),
        ])->all();
        $data['corrections'] = $application->corrections->map(fn (ApplicationCorrection $correction): array => [
            'id' => $correction->public_id,
            'difference_id' => $correction->difference?->public_id,
            'section' => $correction->section->value,
            'field_path' => $correction->field_path,
            'original_value' => $actor->role === RoleCode::ADMINISTRATOR ? null : $correction->original_value,
            'corrected_value' => $actor->role === RoleCode::ADMINISTRATOR ? null : $correction->corrected_value,
            'reason' => $correction->reason,
            'corrected_by' => $correction->corrector === null ? null : [
                'id' => $correction->corrector->public_id,
                'name' => $correction->corrector->name,
            ],
            'corrected_at' => $correction->corrected_at->toISOString(),
        ])->all();
        $data['evaluation'] = $application->evaluation === null ? null : [
            'id' => $application->evaluation->public_id,
            'decision' => $application->evaluation->decision->value,
            'reason' => $application->evaluation->reason,
            'application_version' => $application->evaluation->application_version,
            'decided_at' => $application->evaluation->decided_at->toISOString(),
        ];
        $data['authorization'] = $application->authorization === null ? null : [
            'id' => $application->authorization->public_id,
            'decision' => $application->authorization->decision->value,
            'initial_credit_line' => $application->authorization->initial_credit_line,
            'reason' => $application->authorization->reason,
            'decided_at' => $application->authorization->decided_at->toISOString(),
        ];
        $data['activation'] = $application->activation === null ? null : $this->activation($application);

        return $data;
    }

    /** @return array<string, mixed> */
    public function activation(DistributorApplication $application): array
    {
        $activation = $application->activation;

        return [
            'id' => $activation?->public_id,
            'distributor_id' => $activation?->distributor_id,
            'distributor_number' => $activation?->distributor_number,
            'account_id' => $activation?->account_id,
            'organization_assignment_id' => $activation?->organization_assignment_id,
            'credit_line_id' => $activation?->credit_line_id,
            'initial_credit_line' => $activation?->initial_credit_line,
            'activated_at' => $activation?->activated_at?->toISOString(),
        ];
    }

    /** @return list<string> */
    private function allowedActions(DistributorApplication $application, ActorContext $actor): array
    {
        $actions = [];
        $status = $application->status;

        if ($status === ApplicationStatus::CAPTURE && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_UPDATE_CAPTURE->value)) {
            $actions[] = 'update_capture';
        }
        if ($status === ApplicationStatus::CAPTURE && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_SUBMIT->value)) {
            $actions[] = 'submit';
        }
        if ($status === ApplicationStatus::COORDINATOR_REVIEW && $actor->userId === $application->coordinator_user_id && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_REVIEW->value)) {
            $actions[] = 'request_document_correction';
        }
        if ($status === ApplicationStatus::COORDINATOR_REVIEW && $actor->hasPermission(PermissionCode::ONBOARDING_VERIFICATIONS_ASSIGN->value)) {
            $actions[] = 'assign_verifier';
        }
        if ($status === ApplicationStatus::VISIT_ASSIGNED && $actor->hasPermission(PermissionCode::ONBOARDING_VERIFICATIONS_PERFORM->value)) {
            $actions[] = 'start_visit';
        }
        if ($status === ApplicationStatus::PHYSICAL_VERIFICATION && $actor->hasPermission(PermissionCode::ONBOARDING_VERIFICATIONS_PERFORM->value)) {
            $actions[] = 'record_difference';
            $actions[] = 'complete_visit';
        }
        if ($status === ApplicationStatus::COORDINATOR_CORRECTION && $actor->userId === $application->coordinator_user_id && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_CORRECT->value)) {
            $actions[] = 'record_correction';
            $actions[] = 'complete_corrections';
        }
        if ($status === ApplicationStatus::COORDINATOR_EVALUATION && $actor->userId === $application->coordinator_user_id && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_EVALUATE->value)) {
            $actions[] = 'record_coordinator_decision';
        }
        if ($status === ApplicationStatus::MANAGER_AUTHORIZATION && ($actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_AUTHORIZE_GLOBAL->value) || ($actor->branchId === $application->branch_id && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_AUTHORIZE_BRANCH->value)))) {
            $actions[] = 'record_manager_decision';
        }

        return $actions;
    }

    private function mask(string $value): string
    {
        return mb_strlen($value) <= 4 ? '****' : str_repeat('*', mb_strlen($value) - 4).mb_substr($value, -4);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
