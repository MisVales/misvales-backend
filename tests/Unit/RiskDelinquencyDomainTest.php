<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\RiskDelinquency\Domain\Enums\FinancialResult;
use App\Modules\RiskDelinquency\Domain\Enums\RelationRiskEvaluationStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Domain\Services\ClassifyRelationRisk;
use App\Modules\RiskDelinquency\Domain\ValueObjects\OverdueBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RiskDelinquencyDomainTest extends TestCase
{
    #[DataProvider('validClassifications')]
    public function test_official_financial_combinations_are_classified(
        FinancialResult $result,
        string $balance,
        RelationRiskEvaluationStatus $expected,
    ): void {
        self::assertSame($expected, (new ClassifyRelationRisk)->classify($result, new OverdueBalance($balance)));
    }

    /** @return iterable<string, array{FinancialResult, string, RelationRiskEvaluationStatus}> */
    public static function validClassifications(): iterable
    {
        yield 'liquidated' => [FinancialResult::LIQUIDO, '0.0000', RelationRiskEvaluationStatus::COMPLIANT];
        yield 'partial payment' => [FinancialResult::ABONO, '10.2500', RelationRiskEvaluationStatus::BREACHED];
        yield 'no payment' => [FinancialResult::NO_PAGO, '500.0000', RelationRiskEvaluationStatus::BREACHED];
    }

    #[DataProvider('inconsistentClassifications')]
    public function test_inconsistent_financial_combinations_are_rejected(FinancialResult $result, string $balance): void
    {
        $this->expectException(RiskDelinquencyException::class);
        $this->expectExceptionMessage('incoherente');

        (new ClassifyRelationRisk)->classify($result, new OverdueBalance($balance));
    }

    /** @return iterable<string, array{FinancialResult, string}> */
    public static function inconsistentClassifications(): iterable
    {
        yield 'liquidated with balance' => [FinancialResult::LIQUIDO, '0.0001'];
        yield 'partial with zero' => [FinancialResult::ABONO, '0'];
        yield 'no payment with zero' => [FinancialResult::NO_PAGO, '0.0000'];
    }

    public function test_money_rejects_negative_or_non_decimal_values(): void
    {
        $this->expectException(RiskDelinquencyException::class);
        new OverdueBalance('-1.00');
    }
}
