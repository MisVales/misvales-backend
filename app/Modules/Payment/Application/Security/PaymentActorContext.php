<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Security;

use App\Modules\Access\Domain\Authorization\RoleCode;

/** Contexto efectivo resuelto exclusivamente desde M01. */
final readonly class PaymentActorContext
{
    /** @param list<string> $permissions */
    public function __construct(
        public int $userId,
        public string $publicId,
        public RoleCode $role,
        public ?int $branchId,
        public ?int $coordinatorId,
        public array $permissions,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
