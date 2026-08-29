<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 28px 32px; }
        * { box-sizing: border-box; }
        body { color: #17211b; font-family: "DejaVu Sans", sans-serif; font-size: 8px; line-height: 1.3; }
        header { border-bottom: 3px solid #168a43; padding-bottom: 9px; }
        .logo, .identity { display: inline-block; vertical-align: top; }
        .logo { width: 27%; }
        .logo img { height: 54px; }
        .identity { text-align: right; width: 72%; }
        h1 { color: #087b36; font-size: 20px; margin: 0 0 3px; }
        h2, h3, p { margin: 0; }
        .metadata, .summary, .cut-summary { width: 100%; }
        .metadata { background: #f3f7f4; margin: 10px 0 7px; padding: 7px 9px; }
        .metadata-cell { display: inline-block; vertical-align: top; width: 24.5%; }
        .label { color: #69756d; display: block; font-size: 7px; text-transform: uppercase; }
        .value { color: #173d28; font-weight: bold; }
        .summary { background: #e9f6ed; margin-bottom: 14px; padding: 8px 9px; }
        .summary-cell { display: inline-block; vertical-align: top; width: 24.5%; }
        .summary-cell strong { color: #0b6831; display: block; font-size: 13px; }
        .cut { border-top: 2px solid #168a43; margin: 0 0 18px; padding-top: 7px; }
        .cut-head { background: #183e2a; color: white; padding: 7px 9px; page-break-after: avoid; }
        .cut-head h2 { display: inline-block; font-size: 13px; width: 39%; }
        .cut-meta { display: inline-block; text-align: right; vertical-align: top; width: 59%; }
        .client-block { border: 1px solid #d6e1da; margin-top: 8px; }
        .client-head { background: #edf5f0; border-left: 4px solid #168a43; page-break-after: avoid; padding: 5px 7px; }
        .client-head h3 { color: #125f31; font-size: 10px; }
        .client-head span { color: #59665e; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        thead { display: table-header-group; }
        th { background: #315743; color: white; font-size: 6.8px; padding: 4px 3px; text-align: left; }
        td { border-bottom: 1px solid #dce4df; padding: 4px 3px; vertical-align: top; word-wrap: break-word; }
        .center { text-align: center; }
        .money { text-align: right; white-space: nowrap; }
        .w-number { width: 3%; } .w-client { width: 12%; } .w-folio { width: 12%; } .w-product { width: 12%; }
        .w-part { width: 7%; } .w-status { width: 8%; } .w-money { width: 9%; } .w-total { width: 10%; }
        .client-subtotal { background: #f7faf8; page-break-inside: avoid; padding: 5px 7px; text-align: right; }
        .client-subtotal span { display: inline-block; margin-left: 15px; }
        .cut-summary { background: #e5f0e9; border: 1px solid #bed3c5; margin-top: 8px; padding: 7px 9px; page-break-inside: avoid; }
        .cut-summary h3 { color: #125f31; font-size: 10px; margin-bottom: 4px; }
        .total-cell { display: inline-block; text-align: right; vertical-align: top; width: 19.5%; }
        .cut-summary .total-cell { width: 16.3%; }
        .total-cell strong { display: block; font-size: 10px; }
        .empty { color: #67736b; padding: 10px; text-align: center; }
        footer { bottom: -20px; color: #6a756e; font-size: 7px; position: fixed; text-align: right; width: 100%; }
    </style>
</head>
<body>
<header>
    <div class="logo"><img src="{{ $logo }}" alt="MisVales"></div>
    <div class="identity">
        <h1>Estado de cuenta</h1>
        <p><strong>{{ $statement['distributor']['name'] }}</strong> · {{ $statement['distributor']['number'] }}</p>
        <p>Generado {{ now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}</p>
    </div>
</header>

<section class="metadata">
    <div class="metadata-cell"><span class="label">Distribuidora</span><span class="value">{{ $statement['distributor']['name'] }}</span></div>
    <div class="metadata-cell"><span class="label">Sucursal</span><span class="value">{{ $statement['distributor']['branch'] }}</span></div>
    <div class="metadata-cell"><span class="label">Coordinador</span><span class="value">{{ $statement['distributor']['coordinator'] }}</span></div>
    <div class="metadata-cell"><span class="label">Relación / referencia</span><span class="value">{{ $statement['latest']['reference'] ?? 'Sin relación' }}</span></div>
</section>
<section class="metadata">
    <div class="metadata-cell"><span class="label">Fecha de corte</span><span class="value">{{ $statement['latest'] ? $statement['latest']['cutoff_at']->format('d/m/Y') : '—' }}</span></div>
    <div class="metadata-cell"><span class="label">Fecha límite</span><span class="value">{{ $statement['latest'] ? $statement['latest']['deadline_at']->format('d/m/Y') : '—' }}</span></div>
    <div class="metadata-cell"><span class="label">Estado</span><span class="value">{{ $statement['latest']['status'] ?? 'Sin relación' }}</span></div>
</section>
<section class="summary">
    <div class="summary-cell"><span class="label">Línea autorizada</span><strong>${{ number_format((float) $statement['credit']['authorized'], 2) }}</strong></div>
    <div class="summary-cell"><span class="label">Saldo utilizado de línea</span><strong>${{ number_format((float) $statement['credit']['used'], 2) }}</strong></div>
    <div class="summary-cell"><span class="label">Crédito disponible</span><strong>${{ number_format((float) $statement['credit']['available'], 2) }}</strong></div>
    <div class="summary-cell"><span class="label">Deuda a MisVales</span><strong>${{ number_format((float) $statement['general']['outstanding'], 2) }}</strong></div>
</section>

@forelse ($statement['cuts'] as $cut)
    <section class="cut">
        <div class="cut-head">
            <h2>CORTE {{ $cut['number'] }}{{ $loop->last ? ' · RESUMEN GENERAL' : '' }}</h2>
            <div class="cut-meta">
                {{ $cut['reference'] }} · Corte: {{ $cut['cutoff_at']->format('d/m/Y') }} · Límite: {{ $cut['deadline_at']->format('d/m/Y') }} · {{ $cut['status'] }}
            </div>
        </div>

        @forelse ($cut['clients'] as $client)
            <div class="client-block">
                <div class="client-head">
                    <h3>Cliente: {{ $client['client'] }}</h3>
                    <span>Vales y movimientos incluidos en este corte</span>
                </div>
                <table>
                    <thead><tr>
                        <th class="w-number center">#</th><th class="w-client">Cliente</th><th class="w-folio">Folio vale</th>
                        <th class="w-product">Producto</th><th class="w-part center">Parcialidad</th><th class="w-status">Estado</th>
                        <th class="w-money money">Cobro cliente</th><th class="w-money money">Comisión distrib.</th>
                        <th class="w-money money">Pago MisVales</th><th class="w-money money">Recargo</th><th class="w-total money">Acumulado vale</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($client['rows'] as $index => $row)
                        <tr>
                            <td class="center">{{ $index + 1 }}</td><td>{{ $row['client'] }}</td><td>{{ $row['folio'] }}</td><td>{{ $row['product'] }}</td>
                            <td class="center"><strong>{{ $row['installment'] }}</strong></td><td>{{ $row['status'] }}</td>
                            <td class="money">${{ number_format((float) $row['client_collection'], 2) }}</td>
                            <td class="money">${{ number_format((float) $row['commission'], 2) }}<br><span class="label">{{ number_format((float) $row['commission_percentage'] * 100, 2) }}%</span></td>
                            <td class="money">${{ number_format((float) $row['misvales_payment'], 2) }}</td>
                            <td class="money">${{ number_format((float) $row['surcharge'], 2) }}</td>
                            <td class="money"><strong>${{ number_format((float) $row['cumulative_total'], 2) }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="client-subtotal">
                    <strong>Subtotal cliente {{ $client['client'] }}</strong>
                    <span>Cobro: ${{ number_format((float) $client['subtotal']['client_collection'], 2) }}</span>
                    <span>Comisión distrib.: ${{ number_format((float) $client['subtotal']['commission'], 2) }}</span>
                    <span>MisVales: ${{ number_format((float) $client['subtotal']['misvales_payment'], 2) }}</span>
                    <span>Recargo: ${{ number_format((float) $client['subtotal']['surcharge'], 2) }}</span>
                    <span>Abonado: ${{ number_format((float) $client['subtotal']['paid'], 2) }}</span>
                    <span><strong>Total a pagar a MisVales: ${{ number_format((float) $client['subtotal']['total'], 2) }}</strong></span>
                </div>
            </div>
        @empty
            <p class="empty">Este corte no contiene nuevas partidas de vale.</p>
        @endforelse

        <div class="cut-summary">
            <h3>Subtotal corte {{ $cut['number'] }}</h3>
            <div class="total-cell"><span class="label">Cobro a clientes</span><strong>${{ number_format((float) $cut['subtotal']['client_collection'], 2) }}</strong></div>
            <div class="total-cell"><span class="label">Comisión distribuidora</span><strong>${{ number_format((float) $cut['subtotal']['commission'], 2) }}</strong></div>
            <div class="total-cell"><span class="label">Pago a MisVales</span><strong>${{ number_format((float) $cut['subtotal']['misvales_payment'], 2) }}</strong></div>
            <div class="total-cell"><span class="label">Recargos incluidos</span><strong>${{ number_format((float) $cut['subtotal']['surcharge'], 2) }}</strong></div>
            <div class="total-cell"><span class="label">Abonado</span><strong>${{ number_format((float) $cut['subtotal']['paid'], 2) }}</strong></div>
            <div class="total-cell"><span class="label">Total definitivo MisVales</span><strong>${{ number_format((float) $cut['subtotal']['total'], 2) }}</strong></div>
        </div>
    </section>
@empty
    <p class="empty">No hay relaciones registradas para esta distribuidora.</p>
@endforelse

<footer>MisVales · Estado de cuenta agrupado por corte y cliente</footer>
</body>
</html>
