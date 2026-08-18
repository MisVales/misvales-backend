<?php

namespace App\Services\Reportes;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ServicioReportes
{
    public const REPORTS = [
        'distributors', 'credit-lines', 'vouchers', 'relations', 'relation-balances',
        'payment-behavior', 'delinquent-distributors', 'risk-alerts', 'client-portfolio',
        'reconciled-payments', 'unreconciled-payments', 'manual-reconciliations',
        'surpluses', 'credit-balances', 'refunds',
        'distributor-applications', 'credit-increases', 'transfers', 'client-reassignments',
        'organizational-changes',
    ];

    public function ejecutar(string $report, array $filters, User $actor): LengthAwarePaginator
    {
        if (! in_array($report, self::REPORTS, true)) {
            throw new NotFoundHttpException('Reporte inexistente.');
        }
        [$query, $dateColumn, $branchColumn, $distributorColumn, $statusColumn] = $this->query($report);
        $this->scope($query, $actor, $filters, $branchColumn);
        if (! empty($filters['branch_id']) && $branchColumn) {
            $query->where($branchColumn, $filters['branch_id']);
        }
        if (! empty($filters['distributor_id']) && $distributorColumn) {
            $query->where($distributorColumn, $filters['distributor_id']);
        }
        if (! empty($filters['coordinator_id'])) {
            $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('coordinator_distributor_assignments as report_cda')->whereColumn('report_cda.distributor_id', $distributorColumn)->where('report_cda.coordinator_id', $filters['coordinator_id'])->where('report_cda.status', 'ACTIVE')->whereNull('report_cda.valid_to'));
        }
        if (! empty($filters['status']) && $statusColumn) {
            $query->where($statusColumn, $filters['status']);
        }
        if (! empty($filters['date_from']) && $dateColumn) {
            $query->where($dateColumn, '>=', $filters['date_from'].' 00:00:00');
        }
        if (! empty($filters['date_to']) && $dateColumn) {
            $query->where($dateColumn, '<=', $filters['date_to'].' 23:59:59');
        }

        return $query->orderByDesc($dateColumn ?? $this->fallbackOrder($report))->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }

    private function query(string $report): array
    {
        return match ($report) {
            'distributors' => [$this->distributors(), 'd.created_at', 'd.branch_id', 'd.id', 'd.status'],
            'credit-lines' => [$this->creditLines(), 'cl.updated_at', 'd.branch_id', 'cl.distributor_id', 'cl.status'],
            'vouchers' => [DB::table('vouchers as v')->select('v.id', 'v.folio', 'v.type', 'v.status', 'v.distributor_id', 'v.branch_id', 'v.capital', 'v.misvales_total', 'v.generated_at', 'v.cashed_at'), 'v.generated_at', 'v.branch_id', 'v.distributor_id', 'v.status'],
            'relations', 'relation-balances' => [DB::table('distributor_relations as r')->select('r.id', 'r.distributor_id', 'r.branch_id', 'r.cutoff_at', 'r.payment_deadline_at', 'r.portfolio_total', 'r.misvales_total', 'r.balance', 'r.financial_status', 'r.review_status'), 'r.cutoff_at', 'r.branch_id', 'r.distributor_id', 'r.financial_status'],
            'payment-behavior' => [$this->paymentBehavior(), 'r.cutoff_at', 'r.branch_id', 'r.distributor_id', null],
            'delinquent-distributors' => [DB::table('distributor_operational_blocks as b')->join('distributors as d', 'd.id', '=', 'b.distributor_id')->select('b.id', 'b.distributor_id', 'd.branch_id', 'b.reason', 'b.starts_at', 'b.ends_at', 'b.status')->where('b.type', 'DELINQUENCY'), 'b.starts_at', 'd.branch_id', 'b.distributor_id', 'b.status'],
            'risk-alerts' => [DB::table('distributor_risk_alerts as a')->select('a.id', 'a.distributor_id', 'a.branch_id', 'a.status', 'a.consecutive_defaults', 'a.overdue_balance', 'a.relation_ids', 'a.detected_at'), 'a.detected_at', 'a.branch_id', 'a.distributor_id', 'a.status'],
            'client-portfolio' => [DB::table('client_portfolio_entries as p')->join('client_distributor_assignments as ca', function ($join): void {
                $join->on('ca.client_id', '=', 'p.client_id')->on('ca.distributor_id', '=', 'p.distributor_id')->whereColumn('ca.starts_at', '<=', 'p.occurred_at')->where(fn ($period) => $period->whereNull('ca.ends_at')->orWhereColumn('ca.ends_at', '>', 'p.occurred_at'));
            })->select('p.id', 'p.client_id', 'p.distributor_id', 'ca.branch_id', 'p.entry_type', 'p.amount', 'p.informational_status', 'p.occurred_at', 'p.due_date'), 'p.occurred_at', 'ca.branch_id', 'p.distributor_id', 'p.informational_status'],
            'reconciled-payments' => [$this->bankMovements(true), 'm.created_at', 'i.branch_id', 'r.distributor_id', 'm.classification'],
            'unreconciled-payments' => [$this->bankMovements(false), 'm.created_at', 'i.branch_id', 'r.distributor_id', 'm.classification'],
            'manual-reconciliations' => [DB::table('manual_reconciliation_requests as m')->select('m.id', 'm.branch_id', 'm.bank_movement_id', 'm.relation_id', 'm.status', 'm.reason', 'm.created_at', 'm.authorized_at', 'm.executed_at'), 'm.created_at', 'm.branch_id', null, 'm.status'],
            'surpluses', 'credit-balances' => [DB::table('distributor_surpluses as s')->join('distributors as d', 'd.id', '=', 's.distributor_id')->select('s.id', 's.distributor_id', 'd.branch_id', 's.original_amount', 's.available_amount', 's.reserved_amount', 's.status', 's.created_at'), 's.created_at', 'd.branch_id', 's.distributor_id', 's.status'],
            'refunds' => [DB::table('surplus_refund_requests as r')->join('distributor_surpluses as s', 's.id', '=', 'r.surplus_id')->select('r.id', 's.distributor_id', 'r.branch_id', 'r.amount', 'r.status', 'r.decision_reason', 'r.created_at', 'r.executed_at'), 'r.created_at', 'r.branch_id', 's.distributor_id', 'r.status'],
            'distributor-applications' => [DB::table('distributor_applications as a')->select('a.id', 'a.application_number', 'a.branch_id', 'a.status', 'a.created_at', 'a.submitted_at'), 'a.created_at', 'a.branch_id', null, 'a.status'],
            'credit-increases' => [DB::table('credit_increase_requests as c')->join('distributors as d', 'd.id', '=', 'c.distributor_id')->select('c.id', 'c.distributor_id', 'd.branch_id', 'c.status', 'c.requested_amount', 'c.authorized_amount', 'c.requested_at', 'c.decided_at'), 'c.requested_at', 'd.branch_id', 'c.distributor_id', 'c.status'],
            'transfers' => [DB::table('client_transfer_requests as t')->select('t.id', 't.client_id', 't.origin_distributor_id', 't.destination_distributor_id', 't.origin_branch_id', 't.destination_branch_id', 't.status', 't.created_at', 't.completed_at'), 't.created_at', 't.origin_branch_id', 't.origin_distributor_id', 't.status'],
            'client-reassignments' => [$this->changes('CLIENT_ADMIN_REASSIGNMENT'), 'o.occurred_at', 'o.origin_branch_id', null, null],
            'organizational-changes' => [$this->changes(), 'o.occurred_at', 'o.origin_branch_id', null, 'o.type'],
        };
    }

    private function distributors(): Builder
    {
        return DB::table('distributors as d')->leftJoin('coordinator_distributor_assignments as c', fn ($join) => $join->on('c.distributor_id', '=', 'd.id')->where('c.status', 'ACTIVE')->whereNull('c.valid_to'))->select('d.id', 'd.distributor_number', 'd.branch_id', 'd.status', 'c.coordinator_id', 'd.activated_at', 'd.created_at');
    }

    private function creditLines(): Builder
    {
        return DB::table('credit_lines as cl')->join('distributors as d', 'd.id', '=', 'cl.distributor_id')->select('cl.id', 'cl.distributor_id', 'd.branch_id', 'cl.status', 'cl.total_authorized', 'cl.used_balance', DB::raw('(cl.total_authorized - cl.used_balance) AS available_balance'), DB::raw("COALESCE((SELECT SUM(amount) FROM credit_line_movements m WHERE m.credit_line_id = cl.id AND m.type = 'PAYMENT_RECOVERY'), 0) AS recovered_balance"), 'cl.updated_at');
    }

    private function paymentBehavior(): Builder
    {
        return DB::table('distributor_relations as r')->select('r.id', 'r.distributor_id', 'r.branch_id', 'r.cutoff_at', 'r.misvales_total', 'r.balance', DB::raw("CASE WHEN r.balance = 0 THEN 'SETTLED' WHEN r.balance < r.misvales_total THEN 'PARTIAL' ELSE 'UNPAID' END AS payment_behavior"));
    }

    private function bankMovements(bool $reconciled): Builder
    {
        $query = DB::table('bank_movements as m')->join('bank_file_imports as i', 'i.id', '=', 'm.import_id')->leftJoin('distributor_relations as r', 'r.id', '=', 'm.relation_id')->select('m.id', 'i.branch_id', 'r.distributor_id', 'm.relation_id', 'm.amount', 'm.applied_amount', 'm.surplus_amount', 'm.classification', 'm.bank_folio', 'm.created_at');

        return $reconciled ? $query->whereIn('m.classification', ['PARTIAL_PAYMENT', 'SETTLEMENT', 'SURPLUS']) : $query->whereIn('m.classification', ['UNRECONCILED', 'DUPLICATE', 'ERROR']);
    }

    private function changes(?string $type = null): Builder
    {
        $query = DB::table('organizational_change_events as o')->select('o.id', 'o.type', 'o.subject_id', 'o.origin_branch_id', 'o.destination_branch_id', 'o.actor_id', 'o.reason', 'o.before_snapshot', 'o.after_snapshot', 'o.occurred_at');

        return $type ? $query->where('o.type', $type) : $query;
    }

    private function scope(Builder $query, User $actor, array $filters, ?string $branchColumn): void
    {
        abort_unless($actor->hasPermissionTo('reports.view_global') || $actor->hasPermissionTo('reports.view_branch'), 403);
        if (! $actor->hasPermissionTo('reports.view_global')) {
            abort_unless($branchColumn, 403);
            $branches = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->whereNotNull('branch_id')->pluck('branch_id');
            $query->whereIn($branchColumn, $branches);
        }
    }

    private function fallbackOrder(string $report): string
    {
        return $report === 'distributors' ? 'd.created_at' : 'id';
    }
}
