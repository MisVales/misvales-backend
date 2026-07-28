<?php

namespace App\Modules\Distributor\Application\Queries;

use App\Modules\Distributor\Domain\Distributors\DistributorDomainException;
use App\Modules\Distributor\Persistence\Models\Distributor;

class GetDistributorDetailQuery
{
    public function execute(string $id)
    {
        $distributor = Distributor::find($id);

        if (! $distributor) {
            throw DistributorDomainException::notFound();
        }

        // Aquí se consolidaría con otros módulos según DI09 para retornar un DTO o arreglo estructurado.
        return $distributor;
    }
}
