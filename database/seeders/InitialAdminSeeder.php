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

final class InitialAdminSeeder extends Seeder
{
    private const EMAIL = 'jesusmanueldelarosaguillen@gmail.com';

    public function run(): void
    {
        $invitation = DB::transaction(function (): ?array {
            $role = Role::query()->where('code', 'admin')->firstOrFail();
            $user = User::query()->firstOrCreate(
                ['normalized_email' => self::EMAIL],
                [
                    'name' => 'Jesús Manuel de la Rosa Guillén',
                    'email' => self::EMAIL,
                    'password' => null,
                    'state' => 'INVITED',
                ],
            );

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
                'assignment_reason' => 'Bootstrap inicial del administrador',
            ]);

            if (app()->environment(['local', 'testing']) || $user->state === 'ACTIVE') {
                return null;
            }

            $hasActiveInvitation = AccountInvitation::query()
                ->where('user_id', $user->id)
                ->whereIn('state', ['ACTIVE', 'PREPARED'])
                ->where('expires_at', '>', now())
                ->exists();

            if ($hasActiveInvitation) {
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
}
