<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acuse de devolución {{ $acuse->folio }}</title>
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
        .firma-img { border: 1px solid #cbd5e1; height: 110px; width: 100%; max-width: 260px; object-fit: contain; }
        .pie { margin-top: 28px; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8px; }
        .folio { font-size: 12px; font-weight: bold; }
        .evidencia-fila td { background: #f8fafc; }
        .evidencia-fila img { max-height: 90px; max-width: 130px; border: 1px solid #cbd5e1; margin: 2px 4px 2px 0; }
        .evidencia-nd { font-size: 9px; color: #94a3b8; font-style: italic; }
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
                    <div class="folio">ACUSE DE DEVOLUCIÓN</div>
                    <div class="folio">{{ $acuse->folio }}</div>
                    <div class="muted">Devolución: {{ $snapshot['devolucion']['folio'] ?? '' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <p>Comprobante de devolución de activos. El encargado declara haber recibido a su entera
    satisfacción los activos descritos en este documento.</p>

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
                <td><strong>Recibe la devolución:</strong></td>
                <td>{{ $snapshot['operador']['name'] ?? '' }}</td>
                <td><strong>Fecha de devolución:</strong></td>
                <td>{{ $snapshot['devolucion']['fecha'] ?? '' }}</td>
            </tr>
            <tr>
                <td><strong>Fecha y hora de firma:</strong></td>
                <td>{{ \App\Soporte\FechaHora::local($acuse->firmado_en) }}</td>
                <td><strong>IP de firma:</strong></td>
                <td>{{ $acuse->ip_firma ?? 's/d' }}</td>
            </tr>
            @if ($snapshot['devolucion']['motivo'] ?? null)
                <tr>
                    <td><strong>Motivo:</strong></td>
                    <td colspan="3">{{ $snapshot['devolucion']['motivo'] }}</td>
                </tr>
            @endif
        </table>
    </div>

    <h2>Activos devueltos</h2>
    <table class="items">
        <thead>
            <tr><th>Activo</th><th>Talla</th><th style="text-align:right">Cantidad</th><th>Condición</th></tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] ?? [] as $item)
                @php($imgs = $evidenciasPorItem[$loop->index] ?? [])
                <tr>
                    <td>{{ $item['activo'] ?? '' }}</td>
                    <td>{{ $item['talla'] ?? '—' }}</td>
                    <td style="text-align:right">{{ $item['cantidad'] }}</td>
                    <td>{{ $item['condicion'] ?? '—' }}</td>
                </tr>
                @if (!empty($imgs))
                    <tr class="evidencia-fila">
                        <td colspan="4">
                            <strong style="font-size:9px; color:#64748b">Evidencia fotográfica:</strong><br>
                            @foreach ($imgs as $img)
                                @if ($img)
                                    <img src="{{ $img }}" alt="Evidencia">
                                @else
                                    <span class="evidencia-nd">Evidencia no disponible.</span>
                                @endif
                            @endforeach
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2" style="text-align:right">Total de activos</th>
                <th style="text-align:right">{{ collect($snapshot['items'] ?? [])->sum('cantidad') }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    @if ($acuse->aceptacion_titular && $acuse->texto_aceptado_snapshot)
        <div class="caja">
            <strong>Consentimiento aceptado:</strong> "{{ $acuse->texto_aceptado_snapshot }}"
            <div class="muted">Aceptado el {{ \App\Soporte\FechaHora::local($acuse->aceptado_en) }}</div>
        </div>
    @endif

    <table style="width:100%">
        <tr>
            <td style="width:50%; padding-right:8px">
                <div class="firma-caja">
                    <h2>Firma de quien devuelve</h2>
                    @if ($firmaDataUri)
                        <img class="firma-img" src="{{ $firmaDataUri }}" alt="Firma de quien devuelve">
                    @else
                        <div class="firma-img"></div>
                    @endif
                    <div class="muted" style="margin-top:6px">{{ $acuse->nombre_firmante_snapshot }} — N.º {{ $acuse->numero_empleado_snapshot }}</div>
                </div>
            </td>
            <td style="width:50%; padding-left:8px">
                <div class="firma-caja">
                    <h2>Firma de quien recibe</h2>
                    @if ($firmaOperadorDataUri)
                        <img class="firma-img" src="{{ $firmaOperadorDataUri }}" alt="Firma de quien recibe">
                    @else
                        <div class="firma-img"></div>
                    @endif
                    <div class="muted" style="margin-top:6px">{{ $acuse->nombre_firmante_operador_snapshot ?? $snapshot['operador']['name'] ?? '' }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="pie">
        <div>Folio de acuse: {{ $acuse->folio }} · Documento generado por el Sistema de Control y Gestión de Uniformes.</div>
        <div>Huella de integridad (SHA-256) del contenido: {{ $acuse->hash_documento }}</div>
        <div>Huella (SHA-256) de la firma de quien devuelve: {{ $acuse->hash_firma }}</div>
        @if ($acuse->hash_firma_operador)
            <div>Huella (SHA-256) de la firma de quien recibe: {{ $acuse->hash_firma_operador }}</div>
        @endif
        <div>Este comprobante registra una firma de conformidad. No constituye una Firma Electrónica Avanzada ni e.firma.</div>
    </div>
</body>
</html>
