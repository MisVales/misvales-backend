<?php

namespace App\Modules\Access\Domain\Authorization;

/** Estado mínimo que M02 entrega para autorizar una asignación operativa. */
final readonly class AssignmentStatus
{
    public function __construct(
        public bool $active,
        public ?int $branchId,
    ) {}
}
