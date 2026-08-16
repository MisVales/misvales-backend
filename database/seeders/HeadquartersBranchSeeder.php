<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HeadquartersBranchSeeder extends Seeder
{
    public function run(?string $createdBy = null): void
    {
        DB::transaction(function () use ($createdBy): void {
            $actorId = $createdBy ?? $this->generalManagerId();

            if ($actorId === null || ! User::query()->whereKey($actorId)->exists()) {
                throw new RuntimeException('No existe un gerente general que pueda registrarse como creador de la sucursal matriz.');
            }

            $headquarters = BranchRecord::query()->where('is_headquarters', true)->lockForUpdate()->first();
            $matrixByCode = BranchRecord::query()->where('code', 'MATRIZ')->lockForUpdate()->first();

            if ($headquarters !== null && $matrixByCode !== null && ! $headquarters->is($matrixByCode)) {
                throw new RuntimeException('No es posible conciliar la sucursal matriz: el código MATRIZ pertenece a otro registro.');
            }

            $matrix = $headquarters ?? $matrixByCode ?? new BranchRecord;
            $matrix->fill([
                'code' => 'MATRIZ',
                'name' => 'Sucursal Matriz',
                'address' => 'Torreón, Coahuila',
                'address_validation_id' => null,
                'address_place_id' => null,
                'address_latitude' => null,
                'address_longitude' => null,
                'address_validated_at' => null,
                'is_headquarters' => true,
                'status' => 'ACTIVE',
                'lock_version' => 0,
                'created_by' => $matrix->exists ? $matrix->created_by : $actorId,
                'updated_by' => $matrix->exists ? $actorId : null,
            ]);
            $matrix->save();
        });
    }

    private function generalManagerId(): ?string
    {
        return User::query()
            ->whereHas('roleScopes', fn ($query) => $query
                ->where('scope_type', 'GLOBAL')
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager')))
            ->oldest('created_at')
            ->value('id');
    }
}
