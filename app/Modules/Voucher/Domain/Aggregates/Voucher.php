<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Aggregates;

use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/** Agregado de transiciones de caja; no conoce HTTP ni persistencia. */
final class Voucher
{
    public function __construct(
        public readonly string $id,
        private VoucherStatus $status,
        private int $lockVersion,
    ) {}

    public function status(): VoucherStatus
    {
        return $this->status;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    public function openAtCounter(): void
    {
        if ($this->status !== VoucherStatus::GENERATED) {
            throw VoucherDomainException::notAvailableAtCounter();
        }

        $this->transitionTo(VoucherStatus::COUNTER_VALIDATION);
    }

    public function requestCorrection(): void
    {
        $this->assertStatus(VoucherStatus::COUNTER_VALIDATION);
        $this->transitionTo(VoucherStatus::CORRECTION_PENDING);
    }

    public function applyCorrection(): void
    {
        $this->assertStatus(VoucherStatus::CORRECTION_PENDING);
        $this->transitionTo(VoucherStatus::COUNTER_VALIDATION);
    }

    public function release(): void
    {
        $this->assertStatus(VoucherStatus::COUNTER_VALIDATION);
        $this->transitionTo(VoucherStatus::RELEASED);
    }

    public function reject(): void
    {
        $this->assertStatus(VoucherStatus::COUNTER_VALIDATION);
        $this->transitionTo(VoucherStatus::REJECTED);
    }

    public function fulfill(): void
    {
        if ($this->status === VoucherStatus::FULFILLED) {
            throw VoucherDomainException::alreadyFulfilled();
        }

        $this->assertStatus(VoucherStatus::RELEASED);
        $this->transitionTo(VoucherStatus::FULFILLED);
    }

    private function assertStatus(VoucherStatus $expected): void
    {
        if ($this->status !== $expected || $this->status->isTerminal()) {
            throw VoucherDomainException::invalidTransition();
        }
    }

    private function transitionTo(VoucherStatus $next): void
    {
        $this->status = $next;
        $this->lockVersion++;
    }
}
