<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * La gestión de CONDICIÓN física deja de depender de permisos ajenos:
     *
     *  - Existencias por cantidad (dañado / baja / robo): antes exigía
     *    `inventario.ajustar` (corregir stock) → ahora `activos.condicion`.
     *  - Unidades individuales (dañada / pérdida / recuperar / restaurar):
     *    antes exigía `unidades-activo.administrar` (registrar y dar de baja)
     *    → ahora `unidades-activo.condicion`.
     *
     * Migración de DATOS forward-only: cualquier rol (base o personalizado)
     * que HOY tiene el permiso anterior recibe el nuevo, para que el
     * despliegue no le quite en silencio una capacidad que ya ejercía. No
     * quita nada. Idempotente. Sin roles sembrados (BD recién creada) es un
     * no-op: `RolesPermisosSeeder` crea los permisos después.
     */
    public function up(): void
    {
        $equivalencias = [
            'inventario.ajustar' => 'activos.condicion',
            'unidades-activo.administrar' => 'unidades-activo.condicion',
        ];

        foreach ($equivalencias as $anterior => $nuevo) {
            $idAnterior = DB::table('permissions')->where('name', $anterior)->where('guard_name', 'web')->value('id');

            if ($idAnterior === null) {
                continue;
            }

            $idNuevo = DB::table('permissions')->where('name', $nuevo)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $nuevo,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $rolesConAnterior = DB::table('role_has_permissions')->where('permission_id', $idAnterior)->pluck('role_id');
            $rolesYaConNuevo = DB::table('role_has_permissions')->where('permission_id', $idNuevo)->pluck('role_id');

            $filas = $rolesConAnterior->diff($rolesYaConNuevo)
                ->map(fn ($rolId): array => ['permission_id' => $idNuevo, 'role_id' => $rolId])
                ->values()
                ->all();

            if ($filas !== []) {
                DB::table('role_has_permissions')->insert($filas);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only: quitar los permisos nuevos podría retirar asignaciones
        // hechas después a mano desde "Roles y permisos".
    }
};
