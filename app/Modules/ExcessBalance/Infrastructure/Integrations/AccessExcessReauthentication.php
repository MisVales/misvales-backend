<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\ExcessBalance\Application\Contracts\ExcessReauthenticationPort;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;

final readonly class AccessExcessReauthentication implements ExcessReauthenticationPort
{
    public function __construct(private TemporaryAuthorization $authorizations) {}

    public function consume(
        User $actor,
        string $plainToken,
        RefundRequestModel $request,
        string $decision,
        ?string $reason,
    ): void {
        $branchPublicId = Branch::query()->whereKey($request->branch_id)->value('public_id');
        $this->authorizations->consume($actor, $plainToken, new AuthorizationBinding(
            action: CriticalAction::EXCESS_REFUND_DECIDE,
            resourceType: 'refund_requests',
            resourceId: $request->id,
            branchId: is_string($branchPublicId) ? $branchPublicId : null,
            parameters: [
                'decision' => $decision,
                'requested_amount' => (string) $request->amount,
                'reason' => $reason,
            ],
            reason: $reason,
        ));
    }
}
