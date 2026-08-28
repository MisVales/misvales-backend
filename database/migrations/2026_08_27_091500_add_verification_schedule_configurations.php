<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $actorId = DB::table('users')
                ->join('user_role_scopes', 'user_role_scopes.user_id', '=', 'users.id')
                ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                ->where('roles.code', 'general_manager')
                ->where('user_role_scopes.status', 'ACTIVE')
                ->whereNull('user_role_scopes.revoked_at')
                ->oldest('users.created_at')
                ->value('users.id')
                ?? DB::table('users')->oldest('created_at')->value('id');

            // En una instalación nueva las migraciones corren antes que los
            // seeders; en ese caso los seeders crearán estas definiciones.
            if (! is_string($actorId) || $actorId === '') {
                return;
            }

            $now = now();
            $configurations = [
                'VERIFICATION_START_TIME' => [
                    'name' => 'Hora inicio de visitas',
                    'description' => 'Primera hora global disponible para asignar e iniciar una visita de verificación.',
                    'value' => '08:00',
                ],
                'VERIFICATION_MAX_START_TIME' => [
                    'name' => 'Hora final de visitas',
                    'description' => 'Última hora global disponible para asignar e iniciar una visita de verificación.',
                    'value' => '23:45',
                ],
            ];

            foreach ($configurations as $key => $configuration) {
                $definitionId = DB::table('configuration_definitions')->where('key', $key)->value('id');

                if (! is_string($definitionId) || $definitionId === '') {
                    $definitionId = (string) Str::uuid();
                    DB::table('configuration_definitions')->insert([
                        'id' => $definitionId,
                        'key' => $key,
                        'name' => $configuration['name'],
                        'description' => $configuration['description'],
                        'value_type' => 'TIME',
                        'unit' => null,
                        'is_required' => true,
                        'is_sensitive' => false,
                        'status' => 'ACTIVE',
                        'lock_version' => 0,
                        'created_by' => $actorId,
                        'updated_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('configuration_definitions')->where('id', $definitionId)->update([
                        'name' => $configuration['name'],
                        'description' => $configuration['description'],
                        'value_type' => 'TIME',
                        'status' => 'ACTIVE',
                        'updated_at' => $now,
                    ]);
                }

                $hasPublishedVersion = DB::table('configuration_versions')
                    ->where('configuration_definition_id', $definitionId)
                    ->where('status', 'PUBLISHED')
                    ->whereNull('effective_to')
                    ->exists();

                if (! $hasPublishedVersion) {
                    $nextVersion = ((int) DB::table('configuration_versions')
                        ->where('configuration_definition_id', $definitionId)
                        ->max('version')) + 1;

                    DB::table('configuration_versions')->insert([
                        'id' => (string) Str::uuid(),
                        'configuration_definition_id' => $definitionId,
                        'version' => $nextVersion,
                        'value' => json_encode($configuration['value'], JSON_THROW_ON_ERROR),
                        'status' => 'PUBLISHED',
                        'effective_from' => $now,
                        'effective_to' => null,
                        'reason' => 'Horario global inicial de verificaciones',
                        'created_by' => $actorId,
                        'published_by' => $actorId,
                        'published_at' => $now,
                        'lock_version' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $definitionIds = DB::table('configuration_definitions')
            ->whereIn('key', ['VERIFICATION_START_TIME', 'VERIFICATION_MAX_START_TIME'])
            ->pluck('id');

        DB::table('configuration_versions')->whereIn('configuration_definition_id', $definitionIds)->delete();
        DB::table('configuration_definitions')->whereIn('id', $definitionIds)->delete();
    }
};
