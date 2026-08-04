<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BootstrapGeneralManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'misvales:bootstrap-manager';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bootstrap the initial general manager from environment variables.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!env('INITIAL_GENERAL_MANAGER_ENABLED', false)) {
            $this->info('General Manager bootstrap is disabled in .env.');
            return;
        }

        $email = env('INITIAL_GENERAL_MANAGER_EMAIL');
        $name = env('INITIAL_GENERAL_MANAGER_NAME');

        if (empty($email) || empty($name)) {
            $this->error('Missing INITIAL_GENERAL_MANAGER_EMAIL or INITIAL_GENERAL_MANAGER_NAME in .env.');
            return;
        }

        $normalizedEmail = strtolower(trim($email));

        $user = User::firstOrCreate(
            ['normalized_email' => $normalizedEmail],
            [
                'email' => $email,
                'name' => $name,
                'state' => 'INVITED', // Conforme a la convención acordada
            ]
        );

        $role = Role::where('code', 'general_manager')->first();

        if (!$role) {
            $this->error('The general_manager role does not exist. Please run the roles seeder first.');
            return;
        }

        // Asignar el rol global (branch_id = null) de manera idempotente
        $scope = UserRoleScope::firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => null, // Alcance global
            'revoked_at' => null,
        ], [
            'assigned_at' => now(),
        ]);

        // Generar invitación (Punto 11)
        // Revisamos si ya tiene una invitación activa para no generar basura
        $invitation = \App\Models\AccountInvitation::where('user_id', $user->id)
            ->whereIn('state', ['ACTIVE', 'PREPARED'])
            ->first();

        if (!$invitation) {
            $rawToken = \Illuminate\Support\Str::random(40);
            
            \App\Models\AccountInvitation::create([
                'user_id' => $user->id,
                'created_by_user_id' => $user->id, // El sistema/él mismo
                'purpose' => 'ACCOUNT_ACTIVATION',
                'token_hash' => hash('sha256', $rawToken),
                'state' => 'ACTIVE',
                'expires_at' => now()->addHours(48),
            ]);

            // Enviar correo (Punto 12)
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\ActivationInvitationMail($user, $rawToken));
            $this->info("Invitation generated and email sent to [{$email}]!");
        } else {
            $this->warn("User [{$email}] already has an active invitation.");
        }

        $this->info("General Manager [{$email}] bootstrapped successfully with global scope!");
    }
}
