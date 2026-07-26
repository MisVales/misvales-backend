<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use App\Models\User;

interface OrganizationScopePort
{
    public function assertManagerScope(User $actor, User $distributor): void;

    public function assertResponsibleCoordinator(User $actor, User $distributor): void;
}
