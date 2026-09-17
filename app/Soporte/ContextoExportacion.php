<?php

namespace App\Soporte;

use App\Models\Empresa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Identidad visual + metadata de un export (Excel/PDF): título del reporte,
 * empresa (o "todas las empresas" cuando el listado no está acotado a una
 * sola), filtros ya humanizados (nunca ids técnicos) y totales. Una sola
 * fuente para que `ListadoExport` y `reportes/listado-generico.blade.php`
 * (y cualquier export con forma propia, como `EntregasExport`/
 * `InventarioExport`) muestren el mismo encabezado corporativo sin repetir
 * la lógica de logo/nombre de archivo en cada controller.
 */
final class ContextoExportacion
{
    public readonly CarbonImmutable $generadoEn;

    /**
     * @param  array<string, string>  $filtros  Pares "Etiqueta" => "Valor humano", ya listos para mostrar (nunca `empresa_id: 4`, siempre "Empresa: DASTI").
     * @param  array<string, string|int>  $kpis  Pares "Etiqueta" => valor para la hoja Resumen (Excel) / bloque KPI (PDF). Vacío = sólo el bloque genérico (título/empresa/filtros/total).
     * @param  array<int, SerieGraficaReporte>  $graficas  Gráficas propias del módulo (vacío = ninguna — no todos los módulos aportan una dimensión graficable).
     * @param  array<string, string>  $kpiDescripciones  Pares "Etiqueta" => frase corta explicando ese KPI, OPCIONAL y RETROCOMPATIBLE: las claves deben calzar con `$kpis`, pero un módulo puede no pasar nada (vacío por defecto) y `kpisResueltos()` simplemente arma tarjetas sin descripción — ningún consumidor existente de `$kpis` se rompe.
     */
    public function __construct(
        public readonly string $titulo,
        public readonly ?Empresa $empresa,
        public readonly array $filtros,
        public readonly int $total,
        public readonly ?string $generadoPor = null,
        public readonly array $kpis = [],
        public readonly array $graficas = [],
        public readonly array $kpiDescripciones = [],
    ) {
        $this->generadoEn = CarbonImmutable::now();
    }

    /**
     * `$kpis` (etiqueta => valor) resuelto a tarjetas tipadas, cruzando cada
     * etiqueta con `$kpiDescripciones` cuando exista — única fábrica de
     * `KpiExportacion`, así ningún consumidor arma la tarjeta a mano.
     *
     * @return array<int, KpiExportacion>
     */
    public function kpisResueltos(): array
    {
        return array_map(
            fn (string $etiqueta, string|int $valor): KpiExportacion => new KpiExportacion(
                $etiqueta,
                $valor,
                $this->kpiDescripciones[$etiqueta] ?? null,
            ),
            array_keys($this->kpis),
            array_values($this->kpis),
        );
    }

    public function nombreEmpresa(): string
    {
        return $this->empresa->nombre_comercial ?? 'Todas las empresas';
    }

    /**
     * Marca "Generado:" ya convertida a la zona de presentación
     * (`config('uniformes.zona_horaria')`). Los blades PDF deben usar esto en
     * lugar de `->generadoEn->format(...)`, que imprimiría la hora en UTC.
     */
    public function generadoEnLocal(string $formato = 'd/m/Y H:i'): string
    {
        return FechaHora::local($this->generadoEn, $formato);
    }

    /**
     * Ruta absoluta en disco del logo, sólo para formatos que PhpSpreadsheet
     * puede incrustar como imagen (PNG/JPG). Los SVG no se dibujan: el
     * export sigue funcionando, sólo omite el logotipo (nunca un 500).
     */
    public function logoRutaAbsoluta(): ?string
    {
        $ruta = $this->rutaLogoValida();

        return $ruta !== null ? Storage::disk('public')->path($ruta) : null;
    }

    /**
     * El logo como data URI base64 para incrustarlo en el PDF sin depender
     * de que DomPDF pueda resolver una URL remota. `null` ante cualquier
     * problema de lectura — el PDF se genera igual, sin logo.
     */
    public function logoBase64(): ?string
    {
        $ruta = $this->rutaLogoValida();

        if ($ruta === null) {
            return null;
        }

        $mime = match (Str::lower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        try {
            $contenido = Storage::disk('public')->get($ruta);
        } catch (\Throwable) {
            return null;
        }

        return $contenido === null ? null : 'data:'.$mime.';base64,'.base64_encode($contenido);
    }

    /**
     * Nombre de archivo seguro para el sistema de archivos:
     * "colaboradores-alimentos-sierra-verde-2026-09-07" (sin extensión).
     */
    public function nombreArchivo(): string
    {
        $partes = array_filter([
            Str::slug($this->titulo),
            Str::slug($this->nombreEmpresa()),
            $this->generadoEn->toDateString(),
        ]);

        return implode('-', $partes);
    }

    /**
     * Sólo PNG/JPG existentes en el disco `public` — SVG y logos faltantes
     * devuelven null para que cada consumidor omita el dibujo sin fallar.
     */
    private function rutaLogoValida(): ?string
    {
        $ruta = $this->empresa?->logo_ruta;

        if ($ruta === null) {
            return null;
        }

        if (! in_array(Str::lower(pathinfo($ruta, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) {
            return null;
        }

        return Storage::disk('public')->exists($ruta) ? $ruta : null;
    }
}
