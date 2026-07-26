<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Domain\DTOs\PendingComponents;
use App\Modules\Payment\Domain\Enums\BankImportStatus;
use App\Modules\Payment\Domain\Enums\PostDueEvaluation;
use App\Modules\Payment\Domain\Enums\SettlementClassification;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Domain\Services\ExcessLedger;
use App\Modules\Payment\Domain\Services\PaymentAllocator;
use App\Modules\Payment\Domain\Services\RelationOutcomeClassifier;
use App\Modules\Payment\Domain\ValueObjects\BankFolio;
use App\Modules\Payment\Domain\ValueObjects\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class PaymentDomainTest extends TestCase
{
    public function test_money_keeps_four_decimals_and_rounds_final_amount_arithmetically(): void
    {
        self::assertSame('100.0050', (new Money('100.005'))->value());
        self::assertSame('100.01', (new Money('100.005'))->finalAmount());
        self::assertSame('-100.01', (new Money('-100.005'))->finalAmount());
        self::assertSame('1.2346', (new Money('1.23456'))->value());
        self::assertSame('0.3000', (new Money('0.1'))->add(new Money('0.2'))->value());
    }

    public function test_allocation_respects_financial_priority_and_recovers_only_capital(): void
    {
        $pending = new PendingComponents(
            new Money('300'),
            new Money('100'),
            new Money('50'),
            new Money('50'),
            new Money('500'),
        );
        $allocation = (new PaymentAllocator)->allocate(new Money('400'), $pending);

        self::assertSame('300.0000', $allocation->lateFee->value());
        self::assertSame('100.0000', $allocation->interest->value());
        self::assertSame('0.0000', $allocation->insurance->value());
        self::assertSame('0.0000', $allocation->capital->value());
        self::assertSame('0.0000', $allocation->recoveredCreditLine()->value());
        self::assertSame('600.0000', $allocation->balanceAfter->value());
    }

    public function test_exact_payment_settles_and_overpayment_is_separated_as_excess(): void
    {
        $pending = new PendingComponents(
            Money::zero(),
            new Money('100'),
            Money::zero(),
            Money::zero(),
            new Money('500'),
        );
        $exact = (new PaymentAllocator)->allocate(new Money('600'), $pending);
        self::assertSame('600.0000', $exact->applied->value());
        self::assertSame('500.0000', $exact->capital->value());
        self::assertTrue($exact->balanceAfter->isZero());
        self::assertTrue($exact->excess->isZero());

        $overpayment = (new PaymentAllocator)->allocate(new Money('725.50'), $pending);
        self::assertSame('600.0000', $overpayment->applied->value());
        self::assertSame('125.5000', $overpayment->excess->value());
        self::assertSame('500.0000', $overpayment->recoveredCreditLine()->value());
    }

    public function test_settlement_and_post_due_classification_use_effective_date_and_balance(): void
    {
        $classifier = new RelationOutcomeClassifier;
        $earlyStart = CarbonImmutable::parse('2026-07-20 00:00:00', 'America/Monterrey');
        $dueDate = CarbonImmutable::parse('2026-07-25 00:00:00', 'America/Monterrey');

        self::assertSame(
            SettlementClassification::EARLY,
            $classifier->settlement(CarbonImmutable::parse('2026-07-24 23:59:59', 'America/Monterrey'), $earlyStart, $dueDate),
        );
        self::assertSame(
            SettlementClassification::ON_TIME,
            $classifier->settlement(CarbonImmutable::parse('2026-07-25 23:59:59', 'America/Monterrey'), $earlyStart, $dueDate),
        );
        self::assertSame(
            SettlementClassification::LATE,
            $classifier->settlement(CarbonImmutable::parse('2026-07-26 00:00:00', 'America/Monterrey'), $earlyStart, $dueDate),
        );
        self::assertSame(PostDueEvaluation::SETTLED, $classifier->postDue(Money::zero(), false));
        self::assertSame(PostDueEvaluation::INSTALLMENT, $classifier->postDue(new Money('1'), true));
        self::assertSame(PostDueEvaluation::NO_PAYMENT, $classifier->postDue(new Money('1'), false));
    }

    public function test_uncovered_temporal_period_is_denied_instead_of_inventing_a_classification(): void
    {
        $this->expectException(PaymentDomainException::class);
        $this->expectExceptionMessage('contrato temporal');

        (new RelationOutcomeClassifier)->settlement(
            CarbonImmutable::parse('2026-07-10', 'America/Monterrey'),
            CarbonImmutable::parse('2026-07-20', 'America/Monterrey'),
            CarbonImmutable::parse('2026-07-25', 'America/Monterrey'),
        );
    }

    public function test_excess_ledger_requires_exact_mutual_exclusion_invariant(): void
    {
        $ledger = new ExcessLedger;
        $ledger->assertInvariant(
            new Money('100'),
            new Money('25'),
            new Money('30'),
            new Money('20'),
            new Money('25'),
        );

        $this->expectException(PaymentDomainException::class);
        $ledger->assertInvariant(
            new Money('100'),
            new Money('25'),
            new Money('30'),
            new Money('20'),
            new Money('20'),
        );
    }

    public function test_bank_folio_normalization_is_exact_and_rejects_formula_content(): void
    {
        $folio = new BankFolio('  abc-001  ');
        self::assertSame('  abc-001  ', $folio->raw);
        self::assertSame('ABC-001', $folio->normalized);

        $this->expectException(PaymentDomainException::class);
        new BankFolio('=HYPERLINK("unsafe")');
    }

    public function test_bank_import_transitions_are_closed_and_explicit(): void
    {
        self::assertTrue(BankImportStatus::RECEIVED->canTransitionTo(BankImportStatus::VALIDATING));
        self::assertTrue(BankImportStatus::FAILED->canTransitionTo(BankImportStatus::PROCESSING));
        self::assertFalse(BankImportStatus::PROCESSED->canTransitionTo(BankImportStatus::PROCESSING));
        self::assertFalse(BankImportStatus::VALIDATING->canTransitionTo(BankImportStatus::PROCESSED));
    }
}
