<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Claves estables de configuraciones aprobadas (C02).
 *
 * Cada clave identifica una regla de negocio cuyo valor procede
 * de versiones persistidas, nunca del código fuente.
 */
enum ConfigurationKey: string
{
    case CUT_DAY_OF_MONTH = 'CUT_DAY_OF_MONTH';
    case PAYMENT_DAYS_AFTER_CUT = 'PAYMENT_DAYS_AFTER_CUT';
    case EARLY_PAYMENT_PERIOD = 'EARLY_PAYMENT_PERIOD';
    case BUSINESS_TIMEZONE = 'BUSINESS_TIMEZONE';
    case CUT_TIME = 'CUT_TIME';
    case PAYMENT_DEADLINE_TIME = 'PAYMENT_DEADLINE_TIME';
    case BANK_UPLOAD_DEADLINE_TIME = 'BANK_UPLOAD_DEADLINE_TIME';
    case POST_DUE_EVALUATION_TIME = 'POST_DUE_EVALUATION_TIME';
    case CREDIT_TOLERANCE_AMOUNT = 'CREDIT_TOLERANCE_AMOUNT';
    case LATE_FEE_AMOUNT = 'LATE_FEE_AMOUNT';
    case POINTS_DIVISOR_AMOUNT = 'POINTS_DIVISOR_AMOUNT';
    case POINTS_MULTIPLIER = 'POINTS_MULTIPLIER';
    case POINT_VALUE_AMOUNT = 'POINT_VALUE_AMOUNT';
    case LATE_POINTS_REDUCTION_RATE = 'LATE_POINTS_REDUCTION_RATE';
    case PAYMENT_BEHAVIOR_POINTS_POLICY = 'PAYMENT_BEHAVIOR_POINTS_POLICY';
    case MODIFICATION_TOKEN_TTL_MINUTES = 'MODIFICATION_TOKEN_TTL_MINUTES';

    /**
     * Tipo de dato esperado para esta configuración.
     */
    public function type(): ConfigurationType
    {
        return match ($this) {
            self::CUT_DAY_OF_MONTH,
            self::PAYMENT_DAYS_AFTER_CUT,
            self::POINTS_MULTIPLIER,
            self::MODIFICATION_TOKEN_TTL_MINUTES => ConfigurationType::INTEGER,

            self::CREDIT_TOLERANCE_AMOUNT,
            self::LATE_FEE_AMOUNT,
            self::POINTS_DIVISOR_AMOUNT,
            self::POINT_VALUE_AMOUNT => ConfigurationType::MONEY,

            self::LATE_POINTS_REDUCTION_RATE => ConfigurationType::PERCENTAGE,

            self::CUT_TIME,
            self::PAYMENT_DEADLINE_TIME,
            self::BANK_UPLOAD_DEADLINE_TIME,
            self::POST_DUE_EVALUATION_TIME => ConfigurationType::TIME,

            self::BUSINESS_TIMEZONE => ConfigurationType::TIMEZONE,

            self::EARLY_PAYMENT_PERIOD => ConfigurationType::TYPED_OBJECT,

            self::PAYMENT_BEHAVIOR_POINTS_POLICY => ConfigurationType::TYPED_OBJECT,
        };
    }

    /**
     * Indica si la configuración puede modificarse desde un formulario genérico.
     *
     * Las reglas operativas vigentes (horarios, zona) no se cambian desde
     * un formulario genérico mientras no exista una decisión funcional.
     */
    public function isAdministrable(): bool
    {
        return match ($this) {
            self::BUSINESS_TIMEZONE,
            self::CUT_TIME,
            self::PAYMENT_DEADLINE_TIME,
            self::BANK_UPLOAD_DEADLINE_TIME,
            self::POST_DUE_EVALUATION_TIME => false,
            default => true,
        };
    }

    /**
     * Indica si esta configuración tiene un valor inicial confirmado
     * que el seeder debe precargar.
     */
    public function hasInitialValue(): bool
    {
        return match ($this) {
            self::EARLY_PAYMENT_PERIOD => false,
            default => true,
        };
    }

    /**
     * Devuelve el valor inicial serializado como cadena para persistencia.
     *
     * @throws \LogicException Si la clave no tiene valor inicial.
     */
    public function initialValue(): string
    {
        return match ($this) {
            self::CUT_DAY_OF_MONTH => '25',
            self::PAYMENT_DAYS_AFTER_CUT => '20',
            self::BUSINESS_TIMEZONE => 'America/Monterrey',
            self::CUT_TIME => '00:05:00',
            self::PAYMENT_DEADLINE_TIME => '23:59:59',
            self::BANK_UPLOAD_DEADLINE_TIME => '08:00:00',
            self::POST_DUE_EVALUATION_TIME => '08:30:00',
            self::CREDIT_TOLERANCE_AMOUNT => '500.0000',
            self::LATE_FEE_AMOUNT => '300.0000',
            self::POINTS_DIVISOR_AMOUNT => '1200.0000',
            self::POINTS_MULTIPLIER => '3',
            self::POINT_VALUE_AMOUNT => '2.0000',
            self::LATE_POINTS_REDUCTION_RATE => '0.2000',
            self::MODIFICATION_TOKEN_TTL_MINUTES => '5',
            self::PAYMENT_BEHAVIOR_POINTS_POLICY => json_encode([
                ['behavior' => 'EARLY_PAYMENT', 'generates_points' => true, 'reduces_points' => false],
                ['behavior' => 'ON_TIME_PAYMENT', 'generates_points' => false, 'reduces_points' => false],
                ['behavior' => 'LATE_PAYMENT', 'generates_points' => false, 'reduces_points' => true],
                ['behavior' => 'PARTIAL_PAYMENT', 'generates_points' => false, 'reduces_points' => false],
                ['behavior' => 'NO_PAYMENT', 'generates_points' => false, 'reduces_points' => false],
            ], JSON_THROW_ON_ERROR),
            self::EARLY_PAYMENT_PERIOD => throw new \LogicException(
                "La configuración {$this->value} no tiene valor inicial precargado."
            ),
        };
    }
}
