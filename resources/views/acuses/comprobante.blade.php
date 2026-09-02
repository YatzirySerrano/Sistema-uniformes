<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acuse de recepción {{ $acuse->folio }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        .encabezado { border-bottom: 3px solid {{ $snapshot['empresa']['color_principal'] ?? '#2563eb' }}; padding-bottom: 12px; margin-bottom: 16px; }
        .encabezado table { width: 100%; }
        .logo { max-height: 64px; max-width: 180px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; color: {{ $snapshot['empresa']['color_principal'] ?? '#2563eb' }}; }
        .muted { color: #64748b; }
        .caja { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        table.datos td { padding: 2px 6px; vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th, table.items td { border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left; }
        table.items th { background: #f1f5f9; }
        .firma-caja { margin-top: 24px; }
        .firma-img { border: 1px solid #cbd5e1; height: 120px; width: 320px; object-fit: contain; }
        .pie { margin-top: 28px; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8px; }
        .folio { font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="encabezado">
        <table>
            <tr>
                <td style="width:60%">
                    @if ($logoDataUri)
                        <img class="logo" src="{{ $logoDataUri }}" alt="Logotipo">
                    @endif
                    <h1>{{ $snapshot['empresa']['nombre_comercial'] ?? 'Empresa' }}</h1>
                    <div class="muted">{{ $snapshot['empresa']['razon_social'] ?? '' }}</div>
                    <div class="muted">{{ $snapshot['empresa']['direccion'] ?? '' }}</div>
                </td>
                <td style="width:40%; text-align:right">
                    <div class="folio">ACUSE DE RECEPCIÓN</div>
                    <div class="folio">{{ $acuse->folio }}</div>
                    <div class="muted">Entrega: {{ $snapshot['entrega']['folio'] ?? '' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <p>Comprobante de entrega y recepción de uniformes. El colaborador declara haber recibido a
    su entera satisfacción las prendas descritas en este documento.</p>

    <div class="caja">
        <table class="datos">
            <tr>
                <td><strong>Colaborador:</strong></td>
                <td>{{ $acuse->nombre_firmante_snapshot }}</td>
                <td><strong>N.º de empleado:</strong></td>
                <td>{{ $acuse->numero_empleado_snapshot }}</td>
            </tr>
            <tr>
                <td><strong>Sucursal:</strong></td>
                <td>{{ $snapshot['sucursal']['nombre'] ?? '' }}</td>
                <td><strong>Puesto / Área:</strong></td>
                <td>{{ trim(($snapshot['colaborador']['puesto'] ?? '').' / '.($snapshot['colaborador']['area'] ?? ''), ' /') }}</td>
            </tr>
            <tr>
                <td><strong>Responsable de entrega:</strong></td>
                <td>{{ $snapshot['encargado']['name'] ?? '' }}</td>
                <td><strong>Fecha de entrega:</strong></td>
                <td>{{ $snapshot['entrega']['fecha_entrega'] ?? '' }}</td>
            </tr>
            <tr>
                <td><strong>Fecha y hora de firma:</strong></td>
                <td>{{ $acuse->firmado_en->format('d/m/Y H:i') }}</td>
                <td><strong>IP de firma:</strong></td>
                <td>{{ $acuse->ip_firma ?? 's/d' }}</td>
            </tr>
        </table>
    </div>

    <h2>Prendas entregadas</h2>
    <table class="items">
        <thead>
            <tr><th>Prenda</th><th>Talla</th><th style="text-align:right">Cantidad</th></tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] ?? [] as $item)
                <tr>
                    <td>{{ $item['activo'] ?? $item['prenda'] ?? '' }}</td>
                    <td>{{ $item['talla'] }}</td>
                    <td style="text-align:right">{{ $item['cantidad'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2" style="text-align:right">Total de prendas</th>
                <th style="text-align:right">{{ collect($snapshot['items'] ?? [])->sum('cantidad') }}</th>
            </tr>
        </tfoot>
    </table>

    <div class="firma-caja">
        <h2>Firma de recepción</h2>
        @if ($firmaDataUri)
            <img class="firma-img" src="{{ $firmaDataUri }}" alt="Firma">
        @else
            <div class="firma-img"></div>
        @endif
        <div class="muted" style="margin-top:6px">{{ $acuse->nombre_firmante_snapshot }} — N.º {{ $acuse->numero_empleado_snapshot }}</div>
    </div>

    <div class="pie">
        <div>Folio de acuse: {{ $acuse->folio }} · Documento generado por el Sistema de Control y Gestión de Uniformes.</div>
        <div>Huella de integridad (SHA-256) del contenido: {{ $acuse->hash_documento }}</div>
        <div>Huella de integridad (SHA-256) de la firma: {{ $acuse->hash_firma }}</div>
        <div>Este comprobante registra una firma de conformidad. No constituye una Firma Electrónica Avanzada ni e.firma.</div>
    </div>
</body>
</html>
