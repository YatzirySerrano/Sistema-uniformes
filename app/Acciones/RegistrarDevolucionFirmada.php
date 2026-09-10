<?php

namespace App\Acciones;

use App\Models\AcuseDevolucion;
use App\Models\Devolucion;
use Illuminate\Support\Facades\DB;

/**
 * Flujo ÚNICO de devolución (wizard): registrar y firmar son UNA sola
 * operación atómica. Espejo de `RegistrarEntregaFirmada`.
 *
 * Orquesta, SIN duplicar reglas de negocio:
 *   1. `RegistrarDevolucion::crearYRegistrar()` — crea la `Devolucion`
 *      (`pendiente_firma`), sus `DetalleDevolucion` y adjunta las evidencias.
 *      NO abre transacción propia.
 *   2. `ConfirmarAcuseDevolucion::confirmarEnTransaccion()` — valida AMBAS
 *      firmas + aceptación, reingresa el inventario / actualiza las unidades,
 *      congela el snapshot, guarda las firmas en disco privado y marca la
 *      devolución CONFIRMADA.
 *
 * Todo dentro de UNA `DB::transaction` coordinadora: si algo falla (firma
 * inválida, sin aceptación, unidad ya no asignada, cantidad excedida…) se
 * revierte TODO — no queda reingreso de inventario ni devolución huérfana.
 * El PDF y el correo se materializan/encolan DESPUÉS del commit
 * (`finalizarAcuse()`).
 *
 * Limpieza de archivos (un archivo → un dueño):
 *   - Evidencias: las escribe/limpia el CONTROLLER (`DevolucionController::store`).
 *   - Firmas: las escribe/limpia `ConfirmarAcuseDevolucion::confirmarEnTransaccion`
 *     en su propio `try/catch`. Esta acción NO toca archivos.
 */
class RegistrarDevolucionFirmada
{
    public function __construct(
        private readonly RegistrarDevolucion $registrarDevolucion,
        private readonly ConfirmarAcuseDevolucion $confirmarAcuse,
    ) {}

    /**
     * @param  array<int, array{detalle_entrega_id: int|string, cantidad: int|string, condicion: string}>  $activos
     * @param  array<int, array{detalle_entrega_id: int|string, condicion: string}>  $unidades
     * @param  array<string, array{ruta: string, nombre_original: string, mime: string, extension: string, peso_bytes: int, hash_sha256: string, origen: string}>  $evidencias
     */
    public function ejecutar(
        int $entregaId,
        int $almacenId,
        string $fecha,
        array $activos,
        array $unidades,
        ?int $registradaPor,
        ?string $motivo,
        ?string $notas,
        string $firmaColaboradorBase64,
        string $firmaOperadorBase64,
        bool $aceptacion,
        ?string $ip,
        ?string $userAgent,
        array $evidencias = [],
    ): AcuseDevolucion {
        /** @var array{devolucion: Devolucion, acuse: AcuseDevolucion} $resultado */
        $resultado = DB::transaction(function () use (
            $entregaId, $almacenId, $fecha, $activos, $unidades, $registradaPor,
            $motivo, $notas, $evidencias,
            $firmaColaboradorBase64, $firmaOperadorBase64, $aceptacion, $ip, $userAgent,
        ): array {
            $devolucion = $this->registrarDevolucion->crearYRegistrar(
                $entregaId, $almacenId, $fecha, $activos, $unidades, $registradaPor, $motivo, $notas, $evidencias,
            );

            $acuse = $this->confirmarAcuse->confirmarEnTransaccion(
                $devolucion,
                $firmaColaboradorBase64,
                $firmaOperadorBase64,
                $aceptacion,
                $registradaPor,
                $ip,
                $userAgent,
            );

            return ['devolucion' => $devolucion, 'acuse' => $acuse];
        });

        return $this->confirmarAcuse->finalizarAcuse($resultado['acuse'], $resultado['devolucion']);
    }
}
