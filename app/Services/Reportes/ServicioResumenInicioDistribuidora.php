<?php

namespace App\Services\Reportes;

use App\Models\Distribuidora;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ServicioResumenInicioDistribuidora
{
    public function obtener(User $actor): array
    {
        abort_unless($actor->hasRole('distributor') && $actor->hasPermissionTo('clients.view_portfolio'), 403);
        $distribuidora = Distribuidora::query()
            ->where('user_id', $actor->id)
            ->where('status', 'ACTIVE')
            ->firstOrFail();
        $periodStart = CarbonImmutable::now()->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();

        $clientBalances = DB::table('client_portfolio_entries')
            ->where('distributor_id', $distribuidora->id)
            ->groupBy('client_id')
            ->select('client_id')
            ->selectRaw("GREATEST(SUM(CASE WHEN entry_type IN ('DEBT','ADJUSTMENT_INCREASE') THEN amount WHEN entry_type IN ('PAYMENT','PARTIAL_PAYMENT','ADJUSTMENT_DECREASE') THEN -amount ELSE 0 END), 0) as balance");
        $portfolio = DB::query()->fromSub($clientBalances, 'balances')
            ->selectRaw('COALESCE(SUM(balance), 0) as total')
            ->selectRaw('SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) as clients_with_balance')
            ->first();

        $overdueEntries = DB::table('client_portfolio_entries')
            ->where('distributor_id', $distribuidora->id)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereIn('informational_status', ['PENDING', 'PARTIALLY_PAID'])
            ->count();

        $profit = DB::table('vouchers')
            ->where('distributor_id', $distribuidora->id)
            ->whereBetween('generated_at', [$periodStart, $periodEnd])
            ->sum('distributor_profit_total');
        $payments = DB::table('relation_payments as p')
            ->join('distributor_relations as r', 'r.id', '=', 'p.relation_id')
            ->where('r.distributor_id', $distribuidora->id)
            ->whereBetween('p.applied_at', [$periodStart, $periodEnd]);

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'portfolio' => [
                'total_to_collect' => bcadd('0', (string) ($portfolio->total ?? '0'), 4),
                'clients_with_balance' => (int) ($portfolio->clients_with_balance ?? 0),
                'overdue_entries' => $overdueEntries,
            ],
            'period' => [
                'distributor_profit' => bcadd('0', (string) ($profit ?? '0'), 4),
                'paid_to_misvales' => bcadd('0', (string) ((clone $payments)->sum('p.amount') ?? '0'), 4),
                'capital_recovered' => bcadd('0', (string) ($payments->sum('p.line_recovered') ?? '0'), 4),
            ],
        ];
    }
}
