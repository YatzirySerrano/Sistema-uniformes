<?php

namespace App\Enums;

use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\InventarioFisico;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use App\Models\User;

/**
 * Bloques de información del Dashboard, agrupados por el MÓDULO al que
 * representan. Cada sección se autoriza con exactamente la misma ability que
 * el `index()` de su módulo destino (la Policy `viewAny`, o el permiso
 * concreto cuando el controller destino lo usa directo), así una card visible
 * nunca apunta a un listado que responda 403.
 *
 * Deliberadamente NO hay mapeo por rol ni inferencias entre permisos
 * relacionados: `inventario.ajustar`, `activos.ver`, `inventario-fisico.ver`
 * o `almacenes.ver` no implican `inventario.ver`. Todo se resuelve contra los
 * permisos efectivos de Spatie en cada petición, así que un rol personalizado
 * o un permiso agregado/quitado se refleja en la siguiente carga sin tocar
 * código.
 */
enum SeccionDashboard: string
{
    case Colaboradores = 'colaboradores';
    case Activos = 'activos';
    case Inventario = 'inventario';
    case Entregas = 'entregas';
    case Devoluciones = 'devoluciones';
    case Almacenes = 'almacenes';
    case Unidades = 'unidades';
    case InventarioFisico = 'inventario_fisico';
    // Estructura de la organización (perfiles administrativos): cada una con
    // la `viewAny` de su listado, igual que el resto.
    case Empresas = 'empresas';
    case Sucursales = 'sucursales';
    case Contratos = 'contratos';
    case Servicios = 'servicios';

    public function autorizada(User $usuario): bool
    {
        return match ($this) {
            self::Colaboradores => $usuario->can('viewAny', Colaborador::class),
            self::Activos => $usuario->can('viewAny', Activo::class),
            // `InventarioController::index()` y `MovimientoInventarioController::index()`
            // exigen este permiso directamente (no hay Policy de saldos).
            self::Inventario => $usuario->can('inventario.ver'),
            self::Entregas => $usuario->can('viewAny', EntregaUniforme::class),
            self::Devoluciones => $usuario->can('viewAny', Devolucion::class),
            self::Almacenes => $usuario->can('viewAny', Almacen::class),
            self::Unidades => $usuario->can('viewAny', UnidadActivo::class),
            self::InventarioFisico => $usuario->can('viewAny', InventarioFisico::class),
            self::Empresas => $usuario->can('viewAny', Empresa::class),
            self::Sucursales => $usuario->can('viewAny', Sucursal::class),
            self::Contratos => $usuario->can('viewAny', Contrato::class),
            self::Servicios => $usuario->can('viewAny', Servicio::class),
        };
    }

    /**
     * ¿El filtro de sucursal acota algún dato de esta sección?
     */
    public function usaSucursal(): bool
    {
        return in_array($this, [self::Colaboradores, self::Entregas, self::Devoluciones, self::Inventario, self::Servicios], true);
    }

    /**
     * ¿El filtro de almacén acota algún dato de esta sección?
     */
    public function usaAlmacen(): bool
    {
        return in_array($this, [self::Inventario, self::Entregas, self::Devoluciones, self::Unidades], true);
    }

    /**
     * ¿La sección tiene métricas transaccionales acotadas por `desde`/`hasta`?
     */
    public function usaRangoFechas(): bool
    {
        return in_array($this, [self::Entregas, self::Devoluciones, self::Inventario], true);
    }

    /**
     * ¿Sus cifras dependen de QUÉ sucursales puede ver el usuario? (un
     * perfil restringido sólo ve las suyas, igual que en el listado).
     */
    public function usaAlcanceSucursales(): bool
    {
        return in_array($this, [self::Colaboradores, self::Sucursales, self::Servicios], true);
    }

    /**
     * @return list<self>
     */
    public static function autorizadasPara(User $usuario): array
    {
        return array_values(array_filter(self::cases(), fn (self $seccion): bool => $seccion->autorizada($usuario)));
    }
}
