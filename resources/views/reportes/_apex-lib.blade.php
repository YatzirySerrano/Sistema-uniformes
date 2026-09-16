{{--
    ApexCharts embebido inline (no por URL): Browsershot navega el HTML del
    reporte como archivo temporal (`file://`), donde una ruta relativa al
    build de Vite no resolvería. Sólo se incluye en las vistas que
    efectivamente traen `$contexto->graficas` (evita ~900 KB de JS en los
    módulos sin gráficas).
--}}
<script>
    window.pdfReady = false;
    window.__graficasPendientes = [];
</script>
<script>{!! file_get_contents(base_path('node_modules/apexcharts/dist/apexcharts.min.js')) !!}</script>
