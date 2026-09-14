<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acuse de traspaso {{ $snapshot['traspaso']['folio'] ?? $acuse->traspaso->folio }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        .encabezado { border-bottom: 3px solid #2563eb; padding-bottom: 12px; margin-bottom: 16px; }
        .encabezado table { width: 100%; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; color: #2563eb; }
        .muted { color: #64748b; }
        .caja { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        table.datos td { padding: 2px 6px; vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th, table.items td { border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left; }
        table.items th { background: #f1f5f9; }
        .firma-caja { margin-top: 24px; }
        .firma-img { border: 1px solid #cbd5e1; height: 110px; width: 100%; max-width: 260px; object-fit: contain; }
        .pie { margin-top: 28px; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8px; }
        .folio { font-size: 12px; font-weight: bold; }
        .flecha { font-weight: bold; padding: 0 6px; }
    </style>
</head>
<body>
    <div class="encabezado">
        <table>
            <tr>
                <td style="width:60%">
                    <h1>Comprobante de traspaso de inventario</h1>
                    <div class="muted">
                        {{ $snapshot['tipo'] ?? ($acuse->traspaso->tipo === 'interempresa' ? 'Traspaso entre empresas' : 'Traspaso entre almacenes de la misma empresa') }}
                    </div>
                </td>
                <td style="width:40%; text-align:right">
                    <div class="folio">ACUSE DE TRASPASO</div>
                    <div class="folio">{{ $snapshot['traspaso']['folio'] ?? '' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <p>El responsable declara haber confirmado el traspaso descrito en este documento, con el
    inventario descontado del origen e ingresado al destino en el mismo instante de la firma.</p>

    <div class="caja">
        <table class="datos">
            <tr>
                <td><strong>Empresa origen:</strong></td>
                <td>{{ $snapshot['empresa_origen']['nombre_comercial'] ?? '' }}</td>
                <td><strong>Almacén origen:</strong></td>
                <td>{{ $snapshot['almacen_origen']['nombre'] ?? '' }}</td>
            </tr>
            <tr>
                <td><strong>Empresa destino:</strong></td>
                <td>{{ $snapshot['empresa_destino']['nombre_comercial'] ?? '' }}</td>
                <td><strong>Almacén destino:</strong></td>
                <td>{{ $snapshot['almacen_destino']['nombre'] ?? '' }}</td>
            </tr>
            <tr>
                <td><strong>Responsable:</strong></td>
                <td>{{ $snapshot['responsable']['name'] ?? '' }}</td>
                <td><strong>Fecha del traspaso:</strong></td>
                <td>{{ \App\Soporte\FechaHora::local($acuse->traspaso->ocurrido_en) }}</td>
            </tr>
            <tr>
                <td><strong>Firmado por:</strong></td>
                <td>{{ $acuse->nombre_firmante_snapshot }}</td>
                <td><strong>Fecha y hora de firma:</strong></td>
                <td>{{ \App\Soporte\FechaHora::local($acuse->firmado_en) }}</td>
            </tr>
            <tr>
                <td><strong>IP de firma:</strong></td>
                <td>{{ $acuse->ip_firma ?? 's/d' }}</td>
                <td></td>
                <td></td>
            </tr>
            @if ($snapshot['traspaso']['motivo'] ?? null)
                <tr>
                    <td><strong>Motivo:</strong></td>
                    <td colspan="3">{{ $snapshot['traspaso']['motivo'] }}</td>
                </tr>
            @endif
            @if ($snapshot['traspaso']['notas'] ?? null)
                <tr>
                    <td><strong>Notas:</strong></td>
                    <td colspan="3">{{ $snapshot['traspaso']['notas'] }}</td>
                </tr>
            @endif
        </table>
    </div>

    <h2>Activos traspasados</h2>
    <table class="items">
        <thead>
            <tr>
                <th>Activo origen</th>
                <th>Activo destino</th>
                <th>Talla / variante</th>
                <th style="text-align:right">Cantidad</th>
                <th>Unidad / código</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] ?? [] as $item)
                <tr>
                    <td>{{ $item['activo_origen'] ?? '' }}</td>
                    <td>{{ $item['activo_destino'] ?? '' }}</td>
                    <td>{{ $item['talla'] ?? '—' }}</td>
                    <td style="text-align:right">
                        {{ $item['control'] === 'individual' ? 1 : $item['cantidad'] }}
                    </td>
                    <td>{{ $item['unidad_codigo'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="text-align:right">Total de renglones</th>
                <th style="text-align:right">{{ count($snapshot['items'] ?? []) }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    <div class="firma-caja">
        <h2>Firma del responsable</h2>
        @if ($firmaDataUri)
            <img class="firma-img" src="{{ $firmaDataUri }}" alt="Firma del responsable">
        @else
            <div class="firma-img"></div>
        @endif
        <div class="muted" style="margin-top:6px">{{ $acuse->nombre_firmante_snapshot }}</div>
    </div>

    <div class="pie">
        <div>Folio: {{ $snapshot['traspaso']['folio'] ?? '' }} · Documento generado por el Sistema de Control y Gestión de Uniformes.</div>
        <div>Huella de integridad (SHA-256) del contenido: {{ $acuse->hash_documento }}</div>
        <div>Huella (SHA-256) de la firma: {{ $acuse->hash_firma }}</div>
        <div>Este comprobante registra una firma de conformidad. No constituye una Firma Electrónica Avanzada ni e.firma.</div>
    </div>
</body>
</html>
