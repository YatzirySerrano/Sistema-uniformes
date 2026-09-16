<div class="encabezado">
    <div class="identidad">
        @if ($contexto->logoBase64())
            <img class="logo" src="{{ $contexto->logoBase64() }}" alt="Logotipo">
        @endif
        <div>
            <p class="empresa">{{ $contexto->nombreEmpresa() }}</p>
            <h1>{{ $contexto->titulo }}</h1>
        </div>
    </div>
    <div class="meta">
        <div><b>Generado por:</b> {{ $contexto->generadoPor ?? 'Sistema' }}</div>
        <div><b>Fecha:</b> {{ $contexto->generadoEnLocal() }}</div>
        <div><b>Registros:</b> {{ $contexto->total }}</div>
    </div>
</div>

@if ($contexto->filtros !== [])
    <p class="filtros-aplicados">
        Filtros aplicados:
        @foreach ($contexto->filtros as $etiqueta => $valor)
            <b>{{ $etiqueta }}:</b> {{ $valor }}@if (! $loop->last) &nbsp;·&nbsp; @endif
        @endforeach
    </p>
@endif
