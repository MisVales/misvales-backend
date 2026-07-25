<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Services;

use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Enums\ConfigurationType;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\EarlyPaymentPeriod;
use App\Modules\Configuration\Domain\ValueObjects\Money;
use App\Modules\Configuration\Domain\ValueObjects\PaymentBehaviorPointsPolicy;
use App\Modules\Configuration\Domain\ValueObjects\Percentage;
use App\Modules\Configuration\Domain\ValueObjects\TimeOfDay;
use App\Modules\Configuration\Domain\ValueObjects\TimezoneValue;

/**
 * Validador dinámico de valores de configuración por tipo (C04).
 *
 * Cada tipo tiene un validador específico. No se convierte
 * silenciosamente una cadena inválida en cero.
 */
final class ConfigurationValueValidator
{
    /**
     * Valida el valor conforme al tipo esperado para la clave.
     *
     * @throws ConfigurationException Si el valor no cumple el tipo o validador.
     */
    public function validate(ConfigurationKey $key, string $value): void
    {
        match ($key->type()) {
            ConfigurationType::INTEGER => $this->validateInteger($key, $value),
            ConfigurationType::MONEY => $this->validateMoney($value),
            ConfigurationType::PERCENTAGE => $this->validatePercentage($value),
            ConfigurationType::TIME => $this->validateTime($value),
            ConfigurationType::TIMEZONE => $this->validateTimezone($value),
            ConfigurationType::TYPED_OBJECT => $this->validateTypedObject($key, $value),
        };

        // Validaciones específicas por clave
        $this->validateKeySpecificRules($key, $value);
    }

    private function validateInteger(ConfigurationKey $key, string $value): void
    {
        if (! preg_match('/^\d+$/', $value)) {
            throw ConfigurationException::valueInvalid(
                "El valor de «{$key->value}» debe ser un entero válido."
            );
        }

        $intValue = (int) $value;

        // Validaciones mínimas por clave (C04)
        match ($key) {
            ConfigurationKey::PAYMENT_DAYS_AFTER_CUT => $intValue >= 0 || throw ConfigurationException::valueInvalid(
                'Los días posteriores al corte deben ser mayor o igual que cero.'
            ),
            ConfigurationKey::POINTS_MULTIPLIER => $intValue > 0 || throw ConfigurationException::valueInvalid(
                'El multiplicador de puntos debe ser mayor que cero.'
            ),
            ConfigurationKey::MODIFICATION_TOKEN_TTL_MINUTES => ($intValue > 0 && $intValue <= 5) || throw ConfigurationException::valueInvalid(
                'La vigencia del token debe ser un entero positivo que no exceda cinco minutos.'
            ),
            default => null,
        };
    }

    private function validateMoney(string $value): void
    {
        $money = new Money($value);

        // Los importes monetarios deben ser no negativos (C04)
        if (! $money->isNonNegative()) {
            throw ConfigurationException::valueInvalid(
                'El importe no puede ser negativo.'
            );
        }
    }

    private function validatePercentage(string $value): void
    {
        $pct = new Percentage($value);

        if (! $pct->isNonNegative()) {
            throw ConfigurationException::valueInvalid(
                'El porcentaje no puede ser negativo.'
            );
        }
    }

    private function validateTime(string $value): void
    {
        new TimeOfDay($value);
    }

    private function validateTimezone(string $value): void
    {
        new TimezoneValue($value);
    }

    private function validateTypedObject(ConfigurationKey $key, string $value): void
    {
        match ($key) {
            ConfigurationKey::EARLY_PAYMENT_PERIOD => EarlyPaymentPeriod::fromJson($value),
            ConfigurationKey::PAYMENT_BEHAVIOR_POINTS_POLICY => PaymentBehaviorPointsPolicy::fromJson($value),
            default => throw ConfigurationException::valueInvalid(
                "No existe validador de objeto tipado para «{$key->value}»."
            ),
        };
    }

    /**
     * Validaciones adicionales específicas por clave.
     */
    private function validateKeySpecificRules(ConfigurationKey $key, string $value): void
    {
        // Divisor de puntos debe ser mayor que cero
        if ($key === ConfigurationKey::POINTS_DIVISOR_AMOUNT) {
            $money = new Money($value);
            if (! $money->isPositive()) {
                throw ConfigurationException::valueInvalid(
                    'El divisor de puntos debe ser mayor que cero.'
                );
            }
        }
    }
}
