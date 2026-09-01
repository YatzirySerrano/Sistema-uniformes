<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de entregas</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #1e293b; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        th { background: #f1f5f9; }
    </style>
</head>
<body>
    <h1>{{ $empresa->nombre_comercial }} — Reporte de entregas de uniformes</h1>
    <div class="muted">Generado el {{ now()->format('d/m/Y H:i') }}</div>

    <table>
        <thead>
            <tr>
                <th>Folio</th><th>Fecha</th><th>Sucursal</th><th>N.º empleado</th>
                <th>Colaborador</th><th>Responsable</th><th>Estado</th><th>Prenda</th><th>Talla</th><th>Cant.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entregas as $entrega)
                @foreach ($entrega->detalles as $detalle)
                    <tr>
                        <td>{{ $entrega->folio }}</td>
                        <td>{{ $entrega->fecha_entrega->format('d/m/Y') }}</td>
                        <td>{{ $entrega->sucursal?->nombre }}</td>
                        <td>{{ $entrega->colaborador?->numero_empleado }}</td>
                        <td>{{ $entrega->colaborador?->nombre_completo }}</td>
                        <td>{{ $entrega->encargado?->name }}</td>
                        <td>{{ $entrega->estado->etiqueta() }}</td>
                        <td>{{ $detalle->prenda_nombre_snapshot }}</td>
                        <td>{{ $detalle->talla_valor_snapshot }}</td>
                        <td>{{ $detalle->cantidad }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</body>
</html>
