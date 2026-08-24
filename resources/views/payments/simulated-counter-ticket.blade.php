<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 13px 16px; }
        * { box-sizing: border-box; }
        body { color: #142219; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; margin: 0; text-align: center; }
        .logo { height: 48px; margin: 0 auto 5px; width: auto; }
        h1 { color: #087b36; font-size: 14px; margin: 2px 0; }
        .simulated { border: 2px solid #9a3412; color: #9a3412; font-size: 9px; font-weight: bold; margin: 8px 0; padding: 5px; text-transform: uppercase; }
        .amount { border-bottom: 1px dashed #849188; border-top: 1px dashed #849188; margin: 9px 0; padding: 8px 0; }
        .amount span { color: #087b36; display: block; font-size: 22px; font-weight: bold; }
        dl { margin: 0; text-align: left; }
        .row { border-bottom: 1px solid #e1e7e3; padding: 5px 0; }
        dt { color: #657168; display: inline-block; vertical-align: top; width: 38%; }
        dd { display: inline-block; font-weight: bold; margin: 0; overflow-wrap: anywhere; text-align: right; vertical-align: top; width: 60%; }
        .instructions { background: #edf8f0; border-left: 3px solid #189447; margin: 9px 0; padding: 7px; text-align: left; }
        .footer { color: #657168; font-size: 7px; margin-top: 9px; }
    </style>
</head>
<body>
    <img class="logo" src="{{ $logo }}" alt="MisVales">
    <h1>Ticket de pago en ventanilla</h1>
    <div class="simulated">Comprobante simulado - Sin valor bancario</div>

    <div class="amount">Monto registrado<span>${{ number_format((float) $transfer->amount, 2) }}</span></div>

    <dl>
        <div class="row"><dt>Folio</dt><dd>{{ $transfer->bank_folio }}</dd></div>
        <div class="row"><dt>Referencia</dt><dd>{{ $transfer->payment_reference }}</dd></div>
        <div class="row"><dt>Distribuidora</dt><dd>{{ $relation->header_snapshot['name'] ?? $relation->distribuidora?->usuario?->name ?? '—' }}</dd></div>
        <div class="row"><dt>Fecha y hora</dt><dd>{{ $transfer->paid_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</dd></div>
        <div class="row"><dt>Forma de pago</dt><dd>Efectivo en ventanilla</dd></div>
    </dl>

    <div class="instructions">
        Pago registrado en Caja. Conserva este ticket para cualquier aclaración.
    </div>

    <p class="footer">Generado por MisVales. Este documento pertenece al entorno de simulación y no acredita un depósito real.</p>
</body>
</html>
