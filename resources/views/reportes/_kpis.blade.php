@if ($contexto->kpis !== [])
    <div class="kpis">
        @foreach ($contexto->kpis as $etiqueta => $valor)
            <div class="kpi-tarjeta">
                <p class="etiqueta">{{ $etiqueta }}</p>
                <p class="valor">{{ $valor }}</p>
            </div>
        @endforeach
    </div>
@endif
