<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>{{ $titulo }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; -webkit-text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $titulo }} · {{ $folio }} — {{ $empresaNombre }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e4e4e7;">
                    {{-- Encabezado con color de marca --}}
                    <tr>
                        <td style="background-color:{{ $color }}; padding:20px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="48" style="vertical-align:middle; padding-right:12px;">
                                        @if ($logo)
                                            {{-- Adjunto inline vía Content-ID (`$message` lo inyecta
                                                 Laravel automáticamente al renderizar la vista de un
                                                 Mailable) — nunca data URI ni URL remota. --}}
                                            <img src="{{ $message->embedData($logo['binario'], $logo['nombre'], $logo['mime']) }}" alt="{{ $empresaNombre }}" width="44" style="display:block; width:44px; height:44px; border-radius:6px; background:#ffffff; object-fit:contain;">
                                        @else
                                            {{-- Sin logo utilizable (no configurado, o formato no
                                                 embebible en correo como SVG): placeholder con la
                                                 inicial de la empresa en vez de dejar un hueco o un
                                                 ícono roto. --}}
                                            <table role="presentation" width="44" height="44" cellpadding="0" cellspacing="0" border="0" style="width:44px; height:44px; background-color:rgba(255,255,255,0.18); border-radius:6px;">
                                                <tr>
                                                    <td align="center" valign="middle" style="width:44px; height:44px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:18px; font-weight:700; color:#ffffff;">
                                                        {{ mb_strtoupper(mb_substr($empresaNombre, 0, 1)) }}
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif
                                    </td>
                                    <td style="vertical-align:middle;">
                                        <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; font-weight:700; color:#ffffff; line-height:1.3;">
                                            {{ $empresaNombre }}
                                        </div>
                                        <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:rgba(255,255,255,0.85); line-height:1.4;">
                                            {{ $titulo }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Cuerpo --}}
                    <tr>
                        <td style="padding:28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; color:#27272a; line-height:1.55;">
                            @yield('cuerpo')
                        </td>
                    </tr>

                    {{-- Pie --}}
                    <tr>
                        <td style="padding:16px 28px 24px; border-top:1px solid #e4e4e7; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; color:#71717a; line-height:1.5;">
                            Este es un mensaje automático generado por el sistema de control de activos de {{ $empresaNombre }}.
                            Por favor no respondas a este correo.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
