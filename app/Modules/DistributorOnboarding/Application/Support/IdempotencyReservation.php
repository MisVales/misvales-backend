<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Persistence\Models\OnboardingIdempotencyKey;

/** Resultado de reservar una clave o recuperar una respuesta ya confirmada. */
final readonly class IdempotencyReservation
{
    /** @param array<string, mixed>|null $replayedPayload */
    public function __construct(
        public OnboardingIdempotencyKey $record,
        public ?array $replayedPayload,
    ) {}

    public function isReplay(): bool
    {
        return $this->replayedPayload !== null;
    }
}
