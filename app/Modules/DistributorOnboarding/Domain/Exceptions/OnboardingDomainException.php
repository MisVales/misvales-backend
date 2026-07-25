<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Exceptions;

use RuntimeException;

/** Error funcional estable de M04 apto para transformarse en una respuesta pública. */
final class OnboardingDomainException extends RuntimeException
{
    /** @param array<string, mixed> $fields */
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
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

    public static function invalidState(): self
    {
        return new self('APPLICATION_STATE_INVALID', 'La solicitud no se encuentra en un estado compatible con la acción.', 409);
    }

    public static function applicationTerminal(): self
    {
        return new self('APPLICATION_TERMINAL', 'La solicitud terminó y no admite cambios.', 409);
    }

    public static function versionConflict(): self
    {
        return new self('APPLICATION_VERSION_CONFLICT', 'La solicitud cambió desde que fue consultada.', 409);
    }

    public static function invalidEmail(): self
    {
        return new self('APPLICATION_EMAIL_INVALID', 'El correo no tiene un formato válido.', 422, ['contact_email' => ['El correo no tiene un formato válido.']]);
    }

    public static function emailAlreadyUsed(): self
    {
        return new self('APPLICATION_EMAIL_ALREADY_USED', 'El correo ya pertenece a otra cuenta.', 409);
    }

    public static function reauthenticationRequired(): self
    {
        return new self('APPLICATION_REAUTHENTICATION_REQUIRED', 'La autorización final exige reautenticación vigente.', 401);
    }

    public static function invalidInitialCreditLine(): self
    {
        return new self('APPLICATION_INITIAL_CREDIT_LINE_INVALID', 'La línea inicial no es un decimal válido.', 422, ['initial_credit_line' => ['Use una cadena decimal con hasta cuatro decimales.']]);
    }

    public static function incomplete(): self
    {
        return new self('APPLICATION_INCOMPLETE', 'El expediente no satisface la matriz aprobada para avanzar.', 422);
    }

    public static function scopeDenied(): self
    {
        return new self('APPLICATION_NOT_FOUND', 'La solicitud no existe dentro del alcance visible.', 404);
    }

    public static function authorizationDenied(): self
    {
        return new self('AUTH_SCOPE_DENIED', 'La cuenta no tiene autoridad para ejecutar la acción.', 403);
    }

    public static function integrationUnavailable(string $code): self
    {
        return new self($code, 'La integración propietaria requerida todavía no está disponible.', 503);
    }

    public static function differencesPending(): self
    {
        return new self('APPLICATION_DIFFERENCES_PENDING', 'Existen diferencias corregibles sin resolver.', 409);
    }

    public static function correctionNotAllowed(): self
    {
        return new self('APPLICATION_CORRECTION_NOT_ALLOWED', 'El campo o el valor no admite esta corrección.', 422);
    }

    public static function verifierRequired(): self
    {
        return new self('APPLICATION_VERIFIER_REQUIRED', 'La acción requiere una asignación vigente de verificador.', 409);
    }

    public static function verifierAssignmentConflict(): self
    {
        return new self('APPLICATION_VERIFIER_ASSIGNMENT_CONFLICT', 'Ya existe una asignación vigente.', 409);
    }

    public static function visitAlreadyStarted(): self
    {
        return new self('APPLICATION_VISIT_ALREADY_STARTED', 'La visita de la asignación vigente ya fue iniciada.', 409);
    }

    public static function visitAlreadyCompleted(): self
    {
        return new self('APPLICATION_VISIT_ALREADY_COMPLETED', 'La visita ya tiene un resultado final.', 409);
    }

    public static function evaluationAlreadyRecorded(): self
    {
        return new self('APPLICATION_EVALUATION_ALREADY_RECORDED', 'La evaluación final del coordinador ya fue registrada.', 409);
    }

    public static function managerDecisionAlreadyRecorded(): self
    {
        return new self('APPLICATION_MANAGER_DECISION_ALREADY_RECORDED', 'La decisión gerencial final ya fue registrada.', 409);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 'La clave de idempotencia se reutilizó con otro contenido.', 409);
    }
}
