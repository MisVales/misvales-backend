<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Exceptions;

use RuntimeException;
use Throwable;

/** Excepción pública de M06 con código estable y respuesta HTTP segura. */
final class ClientDomainException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        private readonly string $clientCode,
        string $message,
        private readonly int $status,
        private readonly array $fields = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->clientCode;
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

    public static function authorizationDenied(): self
    {
        return new self('AUTH_SCOPE_DENIED', 'La cuenta no tiene autoridad para ejecutar la acción.', 403);
    }

    public static function integrationUnavailable(string $integration): self
    {
        return new self(
            'CLIENT_DEPENDENCY_UNAVAILABLE',
            'No fue posible resolver una dependencia requerida para la operación.',
            503,
            ['dependency' => [$integration]],
        );
    }

    public static function curpInvalid(): self
    {
        return new self('CLIENT_CURP_INVALID', 'La CURP proporcionada no tiene una forma aceptable.', 422, [
            'curp' => ['La CURP debe contener exactamente 18 caracteres alfanuméricos.'],
        ]);
    }

    public static function curpExists(): self
    {
        return new self('CLIENT_CURP_EXISTS', 'No fue posible registrar al cliente con los datos proporcionados.', 409, [
            'curp' => ['La CURP ya se encuentra registrada.'],
        ]);
    }

    public static function addressExists(): self
    {
        return new self('CLIENT_ADDRESS_EXISTS', 'No fue posible registrar al cliente con los datos proporcionados.', 409, [
            'address' => ['El domicilio ya se encuentra registrado.'],
        ]);
    }

    public static function addressInvalid(): self
    {
        return new self('CLIENT_ADDRESS_INVALID', 'El domicilio está incompleto o contiene valores inválidos.', 422, [
            'address' => ['Todos los componentes obligatorios deben conservar un valor válido.'],
        ]);
    }

    public static function dataIncomplete(string $field): self
    {
        return new self('CLIENT_DATA_INCOMPLETE', 'Los datos del cliente están incompletos.', 422, [
            $field => ['El valor no conserva contenido válido después de sanearlo.'],
        ]);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_CONFLICT', 'La clave de idempotencia ya fue utilizada con otro contenido.', 409);
    }

    public static function notFoundOrOutOfScope(): self
    {
        return new self('CLIENT_NOT_FOUND_OR_OUT_OF_SCOPE', 'El cliente no existe o no está disponible para la cuenta.', 404);
    }

    public static function notAssigned(): self
    {
        return new self('CLIENT_NOT_ASSIGNED_TO_DISTRIBUTOR', 'El cliente no pertenece a la distribuidora operante.', 409);
    }

    public static function versionConflict(string $code = 'RESOURCE_VERSION_CONFLICT'): self
    {
        return new self($code, 'El recurso cambió desde la última consulta.', 409);
    }

    public static function portfolioInvalid(string $message): self
    {
        return new self('PORTFOLIO_ENTRY_INVALID', $message, 422);
    }

    public static function portfolioDisabled(): self
    {
        return new self('PORTFOLIO_TRACKING_DISABLED', 'El seguimiento informativo de cartera no está habilitado.', 409);
    }

    public static function changeAuthorizationInvalid(): self
    {
        return new self('CLIENT_CHANGE_AUTH_INVALID', 'La autorización no coincide o no está vigente.', 409);
    }

    public static function transferBalanceNotZero(): self
    {
        return new self(
            'CLIENT_TRANSFER_BALANCE_NOT_ZERO',
            'El saldo informativo registrado debe estar completamente en cero para continuar.',
            409,
        );
    }

    public static function sensitiveDataUnavailable(?Throwable $previous = null): self
    {
        return new self(
            'CLIENT_SENSITIVE_DATA_UNAVAILABLE',
            'No fue posible recuperar el dato protegido de forma segura.',
            500,
            previous: $previous,
        );
    }
}
