<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Reporting\Application\Services\ExactDecimalAggregator;
use App\Modules\Reporting\Application\Services\ReportParameterNormalizer;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Application\Services\ReportResultProtector;
use App\Modules\Reporting\Application\Services\SensitiveDataMasker;
use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use DateTimeImmutable;
use Tests\TestCase;

final class ReportingDomainTest extends TestCase
{
    public function test_official_catalog_is_closed_unique_and_complete(): void
    {
        $registry = app(ReportRegistry::class);
        self::assertCount(21, $registry->all());
        self::assertSame(
            array_map(static fn (ReportCode $code): string => $code->value, ReportCode::cases()),
            array_map(static fn ($definition): string => $definition->code->value, $registry->all()),
        );
        foreach ($registry->all() as $definition) {
            self::assertNotEmpty($definition->roles);
            self::assertNotEmpty($definition->filters);
            self::assertNotEmpty($definition->columns);
            self::assertNotEmpty($definition->sourceModules);
        }
        self::assertCount(0, $registry->forRole(RoleCode::CASHIER));
        self::assertCount(0, $registry->forRole(RoleCode::VERIFIER));
        self::assertCount(13, $registry->forRole(RoleCode::DISTRIBUTOR));
    }

    public function test_filters_are_normalized_and_dates_use_monterrrey_boundaries(): void
    {
        $definition = app(ReportRegistry::class)->get(ReportCode::CREDIT_LINE_SUMMARY);
        $normalizer = app(ReportParameterNormalizer::class);
        $filters = $normalizer->normalize($definition, [
            'status' => ['ACTIVE', 'ACTIVE', 'RESTRICTED'],
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        self::assertSame(['ACTIVE', 'RESTRICTED'], $filters['status']);
        self::assertSame('2026-01-01T06:00:00Z', $filters['date_from']);
        self::assertSame('2026-02-01T06:00:00Z', $filters['date_to']);
        self::assertSame(
            $normalizer->hash($definition, $filters, ['type' => 'GLOBAL']),
            $normalizer->hash($definition, $filters, ['type' => 'GLOBAL']),
        );
    }

    public function test_unsupported_filter_and_inverted_range_are_rejected(): void
    {
        $definition = app(ReportRegistry::class)->get(ReportCode::CREDIT_LINE_SUMMARY);
        $normalizer = app(ReportParameterNormalizer::class);

        try {
            $normalizer->normalize($definition, ['table' => 'users']);
            self::fail('A physical table filter must be rejected.');
        } catch (ReportingException $exception) {
            self::assertSame('REPORT_FILTER_UNSUPPORTED', $exception->errorCode());
        }

        $this->expectExceptionObject(ReportingException::invalidDateRange());
        $normalizer->normalize($definition, ['date_from' => '2026-02-01', 'date_to' => '2026-01-01']);
    }

    public function test_masking_and_money_are_deterministic_and_exact(): void
    {
        self::assertSame('**********1234', (new SensitiveDataMasker)->lastCharacters('01234567891234'));
        self::assertSame('0.60', (new ExactDecimalAggregator)->sum(['0.10', '0.20', '0.30']));
    }

    public function test_run_state_machine_rejects_terminal_reexecution(): void
    {
        self::assertTrue(ReportRunStatus::QUEUED->canTransitionTo(ReportRunStatus::RUNNING));
        self::assertTrue(ReportRunStatus::RUNNING->canTransitionTo(ReportRunStatus::COMPLETED));
        self::assertFalse(ReportRunStatus::COMPLETED->canTransitionTo(ReportRunStatus::RUNNING));
        self::assertFalse(ReportRunStatus::EXPIRED->canTransitionTo(ReportRunStatus::COMPLETED));
    }

    public function test_result_protector_rejects_columns_outside_the_public_contract(): void
    {
        $definition = app(ReportRegistry::class)->get(ReportCode::CREDIT_LINE_SUMMARY);
        $result = new ReportResult(
            [['distributor' => 'Ficticia', 'curp' => 'SENSITIVE']],
            ['total' => '0.00'],
            [],
            new DateTimeImmutable('2026-07-27T00:00:00Z'),
        );

        $this->expectExceptionObject(ReportingException::dataMinimizationFailed());
        (new ReportResultProtector)->protect($definition, $result);
    }
}
