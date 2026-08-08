<?php

namespace App\Modules\Organization\Domain\Assignments;

use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentAlreadyClosed;
use App\Modules\Organization\Domain\Assignments\Exceptions\InvalidAssignmentPeriod;
use App\Modules\Organization\Domain\Assignments\Exceptions\InvalidOrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentStatus;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use DateTimeImmutable;

final class OrganizationAssignment
{
    private function __construct(
        private readonly AssignmentId $id,
        private readonly string $userId,
        private readonly string $roleId,
        private readonly ?BranchId $branchId,
        private readonly OrganizationScope $scope,
        private DateTimeImmutable $assignedAt,
        private AssignmentStatus $status,
        private readonly ?string $assignedByUserId,
        private ?string $assignmentReason = null,
        private ?DateTimeImmutable $revokedAt = null,
        private ?string $revokedByUserId = null,
        private ?string $revocationReason = null,
    ) {
        $this->assertScopeMatchesBranch();
        $this->assertLifecycleConsistency();
    }

    public static function create(
        AssignmentId $id,
        string $userId,
        string $roleId,
        ?BranchId $branchId,
        OrganizationScope $scope,
        DateTimeImmutable $assignedAt,
        ?string $assignedByUserId,
        ?string $assignmentReason = null,
    ): self {
        return new self(
            id: $id,
            userId: self::requiredId($userId, 'usuario'),
            roleId: self::requiredId($roleId, 'rol'),
            branchId: $branchId,
            scope: $scope,
            assignedAt: $assignedAt,
            status: AssignmentStatus::ACTIVE,
            assignedByUserId: self::requiredId($assignedByUserId, 'actor'),
            assignmentReason: self::normalizeReason($assignmentReason),
        );
    }

    public static function reconstitute(
        AssignmentId $id,
        string $userId,
        string $roleId,
        ?BranchId $branchId,
        OrganizationScope $scope,
        DateTimeImmutable $assignedAt,
        AssignmentStatus $status,
        string $assignedByUserId,
        ?string $assignmentReason,
        ?DateTimeImmutable $revokedAt,
        ?string $revokedByUserId,
        ?string $revocationReason,
    ): self {
        return new self(
            $id,
            self::requiredId($userId, 'usuario'),
            self::requiredId($roleId, 'rol'),
            $branchId,
            $scope,
            $assignedAt,
            $status,
            $assignedByUserId === null ? null : self::requiredId($assignedByUserId, 'actor'),
            self::normalizeReason($assignmentReason),
            $revokedAt,
            $revokedByUserId,
            $revocationReason,
        );
    }

    public function close(
        DateTimeImmutable $revokedAt,
        string $revokedByUserId,
        string $reason,
        AssignmentStatus $status = AssignmentStatus::ENDED,
    ): void {
        if (! $this->status->isActive()) {
            throw new AssignmentAlreadyClosed($this->id->value());
        }

        if ($revokedAt <= $this->assignedAt) {
            throw new InvalidAssignmentPeriod;
        }

        if ($status->isActive()) {
            throw new InvalidOrganizationAssignment('El cierre requiere un estado ENDED o REVOKED.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidOrganizationAssignment('El motivo de finalización es obligatorio.');
        }

        $this->status = $status;
        $this->revokedAt = $revokedAt;
        $this->revokedByUserId = self::requiredId($revokedByUserId, 'actor de cierre');
        $this->revocationReason = $reason;
    }

    public function updateDetails(DateTimeImmutable $assignedAt, ?string $assignmentReason): void
    {
        if (! $this->status->isActive()) {
            throw new AssignmentAlreadyClosed($this->id->value());
        }

        $this->assignedAt = $assignedAt;
        $this->assignmentReason = self::normalizeReason($assignmentReason);
    }

    public function id(): AssignmentId
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function roleId(): string
    {
        return $this->roleId;
    }

    public function branchId(): ?BranchId
    {
        return $this->branchId;
    }

    public function scope(): OrganizationScope
    {
        return $this->scope;
    }

    public function assignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function status(): AssignmentStatus
    {
        return $this->status;
    }

    public function assignedByUserId(): ?string
    {
        return $this->assignedByUserId;
    }

    public function assignmentReason(): ?string
    {
        return $this->assignmentReason;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedByUserId(): ?string
    {
        return $this->revokedByUserId;
    }

    public function revocationReason(): ?string
    {
        return $this->revocationReason;
    }

    private function assertScopeMatchesBranch(): void
    {
        if ($this->scope->requiresBranch() && $this->branchId === null) {
            throw new InvalidOrganizationAssignment('El alcance BRANCH requiere una sucursal.');
        }

        if (! $this->scope->requiresBranch() && $this->branchId !== null) {
            throw new InvalidOrganizationAssignment("El alcance {$this->scope->value} no admite una sucursal.");
        }
    }

    private function assertLifecycleConsistency(): void
    {
        if ($this->status->isActive() && ($this->revokedAt !== null || $this->revokedByUserId !== null)) {
            throw new InvalidOrganizationAssignment('Una asignación activa no puede contener datos de finalización.');
        }

        if (! $this->status->isActive() && ($this->revokedAt === null || $this->revokedByUserId === null)) {
            throw new InvalidOrganizationAssignment('Una asignación finalizada requiere fecha y actor de cierre.');
        }

        if ($this->revokedAt !== null && $this->revokedAt <= $this->assignedAt) {
            throw new InvalidAssignmentPeriod;
        }
    }

    private static function requiredId(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidOrganizationAssignment("El identificador de {$field} es obligatorio.");
        }

        return $value;
    }

    private static function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }
}
