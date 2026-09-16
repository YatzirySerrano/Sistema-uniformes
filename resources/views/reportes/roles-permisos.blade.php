<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contexto->titulo }}</title>
    @include('reportes._estilos', ['colorPrincipal' => \App\Soporte\PaletaGraficas::principal()])
    <style>
        .rol { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; page-break-inside: avoid; }
        .rol-encabezado { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .rol-encabezado td { border: none; padding: 0; vertical-align: middle; }
        .rol-nombre { font-size: 12px; font-weight: bold; color: #0f172a; }
        .badge { display: inline-block; font-size: 8px; padding: 1px 6px; border-radius: 8px; margin-left: 6px; }
        .badge-base { background: #e0e7ff; color: #3730a3; }
        .badge-personalizado { background: #dcfce7; color: #166534; }
        .rol-meta { font-size: 8.5px; color: #64748b; text-align: right; }
        .sin-permisos { color: #94a3b8; font-style: italic; }
        .categorias { width: 100%; border-collapse: collapse; }
        .categorias td { vertical-align: top; padding: 0 10px 0 0; width: 33.33%; }
        .categoria-titulo { font-size: 9px; font-weight: bold; color: #1e293b; background: #f1f5f9; padding: 3px 6px; margin: 6px 0 2px; border-radius: 3px; }
        .categoria-lista { margin: 0 0 4px; padding-left: 14px; }
        .categoria-lista li { margin-bottom: 1px; }
    </style>
</head>
<body>
    @include('reportes._encabezado')
    @include('reportes._kpis')

    @if (count($roles) === 0)
        <p class="vacio">No hay roles que coincidan con los filtros aplicados.</p>
    @else
        @foreach ($roles as $rol)
            <div class="rol">
                <table class="rol-encabezado">
                    <tr>
                        <td>
                            <span class="rol-nombre">{{ $rol['etiqueta'] }}</span>
                            <span class="badge {{ $rol['base'] ? 'badge-base' : 'badge-personalizado' }}">
                                {{ $rol['base'] ? 'Base del sistema' : 'Personalizado' }}
                            </span>
                        </td>
                        <td class="rol-meta">
                            {{ $rol['usuarios'] }} usuario(s) · {{ $rol['total_permisos'] }} permiso(s)
                        </td>
                    </tr>
                </table>

                @if (count($rol['grupos']) === 0)
                    <p class="sin-permisos">Sin permisos asignados.</p>
                @else
                    {{-- Tres columnas para que las categorías se lean en bloques cortos,
                         nunca como una sola línea corrida. --}}
                    <table class="categorias">
                        <tr>
                            @foreach ($rol['grupos']->chunk((int) ceil(count($rol['grupos']) / 3)) as $columna)
                                <td>
                                    @foreach ($columna as $etiquetaGrupo => $items)
                                        <p class="categoria-titulo">{{ $etiquetaGrupo }}</p>
                                        <ul class="categoria-lista">
                                            @foreach ($items as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    </table>
                @endif
            </div>
        @endforeach
    @endif
</body>
</html>
