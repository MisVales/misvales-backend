<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Security;

use App\Modules\Access\Domain\Authorization\RoleCode;

/** Contexto efectivo mínimo utilizado por M06 para denegar por defecto. */
final readonly class ClientActorContext
{
    /** @param list<string> $permissions */
    public function __construct(
        public int $userId,
        public RoleCode $role,
        public ?int $branchId,
        public array $permissions,
    ) {}

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
