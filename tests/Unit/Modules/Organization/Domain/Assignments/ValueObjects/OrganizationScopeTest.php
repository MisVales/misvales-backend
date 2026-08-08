<?php

namespace Tests\Unit\Modules\Organization\Domain\Assignments\ValueObjects;

use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrganizationScopeTest extends TestCase
{
    #[DataProvider('validScopes')]
    public function test_it_accepts_every_controlled_scope(string $input, OrganizationScope $expected): void
    {
        self::assertSame($expected, OrganizationScope::fromString($input));
    }

    /** @return array<string, array{string, OrganizationScope}> */
    public static function validScopes(): array
    {
        return [
            'global' => ['global', OrganizationScope::GLOBAL],
            'branch' => ['BRANCH', OrganizationScope::BRANCH],
            'assigned' => [' assigned ', OrganizationScope::ASSIGNED],
            'self' => ['SELF', OrganizationScope::SELF],
        ];
    }

    public function test_only_branch_scope_requires_a_branch(): void
    {
        self::assertTrue(OrganizationScope::BRANCH->requiresBranch());
        self::assertFalse(OrganizationScope::GLOBAL->requiresBranch());
        self::assertFalse(OrganizationScope::ASSIGNED->requiresBranch());
        self::assertFalse(OrganizationScope::SELF->requiresBranch());
    }

    public function test_it_rejects_an_unknown_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrganizationScope::fromString('REGIONAL');
    }
}
