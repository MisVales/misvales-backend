<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;
use Throwable;

/** Excepción pública estable del módulo de caja. */
final class VoucherDomainException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        private readonly string $voucherCode,
        string $message,
        private readonly int $status,
        private readonly array $fields = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->voucherCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function fields(): array
    {
        return $this->fields;
    }

    public static function scopeDenied(): self
    {
        return new self('AUTH_SCOPE_DENIED', 'La cuenta no tiene autoridad para ejecutar la acción.', 403);
    }

    public static function notFound(): self
    {
        return new self('VOUCHER_NOT_FOUND', 'El vale no existe o no está disponible para la cuenta.', 404);
    }

    public static function branchMismatch(): self
    {
        return new self('VOUCHER_BRANCH_MISMATCH', 'El vale no pertenece a la sucursal operante.', 404);
    }

    public static function invalidTransition(): self
    {
        return new self('VOUCHER_INVALID_TRANSITION', 'El estado actual no permite ejecutar la operación.', 409);
    }

    public static function notAvailableAtCounter(): self
    {
        return new self('VOUCHER_NOT_AVAILABLE_AT_COUNTER', 'El vale no está disponible para apertura en caja.', 409);
    }

    public static function correctionPending(): self
    {
        return new self('VOUCHER_CORRECTION_PENDING', 'El vale conserva una corrección pendiente.', 409);
    }

    public static function modificationActive(): self
    {
        return new self('MODIFICATION_REQUEST_ACTIVE', 'Ya existe una solicitud activa para el vale.', 409);
    }

    public static function fieldNotAllowed(string $field = 'fields'): self
    {
        return new self(
            'MODIFICATION_FIELD_NOT_ALLOWED',
            'La solicitud contiene campos que M06 no publica como corregibles.',
            422,
            [$field => ['Los campos deben coincidir exactamente con el registro de M06.']],
        );
    }

    public static function requestNotPending(): self
    {
        return new self('MODIFICATION_REQUEST_NOT_PENDING', 'La solicitud ya recibió una decisión.', 409);
    }

    public static function tokenInvalid(): self
    {
        return new self('MODIFICATION_TOKEN_INVALID', 'El token no coincide con la autorización solicitada.', 409);
    }

    public static function tokenExpired(): self
    {
        return new self('MODIFICATION_TOKEN_EXPIRED', 'El token de modificación terminó su vigencia.', 409);
    }

    public static function tokenUsed(): self
    {
        return new self('MODIFICATION_TOKEN_USED', 'El token de modificación ya fue consumido.', 409);
    }

    public static function versionConflict(): self
    {
        return new self('RESOURCE_VERSION_CONFLICT', 'El recurso cambió desde la última consulta.', 409);
    }

    public static function transactionRequired(): self
    {
        return new self('TRANSACTION_NUMBER_REQUIRED', 'Se requiere un número de transacción válido.', 422);
    }

    public static function transactionDuplicate(?Throwable $previous = null): self
    {
        return new self(
            'TRANSACTION_NUMBER_DUPLICATE',
            'El número de transacción ya corresponde a otro vale.',
            409,
            previous: $previous,
        );
    }

    public static function alreadyFulfilled(): self
    {
        return new self('VOUCHER_ALREADY_FULFILLED', 'El vale ya fue feriado.', 409);
    }

    public static function idempotencyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 'La clave de idempotencia se reutilizó con otro contenido.', 409);
    }

    public static function dependencyUnavailable(string $dependency): self
    {
        return new self(
            'VOUCHER_DEPENDENCY_UNAVAILABLE',
            'No fue posible resolver una dependencia propietaria requerida.',
            503,
            ['dependency' => [$dependency]],
        );
    }
}
