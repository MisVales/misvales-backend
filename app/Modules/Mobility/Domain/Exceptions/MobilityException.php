<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\Exceptions;

use RuntimeException;

final class MobilityException extends RuntimeException
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 409,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->fields;
    }

    public static function scopeDenied(): self
    {
        return new self('MOBILITY_SCOPE_DENIED', 'La cuenta no tiene alcance para ejecutar esta operación.', 403);
    }

    public static function notAssigned(): self
    {
        return new self('CLIENT_NOT_ASSIGNED_TO_ORIGIN', 'El cliente no pertenece al origen esperado.');
    }

    public static function balanceNotZero(): self
    {
        return new self('CLIENT_TRANSFER_BALANCE_NOT_ZERO', 'El saldo total exigible y el saldo vencido deben ser cero.', 422);
    }

    public static function activeMobility(): self
    {
        return new self('CLIENT_MOBILITY_ALREADY_ACTIVE', 'Ya existe un proceso de movilidad activo para el cliente.');
    }

    public static function sameRecipient(): self
    {
        return new self('TRANSFER_RECIPIENT_EQUALS_ORIGIN', 'La distribuidora destino debe ser diferente del origen.', 422);
    }

    public static function invalidRecipient(): self
    {
        return new self('TRANSFER_RECIPIENT_INVALID', 'La distribuidora receptora no es válida.', 422);
    }

    public static function invalidState(): self
    {
        return new self('TRANSFER_STATE_INVALID', 'La transición no procede desde el estado actual.');
    }

    public static function assignmentConflict(): self
    {
        return new self('CLIENT_ASSIGNMENT_CONFLICT', 'La asignación cambió durante el proceso.');
    }

    /** @param array<string, mixed> $fields */
    public static function invalidItem(array $fields = []): self
    {
        return new self('REASSIGNMENT_ITEM_INVALID', 'Uno o más elementos no cumplen las reglas de reasignación.', 422, $fields);
    }

    public static function partialCompletion(): self
    {
        return new self('REASSIGNMENT_PARTIAL_COMPLETION_FORBIDDEN', 'El lote no puede completarse parcialmente.');
    }

    public static function branchChangeActive(): self
    {
        return new self('BRANCH_CHANGE_ALREADY_ACTIVE', 'Ya existe un cambio de sucursal activo para la distribuidora.');
    }

    public static function clientsPending(): self
    {
        return new self('BRANCH_CHANGE_CLIENTS_PENDING', 'Aún existen clientes asignados a la distribuidora.');
    }

    public static function coordinatorRequired(): self
    {
        return new self('BRANCH_CHANGE_COORDINATOR_REQUIRED', 'Falta un coordinador destino válido.');
    }

    public static function originChanged(): self
    {
        return new self('BRANCH_CHANGE_ORIGIN_CHANGED', 'La sucursal vigente ya no coincide con el origen.');
    }

    public static function coordinatorBatchActive(): self
    {
        return new self('COORDINATOR_REASSIGNMENT_ALREADY_ACTIVE', 'Ya existe un lote activo para el coordinador.');
    }

    public static function coverageIncomplete(): self
    {
        return new self('COORDINATOR_COVERAGE_INCOMPLETE', 'No todas las distribuidoras tienen coordinador destino.');
    }

    public static function invalidCoordinator(): self
    {
        return new self('DESTINATION_COORDINATOR_INVALID', 'El coordinador destino no es válido.', 422);
    }

    public static function versionConflict(): self
    {
        return new self('RESOURCE_VERSION_CONFLICT', 'El recurso cambió desde la consulta.');
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 'La clave de idempotencia ya fue utilizada con otro contenido.');
    }

    public static function reauthenticationRequired(): self
    {
        return new self('REAUTHENTICATION_REQUIRED', 'La acción requiere reautenticación vigente.', 403);
    }

    public static function dependencyUnavailable(string $dependency): self
    {
        return new self('MOBILITY_DEPENDENCY_UNAVAILABLE', "La integración requerida {$dependency} no está disponible.", 503);
    }

    public static function cancellationUndefined(): self
    {
        return new self('MOBILITY_CANCELLATION_NOT_SPECIFIED', 'La autoridad y transición de cancelación aún no están definidas.');
    }
}
