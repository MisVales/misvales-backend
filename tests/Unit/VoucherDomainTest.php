<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Client\Application\AuthorizedChanges\DefaultCorrectableClientFieldRegistry;
use App\Modules\Voucher\Domain\Aggregates\Voucher;
use App\Modules\Voucher\Domain\Entities\AuthorizationToken;
use App\Modules\Voucher\Domain\Entities\DataChangeRequest;
use App\Modules\Voucher\Domain\Enums\DataChangeOperation;
use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class VoucherDomainTest extends TestCase
{
    public function test_voucher_only_accepts_the_defined_counter_transitions(): void
    {
        $voucher = new Voucher('voucher-1', VoucherStatus::GENERATED, 1);
        $voucher->openAtCounter();
        self::assertSame(VoucherStatus::COUNTER_VALIDATION, $voucher->status());
        self::assertSame(2, $voucher->lockVersion());

        $voucher->requestCorrection();
        self::assertSame(VoucherStatus::CORRECTION_PENDING, $voucher->status());
        $voucher->applyCorrection();
        $voucher->release();
        $voucher->fulfill();

        self::assertSame(VoucherStatus::FULFILLED, $voucher->status());
        self::assertSame(6, $voucher->lockVersion());
    }

    public function test_terminal_voucher_cannot_transition_again(): void
    {
        $voucher = new Voucher('voucher-1', VoucherStatus::REJECTED, 4);

        $this->expectException(VoucherDomainException::class);
        $voucher->openAtCounter();
    }

    public function test_request_and_token_enforce_state_expiration_and_exact_scope(): void
    {
        $request = new DataChangeRequest(DataChangeRequestStatus::PENDING, 1);
        $request->authorize();
        self::assertSame(DataChangeRequestStatus::AUTHORIZED, $request->status());

        $now = CarbonImmutable::parse('2026-07-25T18:00:00Z');
        $token = new AuthorizationToken(
            cashierId: 10,
            voucherId: 'voucher-1',
            clientId: 'client-1',
            branchId: 20,
            operation: DataChangeOperation::CLIENT_DATA_CORRECTION,
            fields: ['address', 'curp'],
            expiresAt: $now->addMinutes(5),
            consumedAt: null,
            revokedAt: null,
        );
        $token->assertScope(
            10,
            'voucher-1',
            'client-1',
            20,
            DataChangeOperation::CLIENT_DATA_CORRECTION,
            ['curp', 'address'],
            $now,
        );

        $this->expectException(VoucherDomainException::class);
        $token->assertScope(
            11,
            'voucher-1',
            'client-1',
            20,
            DataChangeOperation::CLIENT_DATA_CORRECTION,
            ['address', 'curp'],
            $now,
        );
    }

    public function test_m06_owns_the_exact_correctable_field_registry(): void
    {
        $registry = new DefaultCorrectableClientFieldRegistry;

        self::assertTrue($registry->containsExactly(['curp', 'address']));
        self::assertFalse($registry->containsExactly([]));
        self::assertFalse($registry->containsExactly(['capital_amount']));
        self::assertFalse($registry->containsExactly(['curp', 'curp']));
    }

    public function test_token_expires_at_the_exact_five_minute_boundary(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-07-25T18:00:00Z');
        $token = new AuthorizationToken(
            cashierId: 10,
            voucherId: 'voucher-1',
            clientId: 'client-1',
            branchId: 20,
            operation: DataChangeOperation::CLIENT_DATA_CORRECTION,
            fields: ['curp'],
            expiresAt: $issuedAt->addMinutes(5),
            consumedAt: null,
            revokedAt: null,
        );

        $this->expectException(VoucherDomainException::class);
        $this->expectExceptionMessage('terminó su vigencia');
        $token->assertScope(
            10,
            'voucher-1',
            'client-1',
            20,
            DataChangeOperation::CLIENT_DATA_CORRECTION,
            ['curp'],
            $issuedAt->addMinutes(5),
        );
    }

    public function test_consumed_token_cannot_be_reused(): void
    {
        $now = CarbonImmutable::parse('2026-07-25T18:00:00Z');
        $token = new AuthorizationToken(
            cashierId: 10,
            voucherId: 'voucher-1',
            clientId: 'client-1',
            branchId: 20,
            operation: DataChangeOperation::CLIENT_DATA_CORRECTION,
            fields: ['curp'],
            expiresAt: $now->addMinutes(5),
            consumedAt: $now,
            revokedAt: null,
        );

        $this->expectException(VoucherDomainException::class);
        $token->assertScope(
            10,
            'voucher-1',
            'client-1',
            20,
            DataChangeOperation::CLIENT_DATA_CORRECTION,
            ['curp'],
            $now,
        );
    }
}
