<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        html, body { margin: 0; padding: 0; background: #ffffff; }
        #grafica { width: {{ $ancho }}px; height: {{ $alto }}px; }
    </style>
</head>
<body>
    <div id="grafica"></div>
    <script>{!! file_get_contents(base_path('node_modules/apexcharts/dist/apexcharts.min.js')) !!}</script>
    @include('reportes._partir-etiqueta-variante')
    <script>
        window.__graficaLista = false;
        (function () {
            var opciones = {!! json_encode($grafica->opcionesApex(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!};
            opciones.chart = Object.assign({}, opciones.chart, {
                width: {{ $ancho }},
                height: {{ $alto }},
                animations: { enabled: false },
            });
            @if ($grafica->etiquetasLargasEnDosLineas)
                opciones.yaxis = Object.assign({}, opciones.yaxis, {
                    labels: Object.assign({}, (opciones.yaxis && opciones.yaxis.labels) || {}, {
                        formatter: partirEtiquetaVarianteEnDosLineas,
                    }),
                });
            @endif
            var instancia = new ApexCharts(document.querySelector('#grafica'), opciones);
            instancia.render()
                .then(function () { window.__graficaLista = true; })
                .catch(function () { window.__graficaLista = true; });
        })();
    </script>
</body>
</html>
