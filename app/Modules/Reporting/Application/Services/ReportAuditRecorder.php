<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Models\User;
use App\Modules\Reporting\Domain\Enums\ReportEventName;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportOutboxEvent;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportQueryEvent;
use Illuminate\Support\Str;

final class ReportAuditRecorder
{
    /** @param array<string, mixed> $filters */
    public function allowed(
        User $actor,
        ReportDefinition $definition,
        ReportScope $scope,
        array $filters,
        int $rows,
        string $correlationId,
        ?string $runId = null,
    ): void {
        $this->queryEvent($actor, $definition, $scope, $filters, 'ALLOWED', $correlationId, $rows, $runId);
        if ($definition->sensitiveFields !== []) {
            $this->outbox(
                ReportEventName::SENSITIVE_ACCESSED,
                $runId,
                $definition,
                $actor,
                $scope,
                $correlationId,
                ['filters_hash' => $this->filterHash($filters), 'row_count' => $rows],
            );
        }
    }

    /** @param array<string, mixed> $filters */
    public function denied(
        ?User $actor,
        ?ReportDefinition $definition,
        ?ReportScope $scope,
        array $filters,
        string $correlationId,
        string $errorCode,
    ): void {
        ReportQueryEvent::query()->create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role_code,
            'report_code' => $definition?->code->value,
            'scope_type' => $scope?->type->value,
            'filters_hash' => $filters === [] ? null : $this->filterHash($filters),
            'outcome' => 'DENIED',
            'rows_returned' => 0,
            'session_id' => null,
            'correlation_id' => $correlationId,
            'error_code' => $errorCode,
            'occurred_at' => now('UTC'),
        ]);
        $this->outbox(ReportEventName::ACCESS_DENIED, null, $definition, $actor, $scope, $correlationId, [
            'error_code' => $errorCode,
            'filters_hash' => $filters === [] ? null : $this->filterHash($filters),
        ]);
    }

    /**
     * Must be called inside the transaction that changes a run.
     *
     * @param  array<string, mixed>  $payload
     */
    public function outbox(
        ReportEventName $name,
        ?string $aggregateId,
        ?ReportDefinition $definition,
        ?User $actor,
        ?ReportScope $scope,
        string $correlationId,
        array $payload,
    ): void {
        ReportOutboxEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_name' => $name->value,
            'aggregate_id' => $aggregateId,
            'report_code' => $definition?->code->value,
            'actor_id' => $actor?->id,
            'scope_type' => $scope?->type->value,
            'correlation_id' => $correlationId,
            'payload' => $payload,
            'occurred_at' => now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function queryEvent(
        User $actor,
        ReportDefinition $definition,
        ReportScope $scope,
        array $filters,
        string $outcome,
        string $correlationId,
        int $rows,
        ?string $runId,
    ): void {
        ReportQueryEvent::query()->create([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role_code,
            'report_code' => $definition->code->value,
            'scope_type' => $scope->type->value,
            'filters_hash' => $this->filterHash($filters),
            'outcome' => $outcome,
            'rows_returned' => $rows,
            'session_id' => null,
            'run_id' => $runId,
            'correlation_id' => $correlationId,
            'occurred_at' => now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function filterHash(array $filters): string
    {
        return hash('sha256', (string) json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
