<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTOs;

use stdClass;

/** Resultado de reservar una clave idempotente o recuperar su respuesta previa. */
final readonly class PaymentIdempotencyReservation
{
    /** @param array<string, mixed>|null $replay */
    public function __construct(
        public stdClass $record,
        public ?array $replay,
    ) {}
}
