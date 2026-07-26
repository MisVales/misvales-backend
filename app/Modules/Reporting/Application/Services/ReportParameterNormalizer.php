<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

final class ReportParameterNormalizer
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(ReportDefinition $definition, array $input): array
    {
        $control = ['page', 'per_page', 'sort', 'direction'];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, [...$definition->filters, ...$control], true)) {
                throw ReportingException::unsupportedFilter($key);
            }
        }

        $filters = array_intersect_key($input, array_flip($definition->filters));
        foreach ($filters as $key => &$value) {
            $value = $this->normalizeValue($key, $value);
        }
        unset($value);

        $this->normalizeDates($filters);
        ksort($filters);

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $scope
     */
    public function hash(ReportDefinition $definition, array $filters, array $scope): string
    {
        $payload = [
            'report_code' => $definition->code->value,
            'contract_version' => $definition->contractVersion,
            'filters' => $filters,
            'scope' => $scope,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            $limit = (int) config('reporting.maximum_multi_filter_values', 50);
            $values = array_values(array_unique(array_map(
                fn (mixed $item): mixed => $this->normalizeValue($key, $item),
                $value,
            ), SORT_REGULAR));
            if (count($values) > $limit) {
                throw ReportingException::invalidFilter($key);
            }
            usort($values, static fn (mixed $left, mixed $right): int => (string) $left <=> (string) $right);

            return $values;
        }
        if (! is_scalar($value) || is_bool($value)) {
            throw ReportingException::invalidFilter($key);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw ReportingException::invalidFilter($key);
        }
        if (str_ends_with($key, '_id') && ! Str::isUuid($normalized)) {
            throw ReportingException::invalidFilter($key);
        }
        if (in_array($key, ['folio', 'reference'], true)) {
            $length = mb_strlen($normalized);
            if ($length < (int) config('reporting.minimum_text_length', 2)
                || $length > (int) config('reporting.maximum_text_length', 120)) {
                throw ReportingException::invalidFilter($key);
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $filters */
    private function normalizeDates(array &$filters): void
    {
        if (! isset($filters['date_from']) && ! isset($filters['date_to'])) {
            return;
        }
        try {
            $timezone = new DateTimeZone((string) config('reporting.business_timezone', 'America/Monterrey'));
            $from = isset($filters['date_from'])
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_from'], $timezone)
                : null;
            $to = isset($filters['date_to'])
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_to'], $timezone)
                : null;
            if (($from !== null && $from->format('Y-m-d') !== $filters['date_from'])
                || ($to !== null && $to->format('Y-m-d') !== $filters['date_to'])) {
                throw ReportingException::invalidDateRange();
            }
        } catch (ReportingException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ReportingException::invalidDateRange();
        }

        if ($from !== null && $to !== null) {
            if ($from->greaterThan($to)
                || $from->diffInDays($to) > (int) config('reporting.maximum_date_range_days', 366)) {
                throw ReportingException::invalidDateRange();
            }
        }
        if ($from !== null) {
            $filters['date_from'] = $from->utc()->format('Y-m-d\TH:i:s\Z');
        }
        if ($to !== null) {
            $filters['date_to'] = $to->addDay()->utc()->format('Y-m-d\TH:i:s\Z');
        }
    }
}
