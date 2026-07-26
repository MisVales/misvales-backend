<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Security;

use App\Modules\Access\Domain\Authorization\RoleCode;

/** Contexto efectivo mínimo de M09. */
final readonly class VoucherActorContext
{
    /** @param list<string> $permissions */
    public function __construct(
        public int $userId,
        public string $publicId,
        public RoleCode $role,
        public ?int $branchId,
        public ?string $branchPublicId,
        public array $permissions,
    ) {}

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
