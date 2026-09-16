<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contexto->titulo }}</title>
    @include('reportes._estilos', ['colorPrincipal' => \App\Soporte\PaletaGraficas::principal()])
    @if ($contexto->graficas !== [])
        @include('reportes._apex-lib')
    @endif
    <style>
        td.num { text-align: right; }
        .bajo { color: #b91c1c; font-weight: bold; }
        .sin-existencias { color: #991b1b; font-weight: bold; }
    </style>
</head>
<body>
    @include('reportes._encabezado')
    @include('reportes._kpis')
    @include('reportes._glosario-kpis')
    @include('reportes._graficas')

    @if ($saldos->isEmpty())
        <p class="vacio">No hay existencias que coincidan con los filtros aplicados.</p>
    @else
        <table class="datos">
            <thead>
                <tr>
                    <th>Empresa</th><th>Almacén</th><th>Activo</th><th>Variante</th>
                    <th class="num">Disponible</th><th class="num">Mínimo</th><th>Estado de stock</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($saldos as $saldo)
                    @php($estado = \App\Enums\EstadoStockInventario::paraSaldo($saldo))
                    <tr>
                        <td>{{ $saldo->empresa?->nombre_comercial }}</td>
                        <td>{{ $saldo->almacen?->nombre }}</td>
                        <td>{{ $saldo->activo?->nombre }}</td>
                        <td>{{ $saldo->talla?->valor ?? 'Sin talla' }}</td>
                        <td class="num">{{ $saldo->cantidad }}</td>
                        <td class="num">{{ $saldo->minimo }}</td>
                        <td class="{{ $estado === \App\Enums\EstadoStockInventario::BajoMinimo ? 'bajo' : ($estado === \App\Enums\EstadoStockInventario::SinExistencias ? 'sin-existencias' : '') }}">
                            {{ $estado->etiqueta() }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
