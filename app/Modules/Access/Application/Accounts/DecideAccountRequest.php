<?php

namespace App\Modules\Access\Application\Accounts;

use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Registra una única decisión final sobre una solicitud de cuenta. */
final class DecideAccountRequest
{
    /** @throws DomainException Si la solicitud ya tiene una decisión final. */
    public function execute(int $requestId, int $decidedBy, AccountRequestState $decision, string $reason): AccountRequest
    {
        if (! in_array($decision, [AccountRequestState::APPROVED, AccountRequestState::REJECTED], true)) {
            throw new DomainException('The decision must be approved or rejected.');
        }

        return DB::transaction(function () use ($requestId, $decidedBy, $decision, $reason): AccountRequest {
            $request = AccountRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->state !== AccountRequestState::PENDING_APPROVAL) {
                throw new DomainException('The account request already has a final decision.');
            }

            $request->forceFill([
                'state' => $decision,
                'decision' => $decision->value,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            return $request;
        });
    }
}
