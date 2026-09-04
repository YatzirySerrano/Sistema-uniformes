<?php

namespace App\Soporte;

use App\Enums\RolSistema;

/**
 * Catálogo central de permisos granulares. Los identificadores usan la notación
 * "recurso.accion" en español. Esta clase es la fuente de verdad para el seeder
 * y para la matriz de roles y permisos de la interfaz.
 */
final class Permisos
{
    /**
     * @var array<string, array{etiqueta: string, permisos: array<string, string>}>
     */
    public const GRUPOS = [
        'empresas' => [
            'etiqueta' => 'Empresas',
            'permisos' => [
                'empresas.ver' => 'Ver empresas',
                'empresas.crear' => 'Crear empresas',
                'empresas.editar' => 'Editar empresas',
                'empresas.administrar' => 'Administrar empresas (global)',
            ],
        ],
        'sucursales' => [
            'etiqueta' => 'Sucursales',
            'permisos' => [
                'sucursales.ver' => 'Ver sucursales',
                'sucursales.crear' => 'Crear sucursales',
                'sucursales.editar' => 'Editar sucursales',
                'sucursales.desactivar' => 'Activar / desactivar sucursales',
            ],
        ],
        'usuarios' => [
            'etiqueta' => 'Usuarios',
            'permisos' => [
                'usuarios.ver' => 'Ver usuarios',
                'usuarios.crear' => 'Crear usuarios',
                'usuarios.editar' => 'Editar usuarios',
                'usuarios.desactivar' => 'Activar / desactivar usuarios',
            ],
        ],
        'roles' => [
            'etiqueta' => 'Roles y permisos',
            'permisos' => [
                'roles.ver' => 'Ver roles',
                'roles.crear' => 'Crear roles',
                'roles.editar' => 'Editar roles',
                'roles.asignar' => 'Asignar roles y permisos a usuarios',
            ],
        ],
        'colaboradores' => [
            'etiqueta' => 'Colaboradores',
            'permisos' => [
                'colaboradores.ver' => 'Ver colaboradores',
                'colaboradores.crear' => 'Crear colaboradores',
                'colaboradores.editar' => 'Editar colaboradores',
                'colaboradores.desactivar' => 'Activar / desactivar colaboradores',
                'colaboradores.importar' => 'Importar colaboradores desde Excel',
            ],
        ],
        'areas' => [
            'etiqueta' => 'Áreas / Departamentos',
            'permisos' => [
                'areas.ver' => 'Ver áreas / departamentos',
                'areas.crear' => 'Crear áreas / departamentos',
                'areas.editar' => 'Editar áreas / departamentos',
                'areas.desactivar' => 'Activar / desactivar áreas / departamentos',
            ],
        ],
        'almacenes' => [
            'etiqueta' => 'Almacenes',
            'permisos' => [
                'almacenes.ver' => 'Ver almacenes',
                'almacenes.crear' => 'Crear almacenes',
                'almacenes.editar' => 'Editar almacenes',
                'almacenes.administrar' => 'Administrar almacenes (estado y sucursales abastecidas)',
            ],
        ],
        'activos' => [
            'etiqueta' => 'Activos, tipos y categorías',
            'permisos' => [
                'activos.ver' => 'Ver activos',
                'activos.crear' => 'Crear activos',
                'activos.editar' => 'Editar activos',
                'activos.administrar' => 'Administrar activos (estado y tipos)',
                'tallas.administrar' => 'Administrar variantes / tallas',
                'tipos-activo.administrar' => 'Administrar el catálogo de tipos de activo',
                'categorias-activo.administrar' => 'Administrar el catálogo de categorías de activo',
            ],
        ],
        'inventario' => [
            'etiqueta' => 'Inventario por almacén',
            'permisos' => [
                'inventario.ver' => 'Ver inventario y movimientos',
                'inventario.entrada' => 'Registrar entradas de almacén',
                'inventario.ajustar' => 'Ajustar existencias de almacén',
                'inventario.minimos' => 'Configurar mínimos por almacén',
                'inventario.transferir' => 'Transferir entre almacenes',
            ],
        ],
        'unidades-activo' => [
            'etiqueta' => 'Unidades de seguimiento individual',
            'permisos' => [
                'unidades-activo.ver' => 'Ver unidades y sus códigos',
                'unidades-activo.administrar' => 'Registrar, corregir y dar de baja unidades',
            ],
        ],
        'conjuntos' => [
            'etiqueta' => 'Conjuntos',
            'permisos' => [
                'conjuntos.ver' => 'Ver conjuntos',
                'conjuntos.crear' => 'Crear conjuntos',
                'conjuntos.editar' => 'Editar conjuntos',
                'conjuntos.administrar' => 'Activar / desactivar conjuntos',
            ],
        ],
        'entregas' => [
            'etiqueta' => 'Entregas',
            'permisos' => [
                'entregas.ver' => 'Ver entregas',
                'entregas.crear' => 'Registrar entregas',
                'entregas.corregir' => 'Corregir entregas firmadas',
            ],
        ],
        'acuses' => [
            'etiqueta' => 'Acuses y comprobantes',
            'permisos' => [
                'acuses.ver' => 'Ver acuses',
                'acuses.firmar' => 'Capturar la firma de recepción',
                'acuses.ver-pdf' => 'Ver y descargar comprobantes PDF',
                'acuses.ver-firma' => 'Ver la imagen de la firma',
            ],
        ],
        'devoluciones' => [
            'etiqueta' => 'Devoluciones',
            'permisos' => [
                'devoluciones.ver' => 'Ver devoluciones',
                'devoluciones.crear' => 'Registrar devoluciones',
            ],
        ],
        'reportes' => [
            'etiqueta' => 'Reportes',
            'permisos' => [
                'reportes.ver' => 'Ver reportes',
                'reportes.exportar' => 'Exportar reportes',
            ],
        ],
        'auditoria' => [
            'etiqueta' => 'Auditoría',
            'permisos' => [
                'auditoria.ver' => 'Ver la bitácora de auditoría',
            ],
        ],
        'configuracion' => [
            'etiqueta' => 'Personalización de empresa',
            'permisos' => [
                'configuracion-empresa.ver' => 'Ver la personalización de la empresa',
                'configuracion-empresa.editar' => 'Editar branding y datos de la empresa',
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function todos(): array
    {
        $todos = [];
        foreach (self::GRUPOS as $grupo) {
            foreach ($grupo['permisos'] as $clave => $etiqueta) {
                $todos[] = $clave;
            }
        }

        return $todos;
    }

    /**
     * Permisos por defecto para cada rol base del sistema.
     *
     * @return array<string, list<string>|string>
     */
    public static function porRol(): array
    {
        return [
            RolSistema::Superadministrador->value => '*',
            // La dirección del cliente administra todos los módulos de negocio,
            // incluidas todas las empresas de la plataforma.
            RolSistema::Administrador->value => self::todos(),
            RolSistema::Supervisor->value => [
                'empresas.ver',
                'sucursales.ver',
                'almacenes.ver',
                'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar', 'colaboradores.importar',
                'areas.ver', 'areas.crear', 'areas.editar',
                'activos.ver',
                'inventario.ver', 'inventario.entrada', 'inventario.minimos',
                'unidades-activo.ver', 'unidades-activo.administrar',
                'conjuntos.ver', 'conjuntos.crear', 'conjuntos.editar',
                'entregas.ver', 'entregas.crear',
                'acuses.ver', 'acuses.firmar', 'acuses.ver-pdf', 'acuses.ver-firma',
                'devoluciones.ver', 'devoluciones.crear',
                'reportes.ver', 'reportes.exportar',
            ],
            RolSistema::Encargado->value => [
                'colaboradores.ver',
                'areas.ver',
                'almacenes.ver',
                'activos.ver',
                'inventario.ver',
                'unidades-activo.ver',
                'conjuntos.ver',
                'entregas.ver', 'entregas.crear',
                'acuses.ver', 'acuses.firmar', 'acuses.ver-pdf',
                'devoluciones.ver', 'devoluciones.crear',
            ],
            RolSistema::Colaborador->value => [
                'acuses.firmar',
            ],
        ];
    }
}
