<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Modules\Points\Application\Services\CompletePointRedemption;
use App\Modules\Points\Application\Services\RequestPointRedemption;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Domain\Services\LatePaymentPenaltyCalculator;
use App\Modules\Points\Domain\Services\PointEarningCalculator;
use App\Modules\Points\Domain\Services\RedemptionAmountCalculator;
use App\Modules\Points\Domain\ValueObjects\PointBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PointsDomainTest extends TestCase
{
    #[DataProvider('earningCases')]
    public function test_earning_uses_floor_before_multiplier(
        string $basis,
        string $divisor,
        int $multiplier,
        int $expected,
    ): void {
        self::assertSame($expected, (new PointEarningCalculator)->calculate($basis, $divisor, $multiplier));
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function earningCases(): iterable
    {
        yield 'official example' => ['5000.0000', '1200.0000', 3, 12];
        yield 'below divisor' => ['1199.9999', '1200.0000', 3, 0];
        yield 'equal divisor' => ['1200.0000', '1200.0000', 3, 3];
        yield 'configurable multiplier' => ['2400.0000', '1200.0000', 7, 14];
    }

    public function test_late_penalty_is_floored_and_never_fractional(): void
    {
        $calculator = new LatePaymentPenaltyCalculator;

        self::assertSame(20, $calculator->calculate(100, '0.2000'));
        self::assertSame(0, $calculator->calculate(4, '0.2000'));
        self::assertSame(33, $calculator->calculate(101, '0.3333'));
    }

    public function test_calculators_reject_non_decimal_input_before_bcmath(): void
    {
        $this->expectException(PointsDomainException::class);

        (new PointEarningCalculator)->calculate('not-money', '1200.0000', 3);
    }

    public function test_point_balance_enforces_total_reserved_and_available_invariants(): void
    {
        $balance = new PointBalance(120, 30);
        self::assertSame(90, $balance->available);

        $this->expectException(PointsDomainException::class);
        new PointBalance(10, 11);
    }

    public function test_redemption_amount_keeps_four_internal_decimals(): void
    {
        self::assertSame('24.0000', (new RedemptionAmountCalculator)->calculate(12, '2.0000'));
    }

    public function test_undefined_redemption_quantity_is_fail_closed(): void
    {
        $this->expectException(PointsDomainException::class);
        $this->expectExceptionMessage('cantidad canjeable');

        (new RequestPointRedemption)->execute(new User, 10, 'key');
    }

    public function test_undefined_delivery_actor_is_fail_closed(): void
    {
        $this->expectException(PointsDomainException::class);
        $this->expectExceptionMessage('rol ejecutor');

        (new CompletePointRedemption)->execute(new User, 'request', [], 'key');
    }
}
