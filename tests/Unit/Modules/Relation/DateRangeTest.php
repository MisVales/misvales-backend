<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Relation;

use App\Modules\Relation\Domain\ValueObjects\DateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    public function test_it_creates_valid_date_range()
    {
        $start = CarbonImmutable::parse('2026-07-25');
        $end = CarbonImmutable::parse('2026-07-27');

        $range = new DateRange($start, $end);

        $this->assertEquals($start, $range->getStartsAt());
        $this->assertEquals($end, $range->getEndsAt());
    }

    public function test_it_throws_exception_if_start_is_after_end()
    {
        $this->expectException(InvalidArgumentException::class);

        $start = CarbonImmutable::parse('2026-07-28');
        $end = CarbonImmutable::parse('2026-07-27');

        new DateRange($start, $end);
    }
}
