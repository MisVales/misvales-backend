<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\ExcessBalance\Application\Contracts\ExcessReauthenticationPort;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;

final class FakeExcessReauthentication implements ExcessReauthenticationPort
{
    public function consume(
        User $actor,
        string $plainToken,
        RefundRequestModel $request,
        string $decision,
        ?string $reason,
    ): void {}
}
