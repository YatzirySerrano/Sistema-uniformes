<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contexto->titulo }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; box-sizing: border-box; }
        body { font-size: 9px; color: #1e293b; margin: 0; }
        .encabezado { width: 100%; border-bottom: 2px solid #1e293b; padding-bottom: 8px; margin-bottom: 8px; }
        .encabezado table { width: 100%; border-collapse: collapse; }
        .encabezado td { border: none; padding: 0; vertical-align: middle; }
        .logo img { max-height: 42px; max-width: 140px; }
        h1 { font-size: 16px; margin: 0 0 2px; color: #0f172a; }
        .empresa { font-size: 11px; font-weight: bold; color: #334155; margin: 0 0 4px; }
        .metadata { font-size: 8.5px; color: #475569; }
        .metadata span { margin-right: 14px; }
        .metadata b { color: #1e293b; }
        table.datos { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.datos th, table.datos td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        table.datos th { background: #1e293b; color: #ffffff; font-size: 9px; }
        table.datos tbody tr:nth-child(even) { background: #f8fafc; }
        td.num { text-align: right; }
        .bajo { color: #b91c1c; font-weight: bold; }
        .pie { position: fixed; bottom: -18px; left: 0; right: 0; font-size: 8px; color: #94a3b8; text-align: right; }
        .pie:after { content: "Sistema de Uniformes · página " counter(page) " de " counter(pages); }
        .vacio { padding: 10px 0; color: #64748b; font-style: italic; }
    </style>
</head>
<body>
    <div class="encabezado">
        <table>
            <tr>
                @if ($contexto->logoBase64())
                    <td class="logo" style="width: 150px;">
                        <img src="{{ $contexto->logoBase64() }}" alt="Logotipo">
                    </td>
                @endif
                <td>
                    <p class="empresa">{{ $contexto->nombreEmpresa() }}</p>
                    <h1>{{ $contexto->titulo }}</h1>
                    <div class="metadata">
                        @foreach ($contexto->filtros as $etiqueta => $valor)
                            <span><b>{{ $etiqueta }}:</b> {{ $valor }}</span>
                        @endforeach
                        <span><b>Generado:</b> {{ $contexto->generadoEnLocal() }}</span>
                        <span><b>Registros:</b> {{ $contexto->total }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if ($saldos->isEmpty())
        <p class="vacio">No hay existencias que coincidan con los filtros aplicados.</p>
    @else
        <table class="datos">
            <thead>
                <tr>
                    <th>Empresa</th><th>Almacén</th><th>Activo</th><th>Variante</th>
                    <th>Existencia</th><th>Mínimo</th><th>Bajo mínimo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($saldos as $saldo)
                    <tr>
                        <td>{{ $saldo->empresa?->nombre_comercial }}</td>
                        <td>{{ $saldo->almacen?->nombre }}</td>
                        <td>{{ $saldo->activo?->nombre }}</td>
                        <td>{{ $saldo->talla?->valor ?? '—' }}</td>
                        <td class="num">{{ $saldo->cantidad }}</td>
                        <td class="num">{{ $saldo->minimo }}</td>
                        <td class="{{ $saldo->estaBajoMinimo() ? 'bajo' : '' }}">
                            {{ $saldo->estaBajoMinimo() ? 'Sí' : 'No' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="pie"></div>
</body>
</html>
