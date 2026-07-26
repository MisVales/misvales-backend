<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Contracts;

/** Snapshot versionado de la asignación y saldos que M06 conserva. */
final readonly class ClientAssignmentSnapshot
{
    public function __construct(
        public string $assignmentId,
        public string $clientId,
        public int $clientVersion,
        public string $distributorId,
        public int $branchId,
        public int $portfolioVersion,
        public string $totalDue,
        public string $overdue,
    ) {}

    public function hasZeroBalance(): bool
    {
        return bccomp($this->totalDue, '0.0000', 4) === 0
            && bccomp($this->overdue, '0.0000', 4) === 0;
    }
}
