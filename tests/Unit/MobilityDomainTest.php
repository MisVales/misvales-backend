<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Mobility\Application\Contracts\ClientAssignmentSnapshot;
use App\Modules\Mobility\Domain\Enums\ClientTransferStatus;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Mobility\Domain\Services\TransferStateMachine;
use App\Modules\Mobility\Domain\ValueObjects\MobilityFolio;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MobilityDomainTest extends TestCase
{
    public function test_zero_balance_requires_total_and_overdue_to_be_exactly_zero(): void
    {
        self::assertTrue($this->snapshot('0.0000', '0.0000')->hasZeroBalance());
        self::assertFalse($this->snapshot('0.0001', '0.0000')->hasZeroBalance());
        self::assertFalse($this->snapshot('0.0000', '1.0000')->hasZeroBalance());
    }

    public function test_transfer_matrix_accepts_only_the_documented_path(): void
    {
        $this->expectNotToPerformAssertions();

        $machine = new TransferStateMachine;
        $machine->assert(ClientTransferStatus::REQUESTED, ClientTransferStatus::PREACCEPTED);
        $machine->assert(ClientTransferStatus::PREACCEPTED, ClientTransferStatus::ORIGIN_EXIT_AUTHORIZED);
        $machine->assert(ClientTransferStatus::ORIGIN_EXIT_AUTHORIZED, ClientTransferStatus::COMPLETED);
    }

    public function test_transfer_matrix_rejects_free_state_changes(): void
    {
        $this->expectException(MobilityException::class);
        (new TransferStateMachine)->assert(ClientTransferStatus::REQUESTED, ClientTransferStatus::COMPLETED);
    }

    public function test_folio_is_closed_to_the_m15_format(): void
    {
        self::assertSame('MV15-TR-ABCDEFGHIJKL', (string) new MobilityFolio('MV15-TR-ABCDEFGHIJKL'));
        $this->expectException(InvalidArgumentException::class);
        new MobilityFolio('TRANSFER-1');
    }

    private function snapshot(string $total, string $overdue): ClientAssignmentSnapshot
    {
        return new ClientAssignmentSnapshot(
            '6df07fd3-5b15-4dc5-a217-c24589b59ef5',
            'b9ce75d1-692f-44db-bdf1-830c50c83267',
            1,
            'ec6d6cf5-6cca-46c4-9a18-59cc8dcc7d7b',
            1,
            1,
            $total,
            $overdue,
        );
    }
}
