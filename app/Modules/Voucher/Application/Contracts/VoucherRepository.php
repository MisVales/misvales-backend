<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VoucherRepository
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, VoucherModel>
     */
    public function search(VoucherActorContext $actor, array $filters): LengthAwarePaginator;

    public function findScoped(string $id, VoucherActorContext $actor): VoucherModel;

    public function lockScoped(string $id, VoucherActorContext $actor): VoucherModel;
}
