<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class RiskDelinquencyException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly array $fields = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, list<string>> */
    public function fields(): array
    {
        return $this->fields;
    }

    public static function sourceUnavailable(): self
    {
        return new self('RISK_SOURCE_NOT_READY', 'La evaluación financiera definitiva todavía no está disponible.', 409);
    }

    public static function sourceInconsistent(): self
    {
        return new self('RISK_SOURCE_INCONSISTENT', 'La evaluación financiera recibida es incoherente.', 422);
    }

    public static function scopeDenied(bool $hide = true): self
    {
        return new self('DELINQUENCY_SCOPE_DENIED', 'El recurso no existe dentro del alcance autorizado.', $hide ? 404 : 403);
    }

    public static function stateConflict(string $code = 'DELINQUENCY_STATE_CONFLICT'): self
    {
        return new self($code, 'La operación no es compatible con el estado vigente.', 409);
    }
}
