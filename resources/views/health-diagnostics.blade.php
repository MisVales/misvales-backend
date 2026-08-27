<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MisVales API · Servicio disponible</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; background: #f5f8f5; color: #17231a; }
        main { width: min(92%, 760px); margin: 8vh auto; }
        .card { overflow: hidden; border: 1px solid #dce7de; border-radius: 18px; background: #fff; box-shadow: 0 18px 50px rgb(20 55 31 / 10%); }
        header { padding: 28px; background: #244b30; color: #fff; }
        h1 { margin: 0 0 6px; font-size: 1.55rem; }
        header p { margin: 0; color: #d9eadc; }
        .status { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 14px; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .dot { width: 9px; height: 9px; border-radius: 999px; background: #86efac; box-shadow: 0 0 0 5px rgb(134 239 172 / 16%); }
        section { padding: 26px 28px 30px; }
        h2 { margin: 0 0 16px; font-size: 1rem; }
        dl { display: grid; grid-template-columns: minmax(180px, .7fr) minmax(0, 1.3fr); margin: 0; border: 1px solid #e3ebe5; border-radius: 12px; }
        dt, dd { margin: 0; padding: 12px 14px; border-bottom: 1px solid #e3ebe5; overflow-wrap: anywhere; }
        dt { background: #f7faf8; color: #526157; font-size: .8rem; font-weight: 700; }
        dd { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; }
        dt:last-of-type, dd:last-of-type { border-bottom: 0; }
        footer { margin-top: 14px; color: #68756c; font-size: .75rem; }
        @media (max-width: 600px) { dl { grid-template-columns: 1fr; } dt { border-bottom: 0; padding-bottom: 3px; } dd { padding-top: 3px; } }
    </style>
</head>
<body>
<main>
    <article class="card">
        <header>
            <div class="status"><span class="dot"></span> Operativo</div>
            <h1>MisVales API está disponible</h1>
            <p>Diagnóstico básico de esta solicitud.</p>
        </header>
        <section>
            <h2>Información observada por el servidor</h2>
            <dl>
                @foreach ($diagnostics as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ filled($value) ? $value : 'No enviado' }}</dd>
                @endforeach
            </dl>
            <footer>No se muestran cookies, credenciales ni encabezados de autorización.</footer>
        </section>
    </article>
</main>
</body>
</html>
