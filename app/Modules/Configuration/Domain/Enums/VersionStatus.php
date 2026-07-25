<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Estados cerrados del ciclo de vida de una versión.
 *
 * DRAFT    → Puede editarse; no es visible para operaciones.
 * PUBLISHED → Inmutable; visible cuando su vigencia lo permite.
 * INACTIVE  → No se utiliza en operaciones nuevas; conserva historial.
 */
enum VersionStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case INACTIVE = 'INACTIVE';

    /**
     * Indica si la versión puede editarse.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Indica si la versión es inmutable.
     */
    public function isImmutable(): bool
    {
        return $this === self::PUBLISHED || $this === self::INACTIVE;
    }

    /**
     * Indica si la versión puede utilizarse en operaciones nuevas.
     */
    public function isOperational(): bool
    {
        return $this === self::PUBLISHED;
    }
}
