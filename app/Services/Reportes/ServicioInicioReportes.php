<?php

namespace App\Services\Reportes;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ServicioInicioReportes
{
    private const PENDING_APPLICATION_STATUSES = [
        'DRAFT',
        'COORDINATOR_REVIEW',
        'COORDINATOR_CORRECTION',
    ];

    public function obtener(User $actor): array
    {
        abort_unless(
            $actor->hasPermissionTo('reports.view_global') || $actor->hasPermissionTo('reports.view_branch'),
            403,
        );

        $branchIds = $actor->hasPermissionTo('reports.view_global')
            ? null
            : $actor->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereNotNull('branch_id')
                ->pluck('branch_id');

        return [
            'generated_at' => now()->toIso8601String(),
            'financial' => $this->financial($branchIds),
            'delinquency' => $this->delinquency($branchIds),
            'cutoffs' => $this->cutoffs($branchIds),
            'points' => $this->points($branchIds),
            'applications' => $this->applications($branchIds),
        ];
    }

    private function financial(?iterable $branchIds): array
    {
        $periodStart = CarbonImmutable::now()->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $relations = DB::table('distributor_relations as r')
            ->whereBetween('r.cutoff_at', [$periodStart, $periodEnd]);
        $this->scopeToBranches($relations, 'r.branch_id', $branchIds);

        $summary = (clone $relations)
            ->selectRaw('COALESCE(SUM(r.portfolio_total), 0) as portfolio_total')
            ->selectRaw('COALESCE(SUM(r.misvales_total), 0) as misvales_total')
            ->selectRaw('COALESCE(SUM(r.balance), 0) as pending_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.balance > 0 AND r.payment_deadline_at < ? THEN r.balance ELSE 0 END), 0) as overdue_total', [now()])
            ->selectRaw('COUNT(*) as relations')
            ->first();

        $received = DB::table('relation_payments as p')
            ->join('distributor_relations as r', 'r.id', '=', 'p.relation_id')
            ->whereBetween('p.applied_at', [$periodStart, $periodEnd]);
        $this->scopeToBranches($received, 'r.branch_id', $branchIds);

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'portfolio_total' => bcadd('0', (string) ($summary->portfolio_total ?? '0'), 4),
            'misvales_total' => bcadd('0', (string) ($summary->misvales_total ?? '0'), 4),
            'received_total' => bcadd('0', (string) ($received->sum('p.amount') ?? '0'), 4),
            'pending_total' => bcadd('0', (string) ($summary->pending_total ?? '0'), 4),
            'overdue_total' => bcadd('0', (string) ($summary->overdue_total ?? '0'), 4),
            'relations' => (int) ($summary->relations ?? 0),
        ];
    }

    private function delinquency(?iterable $branchIds): array
    {
        $summaryQuery = DB::table('distributor_operational_blocks as b')
            ->join('distributors as d', 'd.id', '=', 'b.distributor_id')
            ->where('b.type', 'DELINQUENCY')
            ->where('b.status', 'ACTIVE');
        $this->scopeToBranches($summaryQuery, 'd.branch_id', $branchIds);

        $overdueBalanceQuery = DB::table('distributor_relations as r')
            ->where('r.balance', '>', 0)
            ->where('r.payment_deadline_at', '<=', now());
        $this->scopeToBranches($overdueBalanceQuery, 'r.branch_id', $branchIds);

        $rowsQuery = DB::table('distributor_operational_blocks as b')
            ->join('distributors as d', 'd.id', '=', 'b.distributor_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('branches as br', 'br.id', '=', 'd.branch_id')
            ->where('b.type', 'DELINQUENCY')
            ->where('b.status', 'ACTIVE')
            ->select([
                'd.distributor_number',
                'u.name as distributor_name',
                'br.name as branch_name',
                'b.status',
                'b.starts_at',
            ])
            ->selectSub(
                DB::table('distributor_relations as overdue')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('overdue.distributor_id', 'd.id')
                    ->where('overdue.balance', '>', 0)
                    ->where('overdue.payment_deadline_at', '<=', now()),
                'overdue_relations',
            )
            ->selectSub(
                DB::table('distributor_relations as overdue')
                    ->selectRaw('COALESCE(SUM(overdue.balance), 0)')
                    ->whereColumn('overdue.distributor_id', 'd.id')
                    ->where('overdue.balance', '>', 0)
                    ->where('overdue.payment_deadline_at', '<=', now()),
                'overdue_balance',
            )
            ->orderByDesc('overdue_balance')
            ->limit(5);
        $this->scopeToBranches($rowsQuery, 'd.branch_id', $branchIds);

        return [
            'total' => (int) $summaryQuery->count(),
            'overdue_balance' => (string) ($overdueBalanceQuery->sum('r.balance') ?? '0'),
            'rows' => $rowsQuery->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function cutoffs(?iterable $branchIds): array
    {
        $balanceQuery = DB::table('distributor_relations as r')->where('r.balance', '>', 0);
        $this->scopeToBranches($balanceQuery, 'r.branch_id', $branchIds);

        $rowsQuery = DB::table('distributor_relations as r')
            ->join('branches as br', 'br.id', '=', 'r.branch_id')
            ->where('r.balance', '>', 0)
            ->groupBy('r.process_run_id', 'r.cutoff_at', 'r.payment_deadline_at', 'br.name')
            ->select([
                'r.cutoff_at',
                'r.payment_deadline_at',
                'br.name as branch_name',
            ])
            ->selectRaw('COUNT(DISTINCT r.distributor_id) as distributors')
            ->selectRaw('SUM(r.balance) as total_balance')
            ->orderByDesc('r.cutoff_at')
            ->limit(5);
        $this->scopeToBranches($rowsQuery, 'r.branch_id', $branchIds);

        $activeCutsQuery = DB::table('distributor_relations as r')
            ->where('r.balance', '>', 0)
            ->distinct('r.process_run_id');
        $this->scopeToBranches($activeCutsQuery, 'r.branch_id', $branchIds);

        return [
            'total_balance' => (string) ($balanceQuery->sum('r.balance') ?? '0'),
            'active_count' => (int) $activeCutsQuery->count('r.process_run_id'),
            'rows' => $rowsQuery->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function points(?iterable $branchIds): array
    {
        $accountsQuery = DB::table('point_accounts as pa')
            ->join('distributors as d', 'd.id', '=', 'pa.distributor_id');
        $this->scopeToBranches($accountsQuery, 'd.branch_id', $branchIds);

        $summary = (clone $accountsQuery)
            ->selectRaw('COALESCE(SUM(pa.balance - pa.reserved), 0) as available_points')
            ->selectRaw('COUNT(*) as distributors')
            ->first();

        $trendStart = CarbonImmutable::now()->startOfMonth()->subMonths(5);
        $movementsQuery = DB::table('point_movements as pm')
            ->join('distributors as d', 'd.id', '=', 'pm.distributor_id')
            ->where('pm.created_at', '>=', $trendStart)
            ->select(['pm.points', 'pm.created_at'])
            ->orderBy('pm.created_at');
        $this->scopeToBranches($movementsQuery, 'd.branch_id', $branchIds);

        $monthly = collect(range(0, 5))->mapWithKeys(function (int $offset) use ($trendStart): array {
            $month = $trendStart->addMonths($offset);

            return [$month->format('Y-m') => ['period' => $month->format('Y-m-01'), 'points' => 0]];
        });

        foreach ($movementsQuery->cursor() as $movement) {
            $key = CarbonImmutable::parse($movement->created_at)->format('Y-m');
            if ($monthly->has($key)) {
                $value = $monthly->get($key);
                $value['points'] += (int) $movement->points;
                $monthly->put($key, $value);
            }
        }

        return [
            'available_points' => (int) ($summary->available_points ?? 0),
            'distributors' => (int) ($summary->distributors ?? 0),
            'trend' => $monthly->values()->all(),
        ];
    }

    private function applications(?iterable $branchIds): array
    {
        $summaryQuery = DB::table('distributor_applications as a');
        $this->scopeToBranches($summaryQuery, 'a.branch_id', $branchIds);

        $summary = (clone $summaryQuery)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN a.status IN (?, ?, ?) THEN 1 ELSE 0 END) as pending',
                self::PENDING_APPLICATION_STATUSES,
            )
            ->selectRaw(
                'SUM(CASE WHEN a.status NOT IN (?, ?, ?) THEN 1 ELSE 0 END) as validated',
                self::PENDING_APPLICATION_STATUSES,
            )
            ->first();

        $rowsQuery = DB::table('distributor_applications as a')
            ->leftJoin('application_personal_data as pd', 'pd.application_id', '=', 'a.id')
            ->join('branches as br', 'br.id', '=', 'a.branch_id')
            ->select([
                'a.application_number',
                'a.status',
                'a.created_at',
                'br.name as branch_name',
            ])
            ->selectRaw(
                "TRIM(CONCAT(COALESCE(pd.first_name, ''), ' ', COALESCE(pd.first_last_name, ''), ' ', COALESCE(pd.second_last_name, ''))) as applicant_name",
            )
            ->orderByDesc('a.created_at')
            ->limit(5);
        $this->scopeToBranches($rowsQuery, 'a.branch_id', $branchIds);

        return [
            'total' => (int) ($summary->total ?? 0),
            'pending' => (int) ($summary->pending ?? 0),
            'validated' => (int) ($summary->validated ?? 0),
            'rows' => $rowsQuery->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function scopeToBranches(Builder $query, string $column, ?iterable $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn($column, $branchIds);
        }
    }
}
