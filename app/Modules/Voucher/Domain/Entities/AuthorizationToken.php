<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Entities;

use App\Modules\Voucher\Domain\Enums\DataChangeOperation;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use Carbon\CarbonImmutable;

/** Alcance inmutable de un token de corrección. */
final readonly class AuthorizationToken
{
    /** @param list<string> $fields */
    public function __construct(
        public int $cashierId,
        public string $voucherId,
        public string $clientId,
        public int $branchId,
        public DataChangeOperation $operation,
        public array $fields,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $consumedAt,
        public ?CarbonImmutable $revokedAt,
    ) {}

    /** @param list<string> $fields */
    public function assertScope(
        int $cashierId,
        string $voucherId,
        string $clientId,
        int $branchId,
        DataChangeOperation $operation,
        array $fields,
        CarbonImmutable $now,
    ): void {
        if ($this->consumedAt !== null) {
            throw VoucherDomainException::tokenUsed();
        }
        if ($this->revokedAt !== null) {
            throw VoucherDomainException::tokenInvalid();
        }
        if ($now->gte($this->expiresAt)) {
            throw VoucherDomainException::tokenExpired();
        }

        $expected = $this->fields;
        sort($expected);
        sort($fields);
        if (
            $cashierId !== $this->cashierId
            || $voucherId !== $this->voucherId
            || $clientId !== $this->clientId
            || $branchId !== $this->branchId
            || $fields !== $expected
        ) {
            throw VoucherDomainException::tokenInvalid();
        }
    }
}
