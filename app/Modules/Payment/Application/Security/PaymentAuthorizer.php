<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Security;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Centraliza permiso, rol, sucursal, propiedad y relación jerárquica de M11. */
final class PaymentAuthorizer
{
    public function assertPermission(PaymentActorContext $actor, PermissionCode $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw PaymentDomainException::authorizationDenied();
        }
    }

    /** @param list<PermissionCode> $permissions */
    public function assertAnyPermission(PaymentActorContext $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($actor->can($permission->value)) {
                return;
            }
        }

        throw PaymentDomainException::authorizationDenied();
    }

    public function assertBranch(PaymentActorContext $actor, int $branchId): void
    {
        if ($actor->role->isGlobal()) {
            return;
        }
        if ($actor->branchId === null || $actor->branchId !== $branchId) {
            throw PaymentDomainException::notFound();
        }
    }

    public function assertOwner(PaymentActorContext $actor, int $distributorId): void
    {
        if ($actor->userId !== $distributorId) {
            throw PaymentDomainException::notFound();
        }
    }
}
