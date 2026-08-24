<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 34px; }
        * { box-sizing: border-box; }
        body { color: #17211b; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.35; }
        .header { border-bottom: 3px solid #189447; padding-bottom: 14px; }
        .logo { display: inline-block; vertical-align: top; width: 31%; }
        .logo img { height: 82px; width: auto; }
        .identity { display: inline-block; text-align: right; vertical-align: top; width: 68%; }
        .identity h1 { color: #087b36; font-size: 20px; margin: 0 0 5px; }
        .identity p { margin: 2px 0; }
        .label { color: #5b675f; font-weight: normal; }
        .payment-band { background: #edf8f0; border-left: 5px solid #189447; margin: 18px 0 14px; padding: 11px 14px; }
        .payment-band .left { display: inline-block; vertical-align: top; width: 67%; }
        .payment-band .right { display: inline-block; text-align: right; vertical-align: top; width: 32%; }
        .payment-band .amount { color: #087b36; font-size: 19px; font-weight: bold; }
        .payment-band p { margin: 1px 0; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th { background: #183e2a; color: #fff; font-size: 8.5px; padding: 7px 5px; text-align: left; }
        td { border-bottom: 1px solid #dce4df; padding: 7px 5px; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f7faf8; }
        .number { text-align: center; width: 4%; }
        .product { width: 16%; }
        .client { width: 25%; }
        .payments { text-align: center; width: 12%; }
        .money { text-align: right; width: 10.75%; white-space: nowrap; }
        tfoot td { background: #edf8f0; border-bottom: 0; border-top: 2px solid #189447; font-weight: bold; }
        .bank { border-top: 1px solid #dce4df; margin-top: 18px; padding-top: 12px; }
        .bank h2 { color: #087b36; font-size: 12px; margin: 0 0 5px; }
        .bank p { margin: 2px 0; }
        .footer { color: #6b756e; font-size: 8px; margin-top: 14px; text-align: right; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><img src="{{ $logo }}" alt="MisVales"></div>
        <div class="identity">
            <h1>Relación de pago</h1>
            <p><strong>Número de distribuidora:</strong> {{ $relation->header_snapshot['number'] ?? '—' }}</p>
            <p><strong>Nombre:</strong> {{ $relation->header_snapshot['name'] ?? '—' }}</p>
            <p><strong>Domicilio:</strong> {{ $relation->header_snapshot['address'] ?? '—' }}</p>
            <p><strong>Límite de crédito:</strong> ${{ number_format((float) ($relation->header_snapshot['credit_line_total'] ?? 0), 2) }}</p>
            <p><strong>Crédito disponible:</strong> ${{ number_format((float) ($relation->header_snapshot['credit_available'] ?? 0), 2) }}</p>
        </div>
    </header>

    <section class="payment-band">
        <div class="left">
            <p><strong>Referencia de pago:</strong> {{ $relation->payment_reference }}</p>
            <p><strong>Fecha límite:</strong> {{ $relation->payment_deadline_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</p>
            <p><strong>Pago anticipado:</strong> {{ $relation->advance_period_start->format('d/m/Y') }} al {{ $relation->advance_period_end->format('d/m/Y') }}</p>
        </div>
        <div class="right">
            <span class="label">Total a pagar</span><br>
            <span class="amount">${{ number_format((float) $totals['total'], 2) }}</span><br>
            <span class="label">Saldo pendiente: ${{ number_format((float) $relation->balance, 2) }}</span>
        </div>
    </section>

    <table>
        <thead>
            <tr>
                <th class="number">#</th>
                <th class="product">Producto</th>
                <th class="client">Cliente</th>
                <th class="payments">Pagos realizados</th>
                <th class="money">Comisión</th>
                <th class="money">Pago</th>
                <th class="money">Recargos</th>
                <th class="money">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    <td class="number">{{ $index + 1 }}</td>
                    <td class="product">{{ $row['product'] }}</td>
                    <td class="client">{{ $row['client'] }}</td>
                    <td class="payments">{{ $row['payments_made'] }}</td>
                    <td class="money">${{ number_format((float) $row['commission'], 2) }}</td>
                    <td class="money">${{ number_format((float) $row['payment'], 2) }}</td>
                    <td class="money">${{ number_format((float) $row['surcharge'], 2) }}</td>
                    <td class="money">${{ number_format((float) $row['total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="8">Esta relación no contiene partidas.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right">Totales</td>
                <td class="money">${{ number_format((float) $totals['commission'], 2) }}</td>
                <td class="money">${{ number_format((float) $totals['payment'], 2) }}</td>
                <td class="money">${{ number_format((float) $totals['surcharge'], 2) }}</td>
                <td class="money">${{ number_format((float) $totals['total'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <section class="bank">
        <h2>Datos para realizar el pago</h2>
        <p><strong>Beneficiario:</strong> {{ $relation->bank_snapshot['beneficiary'] ?? '—' }}</p>
        <p><strong>Banco:</strong> {{ $relation->bank_snapshot['name'] ?? '—' }} &nbsp; <strong>Convenio:</strong> {{ $relation->bank_snapshot['agreement'] ?? '—' }}</p>
        <p><strong>CLABE:</strong> {{ $relation->bank_snapshot['clabe'] ?? '—' }}</p>
    </section>
    <p class="footer">Documento generado por MisVales · {{ now()->format('d/m/Y H:i') }}</p>
</body>
</html>
