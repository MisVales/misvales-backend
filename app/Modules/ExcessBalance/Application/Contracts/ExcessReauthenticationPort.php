<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

use App\Models\User;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;

interface ExcessReauthenticationPort
{
    public function consume(
        User $actor,
        string $plainToken,
        RefundRequestModel $request,
        string $decision,
        ?string $reason,
    ): void;
}
