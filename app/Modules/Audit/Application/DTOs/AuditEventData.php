<?php

namespace App\Modules\Audit\Application\DTOs;

/**
 * DTO (Data Transfer Object) inmutable que encapsula los datos requeridos
 * para registrar un evento auditable dentro del ecosistema MisVales.
 *
 * Esta clase restringe los campos para evitar la inserción de payloads completos e indiscriminados.
 */
class AuditEventData
{
    public function __construct(
        public readonly string $eventCode,
        public readonly string $category,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $action,
        public readonly string $result,
        public readonly int $eventVersion = 1,
        public readonly ?\DateTimeInterface $occurredAt = null,
        public readonly ?string $requesterUserId = null,
        public readonly ?string $authorizerUserId = null,
        public readonly ?string $executorUserId = null,
        public readonly ?string $processCode = null,
        public readonly ?string $roleSnapshot = null,
        public readonly ?string $branchId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgentSummary = null,
        public readonly ?string $subjectPublicNumber = null,
        public readonly ?array $changedFields = null,
        public readonly ?array $beforeData = null,
        public readonly ?array $afterData = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reasonText = null,
        public readonly ?string $ruleCode = null,
        public readonly ?int $ruleVersion = null,
        public readonly ?array $evidenceFileIds = null,
        public readonly ?string $requestId = null,
        public readonly ?string $traceId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
        public readonly ?string $idempotencyKeyHash = null,
        public readonly ?array $metadata = null
    ) {}
}
