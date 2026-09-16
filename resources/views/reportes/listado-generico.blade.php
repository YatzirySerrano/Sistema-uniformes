<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contexto->titulo }}</title>
    @include('reportes._estilos', ['colorPrincipal' => \App\Soporte\PaletaGraficas::principal()])
    @if ($contexto->graficas !== [])
        @include('reportes._apex-lib')
    @endif
</head>
<body>
    @include('reportes._encabezado')
    @include('reportes._kpis')
    @include('reportes._graficas')

    @if (count($filas) === 0)
        <p class="vacio">No hay registros que coincidan con los filtros aplicados.</p>
    @else
        <table class="datos">
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
    @endif
</body>
</html>
