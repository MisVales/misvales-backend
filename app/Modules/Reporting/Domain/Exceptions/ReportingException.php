<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use App\Modules\Reporting\Domain\Enums\ReportCode;
use RuntimeException;

final class ReportingException extends RuntimeException
{
    /** @param array<string, mixed> $fields */
    public function __construct(
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

    public static function notFound(): self
    {
        return new self('REPORT_NOT_FOUND', 'El reporte solicitado no existe o no está publicado.', 404);
    }

    public static function accessDenied(): self
    {
        return new self('REPORT_ACCESS_DENIED', 'La cuenta no tiene permiso para consultar este reporte.', 403);
    }

    public static function scopeDenied(): self
    {
        return new self('REPORT_SCOPE_DENIED', 'El filtro o recurso no pertenece al alcance autorizado.', 403);
    }

    public static function unsupportedFilter(string $filter): self
    {
        return new self('REPORT_FILTER_UNSUPPORTED', 'El reporte no admite uno o más filtros.', 422, ['filter' => [$filter]]);
    }

    public static function invalidFilter(string $filter): self
    {
        return new self('REPORT_FILTER_INVALID', 'Uno o más filtros no son válidos.', 422, ['filter' => [$filter]]);
    }

    public static function invalidDateRange(): self
    {
        return new self('REPORT_DATE_RANGE_INVALID', 'El intervalo de fechas no es válido o excede el límite permitido.', 422);
    }

    public static function unsupportedSort(): self
    {
        return new self('REPORT_SORT_UNSUPPORTED', 'El ordenamiento solicitado no está permitido.', 422);
    }

    public static function synchronousLimitExceeded(): self
    {
        return new self('REPORT_SYNC_LIMIT_EXCEEDED', 'El reporte debe solicitarse mediante una corrida asíncrona.', 422);
    }

    public static function runNotFound(): self
    {
        return new self('REPORT_RUN_NOT_FOUND', 'La corrida solicitada no existe o no es visible.', 404);
    }

    public static function runNotReady(): self
    {
        return new self('REPORT_RUN_NOT_READY', 'El resultado de la corrida aún no está disponible.', 409);
    }

    public static function runFailed(): self
    {
        return new self('REPORT_RUN_FAILED', 'La corrida terminó con un error seguro.', 409);
    }

    public static function runExpired(): self
    {
        return new self('REPORT_RUN_EXPIRED', 'El resultado temporal de la corrida expiró.', 410);
    }

    public static function invalidRunState(): self
    {
        return new self('REPORT_RUN_STATE_INVALID', 'La transición técnica de la corrida no es válida.', 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 'La clave de idempotencia fue utilizada con parámetros diferentes.', 409);
    }

    public static function dependencyUnavailable(ReportCode|string $report): self
    {
        $code = $report instanceof ReportCode ? $report->value : $report;

        return new self(
            'REPORT_DEPENDENCY_UNAVAILABLE',
            "La fuente autoritativa requerida por {$code} todavía no está integrada.",
            503,
        );
    }

    public static function dataMinimizationFailed(): self
    {
        return new self(
            'REPORT_DATA_MINIMIZATION_FAILED',
            'La fuente del reporte intentó exponer datos fuera del contrato autorizado.',
            503,
        );
    }
}
