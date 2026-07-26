<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use App\Modules\Payment\Application\Security\PaymentActorContext;

/** Conserva eventos funcionales de M11 sin exponer información bancaria sensible. */
interface PaymentAuditPort
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        string $result,
        ?PaymentActorContext $actor,
        ?string $resourceType,
        ?string $resourceId,
        array $before,
        array $after,
        array $metadata,
        string $requestId,
        ?string $reason = null,
    ): void;
}
