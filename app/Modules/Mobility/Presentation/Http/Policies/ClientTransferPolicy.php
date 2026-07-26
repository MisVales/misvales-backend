<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Mobility\Application\Security\MobilityAccessService;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;

final readonly class ClientTransferPolicy
{
    public function __construct(private MobilityAccessService $access) {}

    public function view(User $actor, ClientTransfer $transfer): bool
    {
        try {
            $this->access->assertTransferVisible($actor, $transfer);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
