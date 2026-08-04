<?php

namespace Tests\Unit\Modules\Organization\Domain\Branches\ValueObjects;

use App\Modules\Organization\Domain\Branches\ValueObjects\BranchCode;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchName;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchValueObjectsTest extends TestCase
{
    public function test_branch_id_accepts_and_normalizes_a_uuid(): void
    {
        $id = BranchId::fromString('019FCBEC-4BA4-7721-BF39-C9729FB0BD67');

        self::assertSame('019fcbec-4ba4-7721-bf39-c9729fb0bd67', $id->value());
        self::assertSame($id->value(), (string) $id);
    }

    #[DataProvider('invalidBranchIds')]
    public function test_branch_id_rejects_invalid_values(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchId::fromString($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidBranchIds(): array
    {
        return [
            'empty' => [''],
            'not a uuid' => ['torreon'],
            'incomplete' => ['019fcbec-4ba4-7721-bf39'],
        ];
    }

    public function test_branch_code_is_trimmed_and_normalized_to_uppercase(): void
    {
        $code = BranchCode::fromString('  trc-centro_01  ');

        self::assertSame('TRC-CENTRO_01', $code->value());
    }

    #[DataProvider('invalidBranchCodes')]
    public function test_branch_code_rejects_invalid_values(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchCode::fromString($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidBranchCodes(): array
    {
        return [
            'empty' => ['   '],
            'spaces' => ['TRC CENTRO'],
            'invalid initial character' => ['-TRC'],
            'too long' => [str_repeat('A', BranchCode::MAX_LENGTH + 1)],
        ];
    }

    public function test_branch_name_preserves_internal_characters_and_trims_edges(): void
    {
        $name = BranchName::fromString('  Sucursal Gómez Palacio  ');

        self::assertSame('Sucursal Gómez Palacio', $name->value());
    }

    #[DataProvider('invalidBranchNames')]
    public function test_branch_name_rejects_invalid_values(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchName::fromString($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidBranchNames(): array
    {
        return [
            'empty' => ['   '],
            'too long' => [str_repeat('A', BranchName::MAX_LENGTH + 1)],
        ];
    }

    public function test_branch_status_accepts_only_controlled_values(): void
    {
        self::assertSame(BranchStatus::ACTIVE, BranchStatus::fromString(' active '));
        self::assertSame(BranchStatus::INACTIVE, BranchStatus::fromString('INACTIVE'));
        self::assertTrue(BranchStatus::ACTIVE->isActive());
        self::assertFalse(BranchStatus::INACTIVE->isActive());
    }

    public function test_branch_status_rejects_unknown_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchStatus::fromString('SUSPENDED');
    }
}
