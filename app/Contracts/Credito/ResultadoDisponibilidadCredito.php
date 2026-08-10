<?php

namespace App\Contracts\Credito;

class ResultadoDisponibilidadCredito
{
    public function __construct(
        public readonly string $credit_line_id,
        public readonly string $total_authorized,
        public readonly string $used_balance,
        public readonly string $available_balance,
        public readonly bool $has_active_restriction,
        public readonly ?string $restriction_id,
        public readonly ?string $lower_limit,
        public readonly ?string $upper_limit,
        public readonly bool $capital_is_available,
        public readonly bool $capital_satisfies_restriction,
    ) {
    }
}
