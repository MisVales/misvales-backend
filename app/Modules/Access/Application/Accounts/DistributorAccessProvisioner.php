<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\DistributorAccessProvisioned;
use App\Modules\Access\Domain\Accounts\DistributorFinalAuthorizationCompleted;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Access\Infrastructure\Persistence\Models\ProcessedDomainEvent;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Support\Facades\DB;
use Normalizer;

final readonly class DistributorAccessProvisioner
{
    public function __construct(
        private InvitationIssuer $invitations,
        private AccountSecurityRecorder $recorder,
    ) {}

    public function handle(DistributorFinalAuthorizationCompleted $event): User
    {
        return DB::transaction(function () use ($event): User {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$event->eventKey]);
            }

            $processed = ProcessedDomainEvent::query()->where('event_key', $event->eventKey)->lockForUpdate()->first();
            if ($processed !== null) {
                return DistributorAccessLink::query()->where('external_request_id', $event->requestId)->firstOrFail()->user;
            }
            if (! $event->isFinal || bccomp($event->initialCreditLine, '0', 2) < 0) {
                throw new AccessRuleViolation('La autorización final de distribuidora no es válida.');
            }

            $branch = Branch::query()->whereKey($event->branchId)->where('is_active', true)->lockForUpdate()->first();
            $coordinator = User::query()->with('role')->whereKey($event->coordinatorUserId)->lockForUpdate()->first();
            $authorizer = User::query()->with('role')->whereKey($event->authorizedBy)->first();
            if ($branch === null
                || $coordinator === null
                || $coordinator->state !== AccountState::ACTIVE
                || $coordinator->branch_id !== $branch->id
                || $coordinator->role->code !== RoleCode::COORDINATOR
                || $authorizer?->state !== AccountState::ACTIVE
                || $authorizer->role->code !== RoleCode::GENERAL_MANAGER) {
                throw new AccessRuleViolation('La sucursal, asignación o autorización final dejó de ser válida.', 409);
            }

            $email = mb_strtolower(trim(Normalizer::normalize($event->email, Normalizer::FORM_C) ?: $event->email));
            if (User::query()->where('normalized_email', $email)->exists()) {
                throw new AccessRuleViolation('El correo ya está asociado a una cuenta.', 409);
            }

            $role = Role::query()->where('code', RoleCode::DISTRIBUTOR->value)->where('is_active', true)->firstOrFail();
            $user = new User;
            $user->forceFill([
                'name' => trim(Normalizer::normalize($event->name, Normalizer::FORM_C) ?: $event->name),
                'email' => $email,
                'normalized_email' => $email,
                'password' => null,
                'role_id' => $role->id,
                'branch_id' => $branch->id,
                'state' => AccountState::PENDING_ACTIVATION,
                'context_version' => 1,
                'credential_version' => 1,
                'invited_at' => now(),
            ])->save();

            DistributorAccessLink::query()->create([
                'user_id' => $user->id,
                'external_request_id' => $event->requestId,
                'external_distributor_id' => $event->distributorId,
                'branch_id' => $branch->id,
                'coordinator_user_id' => $coordinator->id,
                'authorized_by' => $authorizer->id,
                'initial_credit_line' => $event->initialCreditLine,
                'authorized_at' => $event->authorizedAt,
            ]);
            ProcessedDomainEvent::query()->create([
                'event_type' => DistributorFinalAuthorizationCompleted::class,
                'event_key' => $event->eventKey,
                'processed_at' => now(),
            ]);
            $this->invitations->issue($user, InvitationPurpose::ACCOUNT_ACTIVATION);
            $this->recorder->audit('DISTRIBUTOR_ACCESS_PROVISIONED', 'SUCCESS', $authorizer, $user, [
                'request_id' => $event->requestId,
                'distributor_id' => $event->distributorId,
                'coordinator_id' => $coordinator->public_id,
                'event_key' => $event->eventKey,
            ]);
            $this->recorder->outbox('DISTRIBUTOR_ACCESS_PROVISIONED', "distributor-access:{$event->eventKey}", [
                'user_id' => $user->public_id,
                'distributor_id' => $event->distributorId,
                'event_key' => $event->eventKey,
            ]);

            DB::afterCommit(fn () => DistributorAccessProvisioned::dispatch($user->public_id, $event->distributorId, $event->eventKey));

            return $user;
        });
    }
}
