<?php

namespace App\Policies;

use App\Enums\FinalidadCustodia;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class DevolucionPolicy
{
    public const MENSAJE_USO_PERSONAL_PROPIO = 'No puedes registrar la devolución de activos de uso personal que están bajo tu propia custodia. Debe recibirla otro usuario autorizado.';

    public const MENSAJE_SIN_CLASIFICAR_PROPIO = 'No puedes registrar la devolución de activos sin clasificación que están bajo tu propia custodia sin autorización especial. Debe recibirla otro usuario autorizado.';

    public function viewAny(User $user): bool
    {
        return $user->can('devoluciones.ver');
    }

    public function view(User $user, Devolucion $devolucion): bool
    {
        return $user->can('devoluciones.ver') && $user->puedeAccederEmpresa($devolucion->empresa_id);
    }

    public function create(User $user): bool
    {
        return $user->can('devoluciones.crear');
    }

    /**
     * ¿Puede RECIBIR (registrar) la devolución de ESTE renglón de custodia?
     * Fuente única de la regla — la usan la pantalla, el apartado y la
     * confirmación (`RegistrarDevolucion`), siempre por renglón/unidad, nunca
     * por la entrega completa:
     *
     *  - custodia ajena (la cuenta no representa al colaborador de la
     *    entrega, o no tiene ficha `User::colaborador()`) → permisos normales;
     *  - propia y "Para redistribuir" → permisos normales: devolver al
     *    almacén lo que resguardaba para otros no es recibirse a sí mismo;
     *  - propia y "Uso personal" o "Sin clasificar" (null, nunca se asume
     *    redistribución) → sólo con `devoluciones.procesar-custodia-propia`.
     *
     * Quién hizo la entrega original es irrelevante. Nunca por nombre de rol.
     */
    public function recibirRenglon(User $user, DetalleEntrega $detalle): Response
    {
        $detalle->loadMissing('entrega:id,colaborador_id');
        $colaboradorPropioId = $user->colaborador?->getKey();

        if ($colaboradorPropioId === null
            || (int) $colaboradorPropioId !== (int) $detalle->entrega?->colaborador_id
            || $detalle->finalidad === FinalidadCustodia::Redistribucion
            || $user->can('devoluciones.procesar-custodia-propia')) {
            return Response::allow();
        }

        return Response::deny($detalle->finalidad === FinalidadCustodia::UsoPersonal
            ? self::MENSAJE_USO_PERSONAL_PROPIO
            : self::MENSAJE_SIN_CLASIFICAR_PROPIO);
    }

    /**
     * Motivo por el que este usuario NO puede recibir alguno de esos
     * renglones (el primero que falle), o null si puede con todos.
     *
     * @param  iterable<DetalleEntrega>  $detalles
     */
    public static function motivoRechazoRenglones(User $user, iterable $detalles): ?string
    {
        foreach ($detalles as $detalle) {
            $respuesta = Gate::forUser($user)->inspect('recibirRenglon', [Devolucion::class, $detalle]);
            if ($respuesta->denied()) {
                return $respuesta->message();
            }
        }

        return null;
    }

    /**
     * Firma de doble conformidad: el operador con permiso, o el propio
     * colaborador titular (si tiene cuenta) confirmando su devolución.
     */
    public function confirmar(User $user, Devolucion $devolucion): bool
    {
        if (! $user->puedeAccederEmpresa($devolucion->empresa_id)) {
            return false;
        }

        $esTitular = $devolucion->colaborador?->usuario_id === $user->getKey();

        return $user->can('devoluciones.confirmar') || $esTitular;
    }
}
