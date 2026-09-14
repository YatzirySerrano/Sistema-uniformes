<?php

namespace App\Acciones;

use App\Models\AcuseTraspaso;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flujo ÚNICO de traspaso: crear el traspaso y firmarlo son UNA sola
 * operación atómica — un traspaso NUNCA existe sin firma. Espejo de
 * `RegistrarEntregaFirmada`/`RegistrarDevolucionFirmada`, pero con UNA sola
 * firma (el responsable que confirma) en vez de dos.
 *
 * Orquesta, sin duplicar reglas de negocio:
 *   1. Valida la firma (`ConfirmarAcuseTraspaso::validarFirma`) y la escribe
 *      en disco privado ANTES de abrir la transacción (igual que las firmas
 *      de Entregas/Devoluciones). Si algo falla después, el `catch` borra el
 *      archivo huérfano — "un archivo → un dueño".
 *   2. Dentro de UNA `DB::transaction` coordinadora:
 *      a. `RegistrarTraspasoInventario::crearYRegistrar()` — crea el
 *         encabezado, sus renglones y mueve el inventario real (salida
 *         origen + entrada destino, con los locks que ya aplica
 *         `ServicioInventario`).
 *      b. `ConfirmarAcuseTraspaso::confirmarEnTransaccion()` — crea el
 *         `AcuseTraspaso` con el snapshot congelado.
 *   3. Si CUALQUIER paso falla (firma inválida, stock insuficiente, unidad no
 *      disponible, homologación ambigua…) se revierte TODO: no queda
 *      traspaso sin firma, ni movimiento sin su acuse, ni stock movido a
 *      medias.
 *
 * El PDF se materializa DESPUÉS del commit (`ConfirmarAcuseTraspaso::finalizarAcuse()`);
 * un fallo ahí no revierte el traspaso ni el acuse ya firmados.
 */
class RegistrarTraspasoFirmado
{
    public function __construct(
        private readonly RegistrarTraspasoInventario $registrarTraspaso,
        private readonly ConfirmarAcuseTraspaso $confirmarAcuse,
    ) {}

    /**
     * @param  array<int, array{
     *     control: string,
     *     activo_origen_id: int|string,
     *     talla_id?: int|string|null,
     *     cantidad?: int|string|null,
     *     unidad_ids?: array<int, int|string>|null,
     *     activo_destino_id?: int|string|null,
     * }>  $renglones
     */
    public function ejecutar(
        int $empresaOrigenId,
        int $almacenOrigenId,
        int $empresaDestinoId,
        int $almacenDestinoId,
        array $renglones,
        User $firmante,
        string $firmaBase64,
        ?string $motivo,
        ?string $notas,
        ?string $ip,
        ?string $userAgent,
    ): AcuseTraspaso {
        $firma = $this->confirmarAcuse->validarFirma($firmaBase64);
        $hashFirma = hash('sha256', $firma['binario']);
        $ruta = sprintf('firmas/traspasos/%d/%s.png', $empresaOrigenId, Str::uuid());
        Storage::disk('local')->put($ruta, $firma['binario']);

        try {
            $acuse = DB::transaction(function () use (
                $empresaOrigenId, $almacenOrigenId, $empresaDestinoId, $almacenDestinoId,
                $renglones, $firmante, $motivo, $notas, $ruta, $hashFirma, $ip, $userAgent,
            ): AcuseTraspaso {
                $traspaso = $this->registrarTraspaso->crearYRegistrar(
                    $empresaOrigenId, $almacenOrigenId, $empresaDestinoId, $almacenDestinoId,
                    $renglones, $firmante->getKey(), $motivo, $notas,
                );

                return $this->confirmarAcuse->confirmarEnTransaccion(
                    $traspaso, $ruta, $hashFirma, $firmante, $ip, $userAgent,
                );
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($ruta);

            throw $e;
        }

        return $this->confirmarAcuse->finalizarAcuse($acuse);
    }
}
