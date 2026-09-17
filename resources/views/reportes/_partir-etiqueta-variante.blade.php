{{--
    "Nombre base (Variante)" → 2 líneas — misma idea que
    `useGraficasDashboard.ts::partirEtiquetaVariante()` en el sistema (Vue),
    reimplementada aquí porque este runtime es JS embebido en el HTML que
    Browsershot navega (PDF/Excel), no puede importar del bundle de Vite.
    Sólo se usa como `yaxis.labels.formatter` cuando
    `SerieGraficaReporte::$etiquetasLargasEnDosLineas` es `true`
    (`_graficas.blade.php`/`_grafica-standalone.blade.php`) — para el resto
    de gráficas (sin ese sufijo) ApexCharts recibe el string intacto, 1 sola
    línea, igual que siempre.
--}}
<script>
    function partirEtiquetaVarianteEnDosLineas(valor) {
        if (typeof valor !== 'string') {
            return valor;
        }
        var coincidencia = valor.match(/^(.+)\s(\([^)]+\))$/);
        return coincidencia ? [coincidencia[1], coincidencia[2]] : valor;
    }
</script>
