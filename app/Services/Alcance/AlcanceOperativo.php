<?php

namespace App\Services\Alcance;

use App\Enums\TipoAlcanceOperativo;

final readonly class AlcanceOperativo
{
    /** @param list<string> $branchIds */
    public function __construct(
        public TipoAlcanceOperativo $tipo,
        public string $actorId,
        public array $branchIds = [],
    ) {}

    public function esPersonal(): bool
    {
        return $this->tipo === TipoAlcanceOperativo::PERSONAL;
    }

    public function esSucursal(): bool
    {
        return $this->tipo === TipoAlcanceOperativo::SUCURSAL;
    }

    public function esGlobal(): bool
    {
        return $this->tipo === TipoAlcanceOperativo::GLOBAL;
    }
}
