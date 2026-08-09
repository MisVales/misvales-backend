<?php

namespace App\Modules\Organization\Domain\Assignments\Services;

use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;

interface OrganizationScopeResolver
{
    public function resolve(string $userId): EffectiveOrganizationScope;
}
