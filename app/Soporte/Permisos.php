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
        'prendas' => [
            'etiqueta' => 'Prendas y tallas',
            'permisos' => [
                'prendas.ver' => 'Ver prendas',
                'prendas.crear' => 'Crear prendas',
                'prendas.editar' => 'Editar prendas',
                'tallas.administrar' => 'Administrar tallas',
            ],
        ],
        'inventario' => [
            'etiqueta' => 'Inventario',
            'permisos' => [
                'inventario.ver' => 'Ver inventario y movimientos',
                'inventario.entrada' => 'Registrar entradas',
                'inventario.ajustar' => 'Ajustar existencias',
                'inventario.minimos' => 'Configurar mínimos',
                'inventario.transferir' => 'Transferir entre sucursales',
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
            RolSistema::Administrador->value => array_values(array_filter(
                self::todos(),
                fn (string $p): bool => ! in_array($p, ['empresas.crear', 'empresas.administrar'], true),
            )),
            RolSistema::Supervisor->value => [
                'sucursales.ver',
                'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar', 'colaboradores.importar',
                'prendas.ver',
                'inventario.ver', 'inventario.entrada', 'inventario.minimos',
                'entregas.ver', 'entregas.crear',
                'acuses.ver', 'acuses.firmar', 'acuses.ver-pdf', 'acuses.ver-firma',
                'devoluciones.ver', 'devoluciones.crear',
                'reportes.ver', 'reportes.exportar',
            ],
            RolSistema::Encargado->value => [
                'colaboradores.ver',
                'prendas.ver',
                'inventario.ver',
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
