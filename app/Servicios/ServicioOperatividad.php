<?php

namespace App\Servicios;

use App\Models\Activo;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Model;

/**
 * Operatividad EFECTIVA de una entidad (Fase 7 — blindaje multicausa). El
 * propio flag `activo`/`activa` NO basta: una Sucursal/Área/Activo/Conjunto
 * también depende de que su Empresa esté operativa; un Colaborador además de
 * su Sucursal; un Conjunto de que TODOS sus componentes reales lo estén.
 * Todo se calcula EN VIVO a partir del estado actual — nunca se persiste
 * "operativo" ni se generan registros nuevos — para tener una única fuente de
 * verdad que usan por igual `ServicioCascadaSuspension::reactivarSeleccionados()`,
 * los controllers y el checklist del frontend (`PanelSuspendidos.vue`).
 */
class ServicioOperatividad
{
    /**
     * ¿La entidad está REALMENTE operativa ahora mismo? Incluye su propio
     * flag y, recursivamente, sus dependencias obligatorias.
     */
    public function esOperativo(Model $entidad): bool
    {
        return $this->flagPropio($entidad) && $this->dependenciasNoOperativas($entidad) === [];
    }

    /**
     * Motivos por los que las DEPENDENCIAS de la entidad (nunca su propio
     * flag) le impiden operar. Se usa para decidir si una suspensión propia
     * puede levantarse de verdad: el flag propio se ignora a propósito,
     * porque es justo lo que se está a punto de cambiar al reactivar.
     *
     * @return list<string>
     */
    public function dependenciasNoOperativas(Model $entidad): array
    {
        return match (true) {
            $entidad instanceof Sucursal => $this->motivosDeEmpresa($entidad->empresa),
            $entidad instanceof Area => $this->motivosDeEmpresa($entidad->empresa),
            $entidad instanceof Activo => $this->motivosDeEmpresa($entidad->empresa),
            $entidad instanceof Colaborador => $this->motivosDeColaborador($entidad),
            $entidad instanceof Conjunto => $this->motivosDeConjunto($entidad),
            default => [],
        };
    }

    private function flagPropio(Model $entidad): bool
    {
        return (bool) ($entidad->getAttribute('activa') ?? $entidad->getAttribute('activo') ?? false);
    }

    /**
     * @return list<string>
     */
    private function motivosDeEmpresa(?Empresa $empresa): array
    {
        if ($empresa === null || $this->esOperativo($empresa)) {
            return [];
        }

        return ["La empresa «{$empresa->nombre_comercial}» continúa inactiva."];
    }

    /**
     * @return list<string>
     */
    private function motivosDeColaborador(Colaborador $colaborador): array
    {
        $motivos = $this->motivosDeEmpresa($colaborador->empresa);

        if ($colaborador->sucursal !== null && ! $this->esOperativo($colaborador->sucursal)) {
            $motivos[] = "La sucursal «{$colaborador->sucursal->nombre}» continúa inactiva.";
        }

        return $motivos;
    }

    /**
     * @return list<string>
     */
    private function motivosDeConjunto(Conjunto $conjunto): array
    {
        $motivos = $this->motivosDeEmpresa($conjunto->empresa);

        foreach ($conjunto->componentes()->with('activo')->get() as $componente) {
            /** @var ConjuntoComponente $componente */
            $activo = $componente->activo;

            if ($activo !== null && ! $this->esOperativo($activo)) {
                $motivos[] = "El activo «{$activo->nombre}» continúa inactivo.";
            }
        }

        return array_values(array_unique($motivos));
    }
}
