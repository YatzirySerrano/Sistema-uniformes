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
    </style>
</head>
<body>
    @include('reportes._encabezado')
    @include('reportes._kpis')
    @include('reportes._glosario-kpis')
    @include('reportes._graficas')

    @if ($entregas->isEmpty())
        <p class="vacio">No hay entregas que coincidan con los filtros aplicados.</p>
    @else
        <table class="datos">
            <thead>
                <tr>
                    <th>Folio</th><th>Fecha</th><th>Empresa</th><th>Sucursal</th><th>N.º empleado</th>
                    <th>Colaborador</th><th>Responsable</th><th>Servicio</th><th>Activo</th><th>Talla</th><th class="num">Cant.</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entregas as $entrega)
                    @foreach ($entrega->detalles as $detalle)
                        <tr>
                            <td>{{ $entrega->folio }}</td>
                            <td>{{ $entrega->fecha_entrega->format('d/m/Y') }}</td>
                            <td>{{ $entrega->empresa?->nombre_comercial }}</td>
                            <td>{{ $entrega->sucursal?->nombre }}</td>
                            <td>{{ $entrega->colaborador?->numero_empleado }}</td>
                            <td>{{ $entrega->colaborador?->nombre_completo }}</td>
                            <td>{{ $entrega->encargado?->name }}</td>
                            <td>{{ $entrega->servicio ? $entrega->servicio->contrato->nombre.' — '.$entrega->servicio->nombre : 'Sin servicio' }}</td>
                            <td>{{ $detalle->activo_nombre_snapshot }}</td>
                            <td>{{ $detalle->talla_valor_snapshot ?? 'Sin talla' }}</td>
                            <td class="num">{{ $detalle->cantidad }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
