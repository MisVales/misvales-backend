<?php

namespace App\Modules\Distributor\Domain\Distributors;

use RuntimeException;

class DistributorDomainException extends RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function notFound(): self
    {
        return new self('DISTRIBUTOR_NOT_FOUND', 'La distribuidora no existe o no es visible para el alcance actual.');
    }

    public static function scopeDenied(): self
    {
        return new self('DISTRIBUTOR_SCOPE_DENIED', 'El actor no tiene permiso para operar este recurso.');
    }

    public static function alreadyProvisioned(): self
    {
        return new self('DISTRIBUTOR_ALREADY_PROVISIONED', 'La solicitud ya originó un perfil de distribuidora.');
    }

    public static function accountAlreadyLinked(): self
    {
        return new self('DISTRIBUTOR_ACCOUNT_ALREADY_LINKED', 'La cuenta ya está vinculada a otro perfil de distribuidora.');
    }

    public static function numberConflict(): self
    {
        return new self('DISTRIBUTOR_NUMBER_CONFLICT', 'El número de distribuidora generado ya existe.');
    }

    public static function activationInvalid(): self
    {
        return new self('DISTRIBUTOR_ACTIVATION_INVALID', 'Las condiciones de la solicitud no permiten la activación del perfil.');
    }

    public static function statusIncompatible(): self
    {
        return new self('DISTRIBUTOR_STATUS_INCOMPATIBLE', 'El estado base no permite realizar esta operación.');
    }

    public static function versionConflict(): self
    {
        return new self('DISTRIBUTOR_VERSION_CONFLICT', 'El perfil ha sido modificado por otra operación. Intente nuevamente.');
    }

    public static function categoryRequired(): self
    {
        return new self('DISTRIBUTOR_CATEGORY_REQUIRED', 'La distribuidora requiere una categoría vigente.');
    }

    public static function categoryAlreadyAssigned(): self
    {
        return new self('DISTRIBUTOR_CATEGORY_ALREADY_ASSIGNED', 'La distribuidora ya tiene asignada esa misma versión de categoría.');
    }

    public static function categoryAssignmentConflict(): self
    {
        return new self('DISTRIBUTOR_CATEGORY_ASSIGNMENT_CONFLICT', 'Existe otra asignación de categoría en curso.');
    }

    public static function categoryVersionNotAssignable(): self
    {
        return new self('CATEGORY_VERSION_NOT_ASSIGNABLE', 'La versión de categoría seleccionada no se encuentra publicada, activa y vigente.');
    }

    public static function coordinatorRequired(): self
    {
        return new self('DISTRIBUTOR_COORDINATOR_REQUIRED', 'La distribuidora debe tener un coordinador asignado.');
    }

    public static function operationBlocked(): self
    {
        return new self('DISTRIBUTOR_OPERATION_BLOCKED', 'Una restricción de otro módulo impide completar la operación.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 'La clave de idempotencia ya fue utilizada con datos diferentes.');
    }
}
