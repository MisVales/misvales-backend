<?php

namespace App\Modules\Distributor\Domain\Contracts;

interface ProvisionAuthorizedDistributor
{
    public function handle(ProvisionAuthorizedDistributorCommand $command): string;
}
