<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Access\Application\Accounts\AccountSecurityRecorder;
use App\Modules\Access\Application\Accounts\InvitationIssuer;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Normalizer;

class InitialGeneralManagerSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('access.initial_general_manager.enabled')) {
            return;
        }

        $rawEmail = config('access.initial_general_manager.email');
        $rawName = config('access.initial_general_manager.name');
        if (! is_string($rawEmail) || ! is_string($rawName) || trim($rawName) === '') {
            throw new AccessRuleViolation('La configuración del gerente general inicial es inválida.');
        }

        $email = mb_strtolower(trim(Normalizer::normalize($rawEmail, Normalizer::FORM_C) ?: $rawEmail));
        $name = trim(Normalizer::normalize($rawName, Normalizer::FORM_C) ?: $rawName);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AccessRuleViolation('La configuración del gerente general inicial es inválida.');
        }

        DB::transaction(function () use ($email, $name): void {
            $role = Role::query()->where('code', RoleCode::GENERAL_MANAGER->value)->where('scope', 'GLOBAL')->where('is_active', true)->firstOrFail();
            $user = User::query()->where('normalized_email', $email)->lockForUpdate()->first();
            if ($user !== null && $user->role_id !== $role->id) {
                throw new AccessRuleViolation('El correo configurado ya pertenece a otro rol.', 409);
            }

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'normalized_email' => $email,
                    'password' => null,
                    'role_id' => $role->id,
                    'branch_id' => null,
                    'state' => AccountState::PENDING_ACTIVATION,
                    'context_version' => 1,
                    'credential_version' => 1,
                    'invited_at' => now(),
                ])->save();
                app(AccountSecurityRecorder::class)->audit('INITIAL_GENERAL_MANAGER_PROVISIONED', 'SUCCESS', null, $user);
            }

            if ($user->state === AccountState::PENDING_ACTIVATION) {
                app(InvitationIssuer::class)->currentOrIssue($user, InvitationPurpose::ACCOUNT_ACTIVATION);
            }
        });
    }
}
