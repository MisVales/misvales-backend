<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio del módulo de configuraciones (C13).
 *
 * Cada error esperado se convierte en un código estable que la API
 * devuelve al consumidor sin exponer detalles internos.
 */
final class ConfigurationException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $fields;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $statusCode = 409,
        array $fields = [],
    ) {
        parent::__construct($message);
        $this->fields = $fields;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->fields;
    }

    // ── Configuraciones ──

    public static function notFound(string $detail = ''): self
    {
        return new self(
            $detail ?: 'La configuración no existe o no es visible.',
            'CONFIGURATION_NOT_FOUND',
            404,
        );
    }

    public static function valueMissing(string $key, string $date): self
    {
        return new self(
            "No existe una versión aplicable de «{$key}» para la fecha solicitada.",
            'CONFIGURATION_VALUE_MISSING',
            404,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function valueInvalid(string $detail = '', array $fields = []): self
    {
        return new self(
            $detail ?: 'El valor no cumple el tipo o validador.',
            'CONFIGURATION_VALUE_INVALID',
            422,
            $fields,
        );
    }

    public static function immutable(): self
    {
        return new self(
            'No se puede editar una versión publicada.',
            'CONFIGURATION_IMMUTABLE',
            409,
        );
    }

    public static function versionOverlap(string $entity = 'configuración'): self
    {
        return new self(
            "La vigencia se superpone con otra versión de la misma {$entity}.",
            'CONFIGURATION_VERSION_OVERLAP',
            409,
        );
    }

    public static function retroactivePublicationForbidden(): self
    {
        return new self(
            'No se permite publicar con efecto retroactivo.',
            'CONFIGURATION_RETROACTIVE_PUBLICATION_FORBIDDEN',
            422,
        );
    }

    public static function versionConflict(): self
    {
        return new self(
            'El borrador fue modificado por otra solicitud.',
            'CONFIGURATION_VERSION_CONFLICT',
            409,
        );
    }

    public static function scopeInvalid(): self
    {
        return new self(
            'No se permite crear configuraciones por sucursal.',
            'CONFIGURATION_SCOPE_INVALID',
            422,
        );
    }

    public static function changeNotAllowed(string $key): self
    {
        return new self(
            "La configuración «{$key}» no está abierta a modificación mediante formulario.",
            'CONFIGURATION_CHANGE_NOT_ALLOWED',
            403,
        );
    }

    // ── Categorías ──

    public static function categoryNotFound(): self
    {
        return new self(
            'La categoría no existe dentro del alcance visible.',
            'CATEGORY_NOT_FOUND',
            404,
        );
    }

    public static function categoryNotPublished(): self
    {
        return new self(
            'La categoría todavía no puede utilizarse.',
            'CATEGORY_NOT_PUBLISHED',
            409,
        );
    }

    public static function categoryInactive(): self
    {
        return new self(
            'La categoría no admite operaciones nuevas.',
            'CATEGORY_INACTIVE',
            409,
        );
    }

    public static function categoryVersionOverlap(): self
    {
        return new self(
            'Existen vigencias incompatibles para esta categoría.',
            'CATEGORY_VERSION_OVERLAP',
            409,
        );
    }

    // ── Productos ──

    public static function productNotFound(): self
    {
        return new self(
            'El producto no existe dentro del alcance visible.',
            'PRODUCT_NOT_FOUND',
            404,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function productIncomplete(array $fields = []): self
    {
        return new self(
            'El producto no contiene todos los parámetros requeridos para publicarse.',
            'PRODUCT_INCOMPLETE',
            422,
            $fields,
        );
    }

    public static function productAmountNotMultipleOf100(): self
    {
        return new self(
            'El importe del producto debe ser múltiplo exacto de 100.',
            'PRODUCT_AMOUNT_NOT_MULTIPLE_OF_100',
            422,
        );
    }

    public static function productNotPublished(): self
    {
        return new self(
            'El producto todavía no puede utilizarse.',
            'PRODUCT_NOT_PUBLISHED',
            409,
        );
    }

    public static function productInactive(): self
    {
        return new self(
            'El producto no admite vales nuevos.',
            'PRODUCT_INACTIVE',
            409,
        );
    }

    public static function productVersionOverlap(): self
    {
        return new self(
            'Existen vigencias incompatibles para este producto.',
            'PRODUCT_VERSION_OVERLAP',
            409,
        );
    }

    // ── Periodos de canje ──

    public static function redemptionPeriodNotActive(): self
    {
        return new self(
            'No existe un periodo de canje publicado y vigente.',
            'REDEMPTION_PERIOD_NOT_ACTIVE',
            409,
        );
    }

    public static function redemptionPeriodInvalid(string $detail = ''): self
    {
        return new self(
            $detail ?: 'El intervalo del periodo de canje no es válido.',
            'REDEMPTION_PERIOD_INVALID',
            422,
        );
    }

    // ── Autorización ──

    public static function permissionDenied(): self
    {
        return new self(
            'No tiene autoridad para realizar esta operación.',
            'CONFIGURATION_PERMISSION_DENIED',
            403,
        );
    }

    public static function reauthenticationRequired(): self
    {
        return new self(
            'Se requiere reautenticación válida para esta acción.',
            'REAUTHENTICATION_REQUIRED',
            403,
        );
    }

    public static function administratorReadOnly(): self
    {
        return new self(
            'El administrador no puede realizar escrituras.',
            'ADMINISTRATOR_READ_ONLY',
            403,
        );
    }
}
