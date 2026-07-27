<?php

namespace App\Modules\Access\Domain\Contracts;

interface OrganizationContextProviderInterface
{
    /**
     * Retorna un arreglo con el contexto organizacional del usuario.
     */
    public function getUserContext(int $userId): array;
}