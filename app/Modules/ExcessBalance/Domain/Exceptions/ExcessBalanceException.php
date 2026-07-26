<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Exceptions;

use RuntimeException;

final class ExcessBalanceException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    private function __construct(
        private readonly string $domainCode,
        string $message,
        private readonly int $status,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->domainCode;
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

    public static function notFound(): self
    {
        return new self('EXCESS_BALANCE_NOT_FOUND', 'El recurso no existe dentro del alcance visible.', 404);
    }

    public static function amountInvalid(): self
    {
        return new self('EXCESS_AMOUNT_INVALID', 'El importe del excedente no es válido.', 422);
    }

    public static function alreadyRegistered(): self
    {
        return new self('EXCESS_ALREADY_REGISTERED', 'La aplicación ya originó un excedente.', 409);
    }

    public static function invariantViolated(): self
    {
        return new self('EXCESS_INVARIANT_VIOLATED', 'El saldo no conserva su ecuación financiera.', 409);
    }

    public static function stateConflict(): self
    {
        return new self('EXCESS_STATE_CONFLICT', 'El excedente se encuentra en un estado incompatible.', 409);
    }

    public static function refundStateConflict(): self
    {
        return new self('REFUND_REQUEST_STATE_CONFLICT', 'La solicitud se encuentra en un estado incompatible.', 409);
    }

    public static function authorizationDenied(): self
    {
        return new self('AUTH_SCOPE_DENIED', 'La cuenta no tiene autoridad para ejecutar la acción.', 403);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_CONFLICT', 'La clave de idempotencia fue reutilizada con otros datos.', 409);
    }

    public static function insufficientAvailable(): self
    {
        return new self('INSUFFICIENT_AVAILABLE_EXCESS', 'No existe saldo a favor suficiente.', 409);
    }

    public static function insufficientReserved(): self
    {
        return new self('INSUFFICIENT_RESERVED_EXCESS', 'La reserva no cubre la devolución autorizada.', 409);
    }

    public static function relationNotEligible(): self
    {
        return new self('RELATION_NOT_ELIGIBLE_FOR_CREDIT_BALANCE', 'La relación no puede recibir saldo a favor.', 409);
    }

    public static function relationNotSubsequent(): self
    {
        return new self('RELATION_NOT_SUBSEQUENT', 'La relación receptora no es posterior a la relación de origen.', 409);
    }

    public static function pendingDefinition(string $message): self
    {
        return new self('PENDING_BUSINESS_DEFINITION', $message, 503);
    }

    public static function integrationUnavailable(): self
    {
        return new self(
            'CREDIT_BALANCE_APPLICATION_CONTRACT_UNAVAILABLE',
            'M10 y M11 todavía no publican el contrato productivo de aplicación de saldo a favor.',
            503,
        );
    }

    public static function refundExecutionUndefined(): self
    {
        return new self(
            'REFUND_EXECUTION_CONTRACT_UNDEFINED',
            'Los métodos y campos de ejecución de devoluciones todavía no están definidos.',
            503,
        );
    }

    public static function evidenceContractUnavailable(): self
    {
        return new self(
            'REFUND_EVIDENCE_CONTRACT_UNAVAILABLE',
            'La política productiva de evidencias privadas todavía no está definida.',
            503,
        );
    }
}
