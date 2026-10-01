<?php

namespace App\Acciones;

use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Excepciones\VerificacionInventarioFisicoConcurrenteException;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use App\Soporte\FechaHora;
use Illuminate\Support\Facades\DB;

/**
 * Revierte la marca de "presente" de una unidad identificada ESPERADA antes de
 * que la ronda se finalice: limpia `escaneado_en` / `escaneado_por` para que la
 * unidad vuelva a quedar pendiente de verificar. Es el reverso exacto de
 * `MarcarUnidadPresente` (el escaneo QR y la marca manual producen el mismo
 * resultado; deshacer los cubre a ambos). Nunca toca `UnidadActivo`.
 *
 * Concurrencia: el cliente envía `$escaneadoEnVista` (la verificación que
 * tenía en pantalla; sin ella sólo se admite deshacer la marca propia). Si
 * otra persona volvió a verificarla después, no se deshace a ciegas:se lanza `VerificacionInventarioFisicoConcurrenteException`
 * con la fila real. Si ya estaba desmarcada, es idempotente. Cada deshacer
 * real queda en la bitácora (quién lo deshizo y quién la había verificado).
 *
 * Cierre atómico: `lockForUpdate` de la ronda → estado `EnProceso` →
 * `lockForUpdate` del renglón de ESA ronda.
 */
class DesmarcarUnidadPresente
{
    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    public function ejecutar(
        InventarioFisico $ronda,
        InventarioFisicoUnidad $renglon,
        ?User $usuario = null,
        ?string $escaneadoEnVista = null,
    ): InventarioFisicoUnidad {
        return DB::transaction(function () use ($ronda, $renglon, $usuario, $escaneadoEnVista): InventarioFisicoUnidad {
            $bloqueada = InventarioFisico::query()->whereKey($ronda->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaEnProceso()) {
                throw new ExcepcionDeNegocioSimple('Esta ronda ya fue finalizada; no admite cambios.');
            }

            $fila = InventarioFisicoUnidad::query()
                ->where('inventario_fisico_id', $bloqueada->id)
                ->where('esperada', true)
                ->whereKey($renglon->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fila->escaneado_en === null) {
                return $fila;
            }

            // Sin versión a la vista sólo se puede deshacer la marca propia:
            // nunca se revierte a ciegas la verificación de otra persona.
            $versionVigente = $escaneadoEnVista !== null
                ? $fila->escaneado_en->toIso8601String() === $escaneadoEnVista
                : $usuario !== null && $fila->escaneado_por === $usuario->id;

            if (! $versionVigente) {
                $fila->loadMissing('escaneadoPor:id,name');

                throw new VerificacionInventarioFisicoConcurrenteException(sprintf(
                    'La verificación cambió: ahora la registró %s el %s. Revisa antes de deshacer.',
                    $fila->escaneadoPor->name ?? 'otro usuario',
                    FechaHora::local($fila->escaneado_en),
                ), $fila);
            }

            $fila->loadMissing('escaneadoPor:id,name');
            $codigo = $fila->unidad()->value('codigo');
            $verificadaPor = $fila->escaneadoPor?->name;
            $verificadaEn = $fila->escaneado_en;

            $fila->update(['escaneado_en' => null, 'escaneado_por' => null]);
            $fila->unsetRelation('escaneadoPor');

            $this->auditoria->registrar('inventario_fisico', 'unidad_deshacer_presente', [
                'empresa_id' => $bloqueada->empresa_id,
                'tipo_entidad' => InventarioFisicoUnidad::class,
                'entidad_id' => $fila->id,
                'descripcion' => sprintf(
                    'Se deshizo la verificación de la unidad %s en la ronda %s%s.',
                    $codigo ?? '',
                    $bloqueada->folio,
                    $usuario !== null ? ' (por '.$usuario->name.')' : '',
                ),
                'valores_anteriores' => [
                    'verificada_por' => $verificadaPor,
                    'verificada_en' => FechaHora::local($verificadaEn),
                ],
                'valores_nuevos' => ['verificada_por' => null, 'verificada_en' => null],
            ]);

            return $fila;
        });
    }
}
