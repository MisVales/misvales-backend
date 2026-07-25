<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Tipos admitidos para valores de configuración (C04).
 *
 * Cada tipo determina el validador específico que se aplica
 * al crear o editar una versión de configuración.
 */
enum ConfigurationType: string
{
    case INTEGER = 'integer';
    case MONEY = 'money';
    case PERCENTAGE = 'percentage';
    case TIME = 'time';
    case TIMEZONE = 'timezone';
    case TYPED_OBJECT = 'typed_object';
}
