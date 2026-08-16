<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

final class InitialGeneralManagerSeeder extends Seeder
{
    public function run(): void
    {
        $invitation = DB::transaction(function (): ?array {
            $role = Role::query()->where('code', 'general_manager')->firstOrFail();
            $user = $this->resolveManager();

            UserRoleScope::query()->firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => 'GLOBAL',
                'status' => 'ACTIVE',
                'revoked_at' => null,
            ], [
                'branch_id' => null,
                'scope_id' => null,
                'assigned_by_user_id' => $user->id,
                'assigned_at' => now(),
                'assignment_reason' => 'Bootstrap inicial del Gerente General',
            ]);

            if (app()->environment(['local', 'testing']) || $user->state === 'ACTIVE') {
                return null;
            }

            $existingInvitation = AccountInvitation::query()
                ->where('user_id', $user->id)
                ->whereIn('state', ['ACTIVE', 'PREPARED'])
                ->where('expires_at', '>', now())
                ->exists();

            if ($existingInvitation) {
                return null;
            }

            $token = Str::random(40);

            AccountInvitation::query()->create([
                'user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'purpose' => 'ACCOUNT_ACTIVATION',
                'token_hash' => hash('sha256', $token),
                'state' => 'ACTIVE',
                'expires_at' => now()->addHours(48),
            ]);

            return [$user->id, $token];
        });

        if ($invitation !== null) {
            $user = User::query()->findOrFail($invitation[0]);
            Mail::to($user->email)->send(new ActivationInvitationMail($user, $invitation[1]));
        }
    }

    private function resolveManager(): User
    {
        $existingManager = User::query()
            ->whereHas('roleScopes', fn ($query) => $query
                ->where('scope_type', 'GLOBAL')
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager')))
            ->oldest('created_at')
            ->first();

        if ($existingManager !== null) {
            return $existingManager;
        }

        $enabled = (bool) config('bootstrap.initial_general_manager.enabled', false);
        $name = trim((string) config('bootstrap.initial_general_manager.name'));
        $email = trim((string) config('bootstrap.initial_general_manager.email'));

        if (! $enabled || $name === '' || $email === '') {
            throw new RuntimeException('Configure INITIAL_GENERAL_MANAGER_ENABLED, INITIAL_GENERAL_MANAGER_NAME e INITIAL_GENERAL_MANAGER_EMAIL para inicializar el sistema.');
        }

        return User::query()->firstOrCreate(
            ['normalized_email' => Str::lower($email)],
            [
                'name' => $name,
                'email' => $email,
                'password' => null,
                'state' => 'INVITED',
            ],
        );
    }
}
