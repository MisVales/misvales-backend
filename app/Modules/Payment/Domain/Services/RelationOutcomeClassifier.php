<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Services;

use App\Modules\Payment\Domain\Enums\PostDueEvaluation;
use App\Modules\Payment\Domain\Enums\SettlementClassification;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Domain\ValueObjects\Money;
use Carbon\CarbonImmutable;

/** Clasifica resultados usando exclusivamente la fecha bancaria efectiva. */
final class RelationOutcomeClassifier
{
    public function settlement(
        CarbonImmutable $effectiveAt,
        CarbonImmutable $earlyPeriodStartsAt,
        CarbonImmutable $dueDate,
    ): SettlementClassification {
        $effectiveDate = $effectiveAt->toDateString();
        $earlyDate = $earlyPeriodStartsAt->toDateString();
        $limitDate = $dueDate->toDateString();

        if ($effectiveDate < $earlyDate) {
            throw PaymentDomainException::temporalContractUnavailable();
        }
        if ($effectiveDate < $limitDate) {
            return SettlementClassification::EARLY;
        }
        if ($effectiveDate === $limitDate) {
            return SettlementClassification::ON_TIME;
        }

        return SettlementClassification::LATE;
    }

    public function postDue(Money $balance, bool $hasReconciledPayment): PostDueEvaluation
    {
        if ($balance->isNegative()) {
            throw PaymentDomainException::financialInconsistent();
        }
        if ($balance->isZero()) {
            return PostDueEvaluation::SETTLED;
        }

        return $hasReconciledPayment
            ? PostDueEvaluation::INSTALLMENT
            : PostDueEvaluation::NO_PAYMENT;
    }
}
