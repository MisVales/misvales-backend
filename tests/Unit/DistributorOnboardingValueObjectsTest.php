<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\DistributorOnboarding\Domain\Decisions\InitialCreditLine;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DistributorOnboardingValueObjectsTest extends TestCase
{
    public function test_email_is_normalized_without_creating_an_account(): void
    {
        $email = new NormalizedEmail('  Aspirante@Example.COM ');

        self::assertSame('aspirante@example.com', $email->value);
        self::assertSame(64, strlen($email->protectedHash('test-key')));
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->expectException(OnboardingDomainException::class);

        new NormalizedEmail('not-an-email');
    }

    public function test_workflow_fields_are_not_mass_assignable(): void
    {
        $application = new DistributorApplication;

        foreach ([
            'folio', 'branch_id', 'coordinator_user_id', 'status', 'result',
            'lock_version', 'created_by', 'submitted_by', 'submitted_at',
        ] as $field) {
            self::assertFalse($application->isFillable($field), $field);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function exactAmounts(): iterable
    {
        yield 'integer' => ['15000', '15000.0000'];
        yield 'two decimals' => ['15000.25', '15000.2500'];
        yield 'four decimals' => ['0.0001', '0.0001'];
        yield 'zero remains accepted pending definition' => ['0', '0.0000'];
    }

    #[DataProvider('exactAmounts')]
    public function test_initial_line_is_an_exact_four_decimal_string(string $input, string $expected): void
    {
        self::assertSame($expected, (new InitialCreditLine($input))->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'scientific notation' => ['1e4'];
        yield 'negative' => ['-1'];
        yield 'too many decimals' => ['1.00001'];
        yield 'float-style leading zero' => ['01.00'];
        yield 'technical overflow' => ['1234567890123456.0000'];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_initial_line_formats_are_rejected(string $input): void
    {
        $this->expectException(OnboardingDomainException::class);

        new InitialCreditLine($input);
    }
}
