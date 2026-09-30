<?php

namespace App\Policies;

use App\Enums\EstadoEntrega;
use App\Models\EntregaUniforme;
use App\Models\User;

class EntregaUniformePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('entregas.ver');
    }

    public function view(User $user, EntregaUniforme $entrega): bool
    {
        return $user->can('entregas.ver') && $user->puedeAccederEmpresa($entrega->empresa_id);
    }

    /**
     * Puede abrir "Registrar entrega" por ALGUNA de sus dos vías. Cuál de
     * ellas (y con qué fuente de bienes) lo deciden `entregarDesdeAlmacen` y
     * `redistribuir`, siempre por permisos efectivos — nunca por el nombre
     * del rol.
     */
    public function create(User $user): bool
    {
        return $this->entregarDesdeAlmacen($user) || $this->redistribuir($user);
    }

    /**
     * Salida de almacén: elige cualquier existencia del almacén (dentro de
     * su alcance de empresa) y la descuenta del inventario.
     */
    public function entregarDesdeAlmacen(User $user): bool
    {
        return $user->can('entregas.crear');
    }

    /**
     * Redistribución: sólo puede entregar lo que HOY está bajo la custodia
     * de su propio registro de colaborador (se valida en el request y bajo
     * lock en `RedistribuirCustodia`); no toca inventario.
     */
    public function redistribuir(User $user): bool
    {
        return $user->can('entregas.redistribuir');
    }

    /**
     * Clasificar / reclasificar la FINALIDAD (uso personal / para
     * redistribuir) de un renglón que sigue bajo custodia — p. ej. los
     * históricos "sin clasificar". Es la misma autoridad que decide la
     * finalidad al entregar desde almacén (`entregas.crear`) y queda auditada.
     */
    public function clasificarFinalidad(User $user, EntregaUniforme $entrega): bool
    {
        return $user->can('entregas.crear') && $user->puedeAccederEmpresa($entrega->empresa_id);
    }

    /**
     * Además de lo recibido "para redistribuir", puede reasignar bienes de su
     * custodia de USO PERSONAL (o sin clasificar): capacidad explícita y
     * separada para que nadie entregue por error su laptop o su vehículo.
     */
    public function redistribuirPropios(User $user): bool
    {
        return $this->redistribuir($user) && $user->can('entregas.redistribuir-propios');
    }

    public function firmar(User $user, EntregaUniforme $entrega): bool
    {
        if (! $user->puedeAccederEmpresa($entrega->empresa_id)) {
            return false;
        }

        // El propio colaborador (si tiene cuenta) puede firmar su entrega.
        $esTitular = $entrega->colaborador?->usuario_id === $user->getKey();

        return $user->can('acuses.firmar') || $esTitular;
    }

    /**
     * Una entrega FIRMADA (o corregida, o anulada) es un documento histórico
     * inmutable: no se corrige. Sólo una entrega todavía `PendienteFirma`
     * (camino de firma diferida legado) admite corrección administrativa.
     */
    public function corregir(User $user, EntregaUniforme $entrega): bool
    {
        return $user->can('entregas.corregir')
            && $user->puedeAccederEmpresa($entrega->empresa_id)
            && $entrega->estado === EstadoEntrega::PendienteFirma;
    }
}
