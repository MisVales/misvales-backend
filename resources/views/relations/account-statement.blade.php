<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 36px; }
        * { box-sizing: border-box; }
        body { color: #17211b; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; }
        header { border-bottom: 3px solid #168a43; padding-bottom: 12px; }
        .logo { display: inline-block; width: 28%; vertical-align: top; }
        .logo img { height: 64px; }
        .identity { display: inline-block; width: 71%; text-align: right; vertical-align: top; }
        h1 { color: #087b36; font-size: 20px; margin: 0 0 5px; }
        h2 { color: #087b36; font-size: 12px; margin: 0; }
        p { margin: 2px 0; }
        .summary { background: #edf8f0; margin: 14px 0; padding: 10px 12px; }
        .metric { display: inline-block; width: 24.4%; }
        .metric span { color: #637068; display: block; }
        .metric strong { color: #173d28; font-size: 13px; }
        .relation { margin: 0 0 15px; page-break-inside: avoid; }
        .relation-head { border-left: 4px solid #168a43; background: #f1f6f3; padding: 7px 9px; }
        .relation-head .right { float: right; text-align: right; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th { background: #183e2a; color: white; font-size: 8px; padding: 5px 4px; text-align: left; }
        td { border-bottom: 1px solid #dce4df; padding: 5px 4px; vertical-align: top; }
        .installment { text-align: center; width: 8%; }
        .folio { width: 20%; }
        .client { width: 24%; }
        .status { width: 12%; }
        .money { text-align: right; white-space: nowrap; width: 12%; }
        .carry { color: #5d685f; padding: 5px 8px; text-align: right; }
        .empty { color: #657168; padding: 9px; }
        footer { color: #68736b; font-size: 8px; text-align: right; }
    </style>
</head>
<body>
<header>
    <div class="logo"><img src="{{ $logo }}" alt="MisVales"></div>
    <div class="identity">
        <h1>Estado de cuenta</h1>
        <p><strong>{{ $distributor->usuario?->name }}</strong></p>
        <p>{{ $distributor->distributor_number }} · {{ $distributor->sucursal?->name }}</p>
        <p>Generado {{ now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}</p>
    </div>
</header>
<section class="summary">
    <div class="metric"><span>Línea autorizada</span><strong>${{ number_format((float) $authorized, 2) }}</strong></div>
    <div class="metric"><span>Saldo utilizado</span><strong>${{ number_format((float) $used, 2) }}</strong></div>
    <div class="metric"><span>Disponible</span><strong>${{ number_format((float) $available, 2) }}</strong></div>
    <div class="metric"><span>Saldo exigible</span><strong>${{ number_format((float) $currentBalance, 2) }}</strong></div>
</section>

@forelse ($relations as $relation)
    <section class="relation">
        <div class="relation-head">
            <span class="right"><strong>Saldo al cierre: ${{ number_format((float) ($relation->financial_status === 'ROLLED_FORWARD' ? $relation->rolled_forward_amount : $relation->balance), 2) }}</strong><br>Vence {{ $relation->payment_deadline_at->format('d/m/Y') }}</span>
            <h2>{{ $relation->payment_reference }}</h2>
            <p>Corte {{ $relation->cutoff_at->format('d/m/Y') }} · {{ ['SETTLED' => 'Liquidada', 'PARTIALLY_PAID' => 'Con abonos', 'PENDING' => 'Pendiente', 'OVERDUE' => 'Vencida', 'ROLLED_FORWARD' => 'Adeudo trasladado al siguiente corte'][$relation->financial_status] ?? 'En seguimiento' }}</p>
        </div>
        @if ((float) $relation->carried_balance > 0)
            <div class="carry">Adeudo recibido de cortes anteriores: <strong>${{ number_format((float) $relation->carried_balance, 2) }}</strong></div>
        @endif
        <table>
            <thead><tr><th class="installment">Parc.</th><th class="folio">Vale</th><th class="client">Cliente</th><th class="status">Resultado</th><th class="money">Cobro</th><th class="money">Pagado</th><th class="money">Pendiente</th></tr></thead>
            <tbody>
            @forelse ($relation->statement_items as $item)
                <tr><td class="installment">{{ $item['installment'] }}</td><td>{{ $item['folio'] }}</td><td>{{ $item['client'] }}</td><td>{{ $item['status'] }}</td><td class="money">${{ number_format((float) $item['charge'], 2) }}</td><td class="money">${{ number_format((float) $item['paid'], 2) }}</td><td class="money">${{ number_format((float) $item['pending'], 2) }}</td></tr>
            @empty
                <tr><td class="empty" colspan="7">Este corte sólo contiene el adeudo acumulado; las parcialidades permanecen detalladas en el corte donde fueron incluidas originalmente.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
@empty
    <p class="empty">No hay relaciones registradas para esta distribuidora.</p>
@endforelse
<footer>MisVales · Resumen histórico de pagos y adeudos por corte</footer>
</body>
</html>
