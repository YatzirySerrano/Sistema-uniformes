@if ($contexto->graficas !== [])
    <h2 class="seccion">Indicadores gráficos</h2>
    <div class="graficas">
        @foreach ($contexto->graficas as $indice => $grafica)
            <div class="grafica-tarjeta">
                <p class="titulo">{{ $grafica->titulo }}</p>
                <div id="grafica-{{ $indice }}" class="grafica-lienzo"></div>
            </div>
            <script>
                (function () {
                    var opciones = {!! json_encode($grafica->opcionesApex(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!};
                    var grafica = new ApexCharts(document.querySelector('#grafica-{{ $indice }}'), opciones);
                    window.__graficasPendientes.push(grafica.render());
                })();
            </script>
        @endforeach
    </div>
    <script>
        Promise.all(window.__graficasPendientes)
            .then(function () { window.pdfReady = true; })
            .catch(function () { window.pdfReady = true; });
    </script>
@endif
