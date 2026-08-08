<?php

namespace Tests\Unit\Modules\Organization\Domain\Assignments\ValueObjects;

use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssignmentStatusTest extends TestCase
{
    public function test_it_accepts_only_controlled_statuses(): void
    {
        self::assertSame(AssignmentStatus::ACTIVE, AssignmentStatus::fromString(' active '));
        self::assertSame(AssignmentStatus::ENDED, AssignmentStatus::fromString('ENDED'));
        self::assertSame(AssignmentStatus::REVOKED, AssignmentStatus::fromString('revoked'));
    }

    public function test_it_rejects_an_unknown_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssignmentStatus::fromString('DELETED');
    }
}
