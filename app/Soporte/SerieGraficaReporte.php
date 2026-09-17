<?php

namespace App\Soporte;

use App\Enums\TipoGrafica;

/**
 * Una gráfica lista para pintarse en el Excel (imagen PNG vía
 * `ServicioGraficaImagen`) y en el PDF (ApexCharts vivo vía Chromium) a
 * partir de LOS MISMOS datos — nunca una consulta aparte por formato. Cada
 * controller la arma agregando en PHP la colección que YA obtuvo para las
 * filas del listado.
 *
 * De un solo eje (barras/dona/línea) por defecto; opcionalmente COMPARATIVA
 * (`$valoresComparacion` no nulo) para contrastar dos series sobre las mismas
 * categorías (p. ej. "piezas entregadas" vs "piezas devueltas" por periodo)
 * — sólo válida con `TipoGrafica::Barras` (barras agrupadas).
 */
final readonly class SerieGraficaReporte
{
    /**
     * @param  array<int, string>  $etiquetas  Eje X / categorías, en el mismo orden que `$valores`.
     * @param  array<int, int|float>  $valores
     * @param  ?array<int, string>  $colores  Hex por etiqueta, mismo orden que `$etiquetas`. `null` = paleta por defecto (`PaletaGraficas`).
     * @param  ?string  $etiquetaSerie  Nombre de la serie principal en modo comparativo (p. ej. "Piezas entregadas"). Ignorado si no es comparativa.
     * @param  ?array<int, int|float>  $valoresComparacion  Segunda serie opcional, mismo orden/longitud que `$etiquetas`. `null` = gráfica de una sola serie.
     * @param  ?string  $etiquetaComparacion  Nombre de la segunda serie (p. ej. "Piezas devueltas").
     * @param  bool  $etiquetasLargasEnDosLineas  OPT-IN (por defecto `false`, retrocompatible con toda gráfica existente de otros módulos): activa, sólo en barras horizontales de una serie, el corte "Nombre base (Variante)" → 2 líneas en el eje de categorías (`_apex-lib.blade.php::partirEtiquetaVarianteEnDosLineas`), para que la variante/talla nunca quede a medias por el ancho máximo del eje. Ver `Reportes/Index.vue` para el equivalente en pantalla (`opcionesBarrasHorizontales({ etiquetaLargaEnDosLineas: true })`).
     */
    public function __construct(
        public string $titulo,
        public TipoGrafica $tipo,
        public array $etiquetas,
        public array $valores,
        public ?array $colores = null,
        public ?string $etiquetaSerie = null,
        public ?array $valoresComparacion = null,
        public ?string $etiquetaComparacion = null,
        public bool $etiquetasLargasEnDosLineas = false,
    ) {}

    public function esComparativa(): bool
    {
        return $this->valoresComparacion !== null;
    }

    /**
     * Colores resueltos: los explícitos si vienen, si no la paleta genérica
     * (un color por posición, ciclando `PaletaGraficas::serie()`).
     *
     * @return array<int, string>
     */
    public function coloresResueltos(): array
    {
        if ($this->colores !== null) {
            return $this->colores;
        }

        return array_map(
            fn (int $indice): string => PaletaGraficas::serie($indice),
            array_keys($this->etiquetas),
        );
    }

    /**
     * Opciones de ApexCharts para el PDF ejecutivo (Browsershot/Chromium) —
     * la MISMA fuente de datos que el Excel nativo, sólo cambia el formato
     * de salida. Animaciones desactivadas a propósito: Chromium captura el
     * PDF apenas se resuelve la promesa de `render()`, así que el estado
     * final (no uno a medio animar) debe estar listo de inmediato.
     *
     * @return array<string, mixed>
     */
    public function opcionesApex(): array
    {
        $colores = $this->coloresResueltos();
        $fuente = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif";

        $base = [
            'chart' => [
                'type' => match ($this->tipo) {
                    TipoGrafica::Barras => 'bar',
                    TipoGrafica::Dona => 'donut',
                    TipoGrafica::Linea => 'line',
                },
                'height' => 260,
                'fontFamily' => $fuente,
                'animations' => ['enabled' => false],
                'toolbar' => ['show' => false],
                'sparkline' => ['enabled' => false],
            ],
            'colors' => $colores,
            'legend' => ['fontFamily' => $fuente],
            'dataLabels' => ['enabled' => $this->tipo !== TipoGrafica::Linea],
        ];

        if ($this->esComparativa()) {
            return [
                ...$base,
                'colors' => [$colores[0] ?? PaletaGraficas::principal(), $colores[1] ?? PaletaGraficas::serie(1)],
                'series' => [
                    ['name' => $this->etiquetaSerie ?? $this->titulo, 'data' => $this->valores],
                    ['name' => $this->etiquetaComparacion ?? '', 'data' => $this->valoresComparacion],
                ],
                'plotOptions' => ['bar' => ['horizontal' => false, 'borderRadius' => 3, 'columnWidth' => '55%']],
                'dataLabels' => ['enabled' => false],
                'legend' => [...$base['legend'], 'show' => true, 'position' => 'top'],
                'xaxis' => ['categories' => $this->etiquetas, 'labels' => ['style' => ['fontFamily' => $fuente]]],
            ];
        }

        return match ($this->tipo) {
            TipoGrafica::Barras => [
                ...$base,
                // Sin `etiquetasLargasEnDosLineas` (todo lo que no es
                // "Activo (Variante)"), `chart.height` queda igual que
                // siempre — idéntico byte a byte para cualquier otro
                // módulo/gráfica. Con el flag, un poco más de alto: cada
                // barra necesita espacio para 2 líneas de etiqueta, no 1.
                'chart' => $this->etiquetasLargasEnDosLineas
                    ? [...$base['chart'], 'height' => 300]
                    : $base['chart'],
                'series' => [['name' => $this->titulo, 'data' => $this->valores]],
                'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 3, 'distributed' => true]],
                'legend' => [...$base['legend'], 'show' => false],
                'xaxis' => ['categories' => $this->etiquetas, 'labels' => ['style' => ['fontFamily' => $fuente]]],
                // `yaxis.labels.maxWidth`: ApexCharts corta con "…" a partir
                // de 160px por defecto — insuficiente para "Activo
                // (Variante)", cortaba justo antes de la variante. El
                // formatter que además la parte en 2 líneas vive en
                // `reportes/_partir-etiqueta-variante.blade.php` (JS no
                // serializa por JSON, así que no puede ir en este array).
                ...($this->etiquetasLargasEnDosLineas
                    ? ['yaxis' => ['labels' => ['maxWidth' => 320]]]
                    : []),
            ],
            TipoGrafica::Dona => [
                ...$base,
                'series' => $this->valores,
                'labels' => $this->etiquetas,
                'legend' => [...$base['legend'], 'position' => 'right'],
            ],
            TipoGrafica::Linea => [
                ...$base,
                'series' => [['name' => $this->titulo, 'data' => $this->valores]],
                'colors' => [$colores[0] ?? PaletaGraficas::principal()],
                'stroke' => ['curve' => 'smooth', 'width' => 3],
                'legend' => [...$base['legend'], 'show' => false],
                'xaxis' => ['categories' => $this->etiquetas, 'labels' => ['style' => ['fontFamily' => $fuente]]],
            ],
        };
    }
}
