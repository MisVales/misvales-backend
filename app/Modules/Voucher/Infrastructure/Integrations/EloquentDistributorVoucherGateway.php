<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Persistence\Models\DistributorCategoryAssignment;
use App\Modules\Voucher\Application\Contracts\DistributorVoucherGateway;
use App\Modules\Voucher\Application\DTOs\DistributorContext;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/** Resuelve y bloquea el perfil M05 correspondiente a la sesión, nunca al cuerpo. */
final class EloquentDistributorVoucherGateway implements DistributorVoucherGateway
{
    public function lockAuthenticated(User $actor): DistributorContext
    {
        $actor->loadMissing('role', 'branch');
        if (
            $actor->role?->code !== RoleCode::DISTRIBUTOR
            || $actor->state !== AccountState::ACTIVE
            || $actor->branch_id === null
            || $actor->branch === null
            || ! $actor->branch->is_active
        ) {
            throw VoucherDomainException::generationPermissionDenied();
        }

        $distributor = Distributor::query()
            ->where('user_id', $actor->public_id)
            ->lockForUpdate()
            ->first();
        if ($distributor === null || $distributor->status !== 'ACTIVE') {
            throw VoucherDomainException::distributorInactive();
        }
        $branch = Branch::query()
            ->where('public_id', $distributor->branch_id)
            ->whereKey($actor->branch_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if ($branch === null) {
            throw VoucherDomainException::distributorInactive();
        }

        $now = now('UTC');
        $assignment = DistributorCategoryAssignment::query()
            ->where('distributor_id', $distributor->id)
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->latest('effective_from')
            ->lockForUpdate()
            ->first();
        if ($assignment === null) {
            throw VoucherDomainException::categoryUnavailable();
        }

        return new DistributorContext(
            id: $distributor->id,
            userId: (int) $actor->id,
            userPublicId: $actor->public_id,
            branchId: (int) $branch->id,
            branchPublicId: $branch->public_id,
            categoryId: $assignment->category_id,
            categoryVersionId: $assignment->category_version_id,
        );
    }
}
