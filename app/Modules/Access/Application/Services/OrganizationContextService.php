<?php

namespace App\Modules\Access\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Contracts\OrganizationContextProviderInterface;

class OrganizationContextService implements OrganizationContextProviderInterface
{
    public function getUserContext(int $userId): array
    {
        $user = User::with(['role', 'branch'])->find($userId);

        if (! $user) {
            return [];
        }

        $roleCode = $user->role->code ?? null;
        if ($roleCode instanceof \BackedEnum) {
            $roleCode = $roleCode->value;
        }

        return [
            'user_id' => $user->id,
            'role_code' => (string) $roleCode,
            'role_scope' => $user->role->scope ?? 'LOCAL',
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch->name ?? null,
        ];
    }
}
