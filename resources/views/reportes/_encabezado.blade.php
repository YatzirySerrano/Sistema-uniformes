<div class="encabezado">
    <table>
        <tr>
            @if ($contexto->logoBase64())
                <td class="logo" style="width: 150px;">
                    <img src="{{ $contexto->logoBase64() }}" alt="Logotipo">
                </td>
            @endif
            <td>
                <p class="empresa">{{ $contexto->nombreEmpresa() }}</p>
                <h1>{{ $contexto->titulo }}</h1>
                <div class="metadata">
                    @foreach ($contexto->filtros as $etiqueta => $valor)
                        <span><b>{{ $etiqueta }}:</b> {{ $valor }}</span>
                    @endforeach
                    <span><b>Generado:</b> {{ $contexto->generadoEnLocal() }}</span>
                    <span><b>Registros:</b> {{ $contexto->total }}</span>
                </div>
            </td>
        </tr>
    </table>
</div>
