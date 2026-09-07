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
     */
    public function __construct(
        public readonly string $titulo,
        public readonly ?Empresa $empresa,
        public readonly array $filtros,
        public readonly int $total,
    ) {
        $this->generadoEn = CarbonImmutable::now();
    }

    public function nombreEmpresa(): string
    {
        return $this->empresa->nombre_comercial ?? 'Todas las empresas';
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
