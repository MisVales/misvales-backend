<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ModificationRequestRepository
{
    public function hasActiveForVoucher(string $voucherId): bool;

    public function lockScoped(string $id, VoucherActorContext $actor): DataChangeRequestModel;

    public function findScoped(string $id, VoucherActorContext $actor): DataChangeRequestModel;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DataChangeRequestModel>
     */
    public function list(VoucherActorContext $actor, array $filters): LengthAwarePaginator;
}
