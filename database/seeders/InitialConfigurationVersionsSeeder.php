<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InitialConfigurationVersionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $managerId = $this->managerId();
            $publishedAt = now();

            foreach ($this->values() as $key => $value) {
                $definition = ConfigurationDefinition::query()->where('key', $key)->firstOrFail();
                $versionOne = ConfigurationVersion::query()
                    ->where('configuration_definition_id', $definition->id)
                    ->where('version', 1)
                    ->first();

                if ($versionOne !== null) {
                    continue;
                }

                $hasOpenPublishedVersion = ConfigurationVersion::query()
                    ->where('configuration_definition_id', $definition->id)
                    ->where('status', 'PUBLISHED')
                    ->whereNull('effective_to')
                    ->exists();

                if ($hasOpenPublishedVersion) {
                    continue;
                }

                ConfigurationVersion::query()->create([
                    'configuration_definition_id' => $definition->id,
                    'version' => 1,
                    'value' => $value,
                    'status' => 'PUBLISHED',
                    'effective_from' => $publishedAt,
                    'effective_to' => null,
                    'reason' => 'Configuración inicial de MisVales',
                    'created_by' => $managerId,
                    'published_by' => $managerId,
                    'published_at' => $publishedAt,
                    'lock_version' => 0,
                ]);
            }
        });
    }

    /** @return array<string, int|string> */
    private function values(): array
    {
        return [
            'CUT_DAY_OF_MONTH' => 25,
            'PAYMENT_DAYS_AFTER_CUT' => 20,
            'BUSINESS_TIMEZONE' => 'America/Monterrey',
            'CUT_TIME' => '00:05',
            'PAYMENT_DEADLINE_TIME' => '23:59:59',
            'BANK_UPLOAD_DEADLINE_TIME' => '08:00',
            'POST_DUE_EVALUATION_TIME' => '08:30',
            'CREDIT_TOLERANCE_AMOUNT' => '500.0000',
            'LATE_FEE_AMOUNT' => '300.0000',
            'MODIFICATION_TOKEN_TTL' => 5,
        ];
    }

    private function managerId(): string
    {
        return User::query()
            ->whereHas('roleScopes', fn ($query) => $query
                ->where('scope_type', 'GLOBAL')
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager')))
            ->oldest('created_at')
            ->value('id') ?? throw new RuntimeException('No existe un gerente general para publicar las configuraciones iniciales.');
    }
}
