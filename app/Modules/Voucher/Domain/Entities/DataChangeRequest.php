<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Entities;

use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use Carbon\CarbonImmutable;

/** Entidad de decisión de una solicitud de modificación. */
final class DataChangeRequest
{
    public function __construct(
        private DataChangeRequestStatus $status,
        private int $lockVersion,
    ) {}

    public function authorize(): void
    {
        $this->assertPending();
        $this->status = DataChangeRequestStatus::AUTHORIZED;
        $this->lockVersion++;
    }

    public function reject(): void
    {
        $this->assertPending();
        $this->status = DataChangeRequestStatus::REJECTED;
        $this->lockVersion++;
    }

    public function use(): void
    {
        if ($this->status !== DataChangeRequestStatus::AUTHORIZED) {
            throw VoucherDomainException::tokenInvalid();
        }
        $this->status = DataChangeRequestStatus::USED;
        $this->lockVersion++;
    }

    public function expire(CarbonImmutable $now, CarbonImmutable $expiresAt): void
    {
        if ($this->status !== DataChangeRequestStatus::AUTHORIZED || $now->lt($expiresAt)) {
            throw VoucherDomainException::tokenInvalid();
        }
        $this->status = DataChangeRequestStatus::EXPIRED;
        $this->lockVersion++;
    }

    public function status(): DataChangeRequestStatus
    {
        return $this->status;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    private function assertPending(): void
    {
        if ($this->status !== DataChangeRequestStatus::PENDING) {
            throw VoucherDomainException::requestNotPending();
        }
    }
}
