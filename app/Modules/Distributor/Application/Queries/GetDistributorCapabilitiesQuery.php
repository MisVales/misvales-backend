<?php

namespace App\Modules\Distributor\Application\Queries;

use App\Modules\Distributor\Domain\Distributors\DistributorDomainException;
use App\Modules\Distributor\Persistence\Models\Distributor;

class GetDistributorCapabilitiesQuery
{
    public function execute(string $distributorId)
    {
        $distributor = Distributor::find($distributorId);

        if (! $distributor) {
            throw DistributorDomainException::notFound();
        }

        // Lógica para derivar capacidades basándose en estatus, M01, M02, M03, M14 (DI08)
        return [
            'can_access' => $distributor->status === 'ACTIVE',
            'can_issue_vouchers' => false, // Simplificación hasta integrarse con otros módulos
            'can_request_credit_increase' => false,
            'can_view_relations' => true,
            'can_submit_clarifications' => true,
            'can_request_point_redemption' => false,
            'blocking_codes' => [],
        ];
    }
}
