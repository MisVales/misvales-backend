<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Organization\Domain\Branches\Branch;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

final class HeadquartersBranchSeeder extends Seeder
{
    private const CODE = 'TOR-MATRIZ';

    private const NAME = 'Sucursal Matriz Torreón';

    public function __construct(private readonly BranchRepository $branches) {}

    public function run(?string $createdBy = null): void
    {
        if ($this->branches->headquarters() !== null) {
            return;
        }

        $actorId = $createdBy ?? $this->generalManagerId();

        if ($actorId === null || ! User::query()->whereKey($actorId)->exists()) {
            throw new RuntimeException('No existe un gerente general que pueda registrarse como creador de la sucursal matriz.');
        }

        $code = BranchCode::fromString(self::CODE);
        $branchWithCode = $this->branches->findByCode($code);

        if ($branchWithCode !== null) {
            throw new RuntimeException('El código TOR-MATRIZ ya pertenece a una sucursal que no es matriz.');
        }

        $branch = Branch::create(
            id: BranchId::fromString(Str::uuid()->toString()),
            code: $code,
            name: BranchName::fromString(self::NAME),
            headquarters: true,
        );

        $this->branches->save($branch, $actorId);
    }

    private function generalManagerId(): ?string
    {
        return User::query()
            ->whereHas('roleScopes', function ($query): void {
                $query
                    ->whereNull('revoked_at')
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager'));
            })
            ->oldest('created_at')
            ->value('id');
    }
}
