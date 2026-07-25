<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Enums\PaymentBehavior;
use JsonSerializable;

/**
 * Regla individual de la política de puntos por comportamiento de pago.
 *
 * Cada regla define si un comportamiento específico genera o reduce puntos.
 *
 * @see PaymentBehaviorPointsPolicy
 */
final readonly class PaymentBehaviorRule implements JsonSerializable
{
    public function __construct(
        public PaymentBehavior $behavior,
        public bool $generatesPoints,
        public bool $reducesPoints,
    ) {}

    /**
     * @param array{behavior: string, generates_points: bool, reduces_points: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            behavior: PaymentBehavior::from($data['behavior']),
            generatesPoints: $data['generates_points'],
            reducesPoints: $data['reduces_points'],
        );
    }

    /**
     * @return array{behavior: string, generates_points: bool, reduces_points: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'behavior' => $this->behavior->value,
            'generates_points' => $this->generatesPoints,
            'reduces_points' => $this->reducesPoints,
        ];
    }
}
