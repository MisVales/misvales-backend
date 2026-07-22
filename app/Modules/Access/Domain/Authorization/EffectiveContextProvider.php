<?php

namespace App\Modules\Access\Domain\Authorization;

/** Provee el contexto efectivo sin acoplar M02 a la implementación de autenticación. */
interface EffectiveContextProvider
{
    /** Obtiene el contexto vigente de una cuenta. */
    public function forUser(int $userId): EffectiveContext;
}
