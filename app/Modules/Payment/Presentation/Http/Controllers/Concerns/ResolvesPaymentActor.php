<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Controllers\Concerns;

use App\Models\User;
use App\Modules\Payment\Application\Security\PaymentActorContext;
use App\Modules\Payment\Application\Security\PaymentActorContextFactory;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use Illuminate\Http\Request;

/** Construye el contexto de autorización desde la cuenta autenticada. */
trait ResolvesPaymentActor
{
    abstract protected function paymentContexts(): PaymentActorContextFactory;

    protected function paymentActor(Request $request): PaymentActorContext
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw PaymentDomainException::authorizationDenied();
        }

        return $this->paymentContexts()->fromUser($user);
    }

    protected function paymentRequestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }
}
