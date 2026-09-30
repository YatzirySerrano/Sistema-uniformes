<?php

namespace App\Servicios;

use App\Models\AcuseDevolucion;
use App\Models\AcuseRecepcion;
use App\Models\Evidencia;
use App\Soporte\ValidadorFirma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Firma de QUIEN RECIBE (entrega) o QUIEN DEVUELVE (devolución), con sus dos
 * métodos posibles — una sola fuente por acuse:
 *
 * - `dibujada`: data URI del pad (validada por `ValidadorFirma::validar`).
 * - `archivo`: imagen o PDF subido (firma a distancia). La imagen se
 *   normaliza a PNG y se usa como firma visual; el PDF se conserva íntegro.
 *   En ambos casos el ARCHIVO ORIGINAL queda como `Evidencia`
 *   (`origen = firma_archivo`) del acuse: nombre original, MIME real, peso,
 *   hash, quién lo subió y cuándo — nunca sólo un path sin contexto.
 *
 * Mismo patrón que la firma de siempre: los archivos se escriben ANTES de
 * la transacción (`preparar`), la fila se crea DENTRO (`adjuntar`) y, si
 * algo falla, el llamador borra lo escrito (`descartar`).
 */
class ServicioFirmaColaborador
{
    private const DISCO = 'local';

    public function __construct(private readonly ValidadorFirma $validador) {}

    /**
     * Valida y escribe la firma en disco privado.
     *
     * @return array{ruta_firma: string, hash_firma: string, rutas: list<string>, archivo: array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, es_pdf: bool}|null}
     */
    public function preparar(string $firmaBase64, ?UploadedFile $archivo, int $empresaId): array
    {
        if ($archivo === null) {
            $firma = $this->validador->validar($firmaBase64);
            $ruta = sprintf('firmas/%d/%s.png', $empresaId, Str::uuid());
            Storage::disk(self::DISCO)->put($ruta, $firma['binario']);

            return ['ruta_firma' => $ruta, 'hash_firma' => hash('sha256', $firma['binario']), 'rutas' => [$ruta], 'archivo' => null];
        }

        $validado = $this->validador->validarArchivo($archivo);
        $base = sprintf('firmas/%d/%s', $empresaId, Str::uuid());
        $rutaOriginal = "{$base}-original.{$validado['extension']}";
        Storage::disk(self::DISCO)->put($rutaOriginal, $validado['original']);

        // PDF: la "firma" del acuse ES el documento (no se rasteriza). Imagen:
        // copia normalizada a PNG para incrustarla como firma visual.
        $rutaFirma = $rutaOriginal;
        if (! $validado['es_pdf']) {
            $rutaFirma = "{$base}.png";
            Storage::disk(self::DISCO)->put($rutaFirma, $validado['binario_firma']);
        }

        return [
            'ruta_firma' => $rutaFirma,
            'hash_firma' => hash('sha256', $validado['binario_firma']),
            'rutas' => array_values(array_unique([$rutaOriginal, $rutaFirma])),
            'archivo' => [
                'ruta' => $rutaOriginal,
                'nombre_original' => $validado['nombre_original'],
                'mime' => $validado['mime'],
                'extension' => $validado['extension'],
                'peso_bytes' => $validado['peso_bytes'],
                'hash_sha256' => hash('sha256', $validado['original']),
                'es_pdf' => $validado['es_pdf'],
            ],
        ];
    }

    /**
     * Registra el archivo original de una firma a distancia como evidencia
     * del acuse (dentro de la transacción del llamador). No hace nada para
     * una firma dibujada.
     *
     * @param  array{archivo: array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, es_pdf: bool}|null}  $preparada
     */
    public function adjuntar(AcuseRecepcion|AcuseDevolucion $acuse, array $preparada, ?int $usuarioId): void
    {
        $archivo = $preparada['archivo'];

        if ($archivo === null) {
            return;
        }

        $acuse->firmaArchivo()->create([
            'disco' => self::DISCO,
            'ruta' => $archivo['ruta'],
            'nombre_original' => $archivo['nombre_original'],
            'mime' => $archivo['mime'],
            'extension' => $archivo['extension'],
            'peso_bytes' => $archivo['peso_bytes'],
            'hash_sha256' => $archivo['hash_sha256'],
            'origen' => Evidencia::ORIGEN_FIRMA_ARCHIVO,
            'subido_por' => $usuarioId,
        ]);
    }

    /**
     * @param  array{rutas: list<string>}  $preparada
     */
    public function descartar(array $preparada): void
    {
        Storage::disk(self::DISCO)->delete($preparada['rutas']);
    }

    /**
     * Texto para la bitácora: deja claro el método de la firma.
     *
     * @param  array{archivo: array{nombre_original: string}|null}  $preparada
     */
    public function descripcionMetodo(array $preparada): string
    {
        return $preparada['archivo'] === null
            ? 'firma dibujada'
            : 'firma por archivo «'.$preparada['archivo']['nombre_original'].'»';
    }
}
