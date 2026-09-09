<?php

namespace App\Acciones;

use App\Models\AcuseRecepcion;
use App\Models\EntregaUniforme;
use Illuminate\Support\Facades\DB;

/**
 * Flujo ÚNICO de entrega: registrar y firmar son UNA sola operación. Nunca
 * deja una entrega nueva en `PendienteFirma` esperando firma posterior.
 *
 * Orquesta, SIN duplicar reglas:
 *   1. `CrearEntregaUniforme` — crea la entrega y descuenta inventario.
 *   2. `ConfirmarAcuseRecepcion::confirmarEnTransaccion()` — valida AMBAS
 *      firmas + aceptación, congela el snapshot, guarda las firmas en disco
 *      privado y marca la entrega FIRMADA.
 *
 * Ambos pasos corren dentro de UNA transacción: si la firma es inválida,
 * falta la aceptación o no alcanzan las existencias, se revierte TODO — no
 * queda inventario descontado ni entrega huérfana. El PDF y el correo se
 * materializan/encolan DESPUÉS del commit (`finalizarAcuse()`), igual que en
 * el flujo histórico.
 *
 * El estado `EstadoEntrega::PendienteFirma` sigue existiendo: es transitorio
 * dentro de esta transacción (lo pone `CrearEntregaUniforme` y lo cambia
 * `confirmarEnTransaccion` antes del commit) y se conserva para las entregas
 * históricas y su ruta de firma diferida.
 */
class RegistrarEntregaFirmada
{
    public function __construct(
        private readonly CrearEntregaUniforme $crearEntrega,
        private readonly ConfirmarAcuseRecepcion $confirmarAcuse,
    ) {}

    /**
     * @param  array<int, array{activo_id: int|string, talla_id?: int|string|null, cantidad: int|string}>  $activos
     * @param  array<int, array{unidad_activo_id: int|string}>  $unidades
     * @param  array<int, array{conjunto_id: int|string, cantidad: int|string, variantes?: array<int|string, int|string|null>}>  $conjuntos
     */
    public function ejecutar(
        int $colaboradorId,
        int $almacenId,
        int $encargadoId,
        string $fechaEntrega,
        array $activos,
        array $unidades,
        array $conjuntos,
        ?string $notas,
        ?int $servicioId,
        string $firmaColaboradorBase64,
        string $firmaOperadorBase64,
        bool $aceptacion,
        ?string $ip,
        ?string $userAgent,
    ): AcuseRecepcion {
        /** @var array{entrega: EntregaUniforme, acuse: AcuseRecepcion} $resultado */
        $resultado = DB::transaction(function () use (
            $colaboradorId, $almacenId, $encargadoId, $fechaEntrega,
            $activos, $unidades, $conjuntos, $notas, $servicioId,
            $firmaColaboradorBase64, $firmaOperadorBase64, $aceptacion, $ip, $userAgent,
        ): array {
            $entrega = $this->crearEntrega->ejecutar(
                $colaboradorId,
                $almacenId,
                $encargadoId,
                $fechaEntrega,
                $activos,
                $unidades,
                $conjuntos,
                $notas,
                $servicioId,
            );

            $acuse = $this->confirmarAcuse->confirmarEnTransaccion(
                $entrega,
                $firmaColaboradorBase64,
                $firmaOperadorBase64,
                $aceptacion,
                $encargadoId,
                $ip,
                $userAgent,
            );

            return ['entrega' => $entrega, 'acuse' => $acuse];
        });

        return $this->confirmarAcuse->finalizarAcuse($resultado['acuse'], $resultado['entrega']);
    }
}
