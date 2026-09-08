@extends('emails.layout')

@section('cuerpo')
    <p style="margin:0 0 16px;">
        Hola,
    </p>
    <p style="margin:0 0 20px;">
        Te confirmamos que la operación quedó <strong>{{ strtolower($estado) }}</strong>.
        A continuación el detalle.
    </p>

    {{-- Estado --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 16px; font-size:14px; font-weight:600; color:#166534;">
                {{ $estado }}
            </td>
        </tr>
    </table>

    {{-- Datos generales --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; margin:0 0 24px;">
        @php
            $filas = [
                'Empresa' => $empresaNombre,
                'Sucursal' => $sucursal,
                'Folio' => $folio,
                'Folio de acuse' => $folioAcuse,
                'Fecha' => $fecha ?? '—',
                'Colaborador' => $colaborador,
                'Número de empleado' => $numeroEmpleado,
                'Encargado' => $encargado,
            ];
            if ($referenciaEtiqueta && $referenciaValor) {
                $filas[$referenciaEtiqueta] = $referenciaValor;
            }
        @endphp
        @foreach ($filas as $etiqueta => $valor)
            <tr>
                <td style="padding:6px 12px 6px 0; font-size:13px; color:#71717a; vertical-align:top; white-space:nowrap;">{{ $etiqueta }}</td>
                <td style="padding:6px 0; font-size:13px; color:#27272a; font-weight:600; vertical-align:top;">{{ $valor }}</td>
            </tr>
        @endforeach
    </table>

    {{-- Detalle de activos --}}
    <p style="margin:0 0 8px; font-size:13px; font-weight:700; color:#3f3f46; text-transform:uppercase; letter-spacing:0.03em;">
        Activos
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; margin:0 0 24px; border:1px solid #e4e4e7; border-radius:8px;">
        <tr style="background-color:#fafafa;">
            <th align="left" style="padding:8px 12px; font-size:12px; color:#71717a; border-bottom:1px solid #e4e4e7;">Descripción</th>
            <th align="left" style="padding:8px 12px; font-size:12px; color:#71717a; border-bottom:1px solid #e4e4e7;">Variante</th>
            <th align="right" style="padding:8px 12px; font-size:12px; color:#71717a; border-bottom:1px solid #e4e4e7;">Cantidad</th>
        </tr>
        @forelse ($items as $item)
            <tr>
                <td style="padding:8px 12px; font-size:13px; color:#27272a; border-bottom:1px solid #f4f4f5;">
                    {{ $item['nombre'] }}
                    @if (!empty($item['condicion']))
                        <span style="display:block; font-size:11px; color:#71717a;">Condición: {{ $item['condicion'] }}</span>
                    @endif
                </td>
                <td style="padding:8px 12px; font-size:13px; color:#52525b; border-bottom:1px solid #f4f4f5;">{{ $item['variante'] ?: '—' }}</td>
                <td align="right" style="padding:8px 12px; font-size:13px; color:#27272a; border-bottom:1px solid #f4f4f5;">{{ $item['cantidad'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3" style="padding:12px; font-size:13px; color:#71717a;">Sin activos registrados.</td>
            </tr>
        @endforelse
    </table>

    {{-- Confirmación de firmas --}}
    <p style="margin:0 0 16px; font-size:13px; color:#52525b;">
        {{ $confirmacionFirmas }}
    </p>

    {{-- Adjunto --}}
    <p style="margin:0; font-size:13px; color:#52525b;">
        {{ $adjuntoNota }}
    </p>
@endsection
