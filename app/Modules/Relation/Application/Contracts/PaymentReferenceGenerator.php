<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Contracts;

use App\Modules\Relation\Domain\ValueObjects\PaymentReference;

interface PaymentReferenceGenerator
{
    /**
     * Generates a unique, immutable payment reference for the relation.
     * The exact format is pending business definition.
     *
     * @param string $relationId
     * @param string $distributorId
     * @return PaymentReference
     */
    public function generateFor(string $relationId, string $distributorId): PaymentReference;
}
