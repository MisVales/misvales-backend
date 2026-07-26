<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

/** Error público estable de M11 sin detalles técnicos o bancarios sensibles. */
final class PaymentDomainException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    private function __construct(
        private readonly string $paymentCode,
        string $message,
        private readonly int $status,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->paymentCode;
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

    public static function invalidMoney(): self
    {
        return new self('PAYMENT_AMOUNT_INVALID', 'El importe no es un decimal monetario válido.', 422);
    }

    /** @param array<string, list<string>> $fields */
    public static function invalidBankRow(array $fields = []): self
    {
        return new self('BANK_ROW_INVALID', 'La fila bancaria contiene valores inválidos.', 422, $fields);
    }

    public static function invalidAllocation(): self
    {
        return new self('PAYMENT_ALLOCATION_INVALID', 'El desglose no equivale al importe aplicado.', 409);
    }

    public static function financialInconsistent(): self
    {
        return new self('RELATION_FINANCIAL_INCONSISTENT', 'La relación conserva una inconsistencia financiera.', 409);
    }

    public static function fileContractUnavailable(): self
    {
        return new self('BANK_FILE_CONTRACT_UNDEFINED', 'El formato bancario productivo todavía no ha sido definido.', 503);
    }

    public static function relationContractUnavailable(): self
    {
        return new self('RELATION_PAYMENT_CONTRACT_UNAVAILABLE', 'El contrato autoritativo de relaciones todavía no está disponible.', 503);
    }

    public static function mediaContractUnavailable(): self
    {
        return new self('PRIVATE_MEDIA_CONTRACT_UNAVAILABLE', 'El contrato privado de archivos y evidencias todavía no está disponible.', 503);
    }

    public static function folioScopeUnavailable(): self
    {
        return new self('BANK_FOLIO_SCOPE_UNDEFINED', 'El ámbito de unicidad del folio bancario todavía no ha sido definido.', 503);
    }

    public static function refundContractUnavailable(): self
    {
        return new self('REFUND_METHOD_CONTRACT_UNDEFINED', 'Los métodos y campos de devolución todavía no han sido definidos.', 503);
    }

    public static function temporalContractUnavailable(): self
    {
        return new self('PAYMENT_TEMPORAL_CONTRACT_UNDEFINED', 'La fecha efectiva requerida no está cubierta por el contrato temporal.', 503);
    }

    public static function authorizationContractUnavailable(): self
    {
        return new self('PAYMENT_AUTHORIZATION_CONTRACT_UNAVAILABLE', 'El contrato de autorización crítica todavía no está disponible.', 503);
    }

    public static function configurationContractUnavailable(): self
    {
        return new self('PAYMENT_CONFIGURATION_CONTRACT_UNAVAILABLE', 'La configuración versionada de pagos todavía no está disponible.', 503);
    }

    public static function bankCoverageContractUnavailable(): self
    {
        return new self('BANK_COVERAGE_CONTRACT_UNDEFINED', 'La regla de cobertura bancaria todavía no ha sido definida.', 503);
    }

    public static function authorizationDenied(): self
    {
        return new self('AUTH_SCOPE_DENIED', 'La cuenta no tiene autoridad para ejecutar la acción.', 403);
    }

    public static function notFound(): self
    {
        return new self('PAYMENT_RESOURCE_NOT_FOUND', 'El recurso no existe dentro del alcance visible.', 404);
    }

    public static function versionConflict(): self
    {
        return new self('RESOURCE_VERSION_CONFLICT', 'El registro cambió desde que fue consultado.', 409);
    }

    public static function invalidImportState(): self
    {
        return new self('BANK_IMPORT_NOT_RETRYABLE', 'La importación no se encuentra en un estado reintentable.', 409);
    }

    public static function excessUnavailable(): self
    {
        return new self('EXCESS_NOT_AVAILABLE', 'El excedente no conserva importe disponible.', 409);
    }

    public static function excessDecisionTaken(): self
    {
        return new self('EXCESS_DECISION_ALREADY_TAKEN', 'El excedente ya tiene un destino incompatible.', 409);
    }

    public static function excessInvariantViolation(): self
    {
        return new self('EXCESS_LEDGER_INCONSISTENT', 'El libro del excedente no conserva su invariante.', 409);
    }

    public static function idempotencyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 'La clave de idempotencia se reutilizó con otro contenido.', 409);
    }
}
