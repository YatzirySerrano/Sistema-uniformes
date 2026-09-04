<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #1e293b; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        th { background: #f1f5f9; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <div class="muted">Generado el {{ now()->format('d/m/Y H:i') }} &middot; {{ count($filas) }} registro(s)</div>

    <table>
        <thead>
            <tr>
                @foreach ($encabezados as $encabezado)
                    <th>{{ $encabezado }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    @foreach ($fila as $valor)
                        <td>{{ $valor }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
