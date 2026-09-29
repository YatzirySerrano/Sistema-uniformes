<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permisos nuevos que el despliegue debe dejar DISPONIBLES en "Roles y
     * permisos" sin ejecutar `RolesPermisosSeeder` (su `syncPermissions()`
     * reescribiría la configuración real de los roles base).
     *
     * Sólo crea las filas que falten (idempotente). NO asigna nada a ningún
     * rol: quién puede redistribuir o ver la cuenta asociada de un colaborador
     * lo decide el Administrador desde la pantalla de roles.
     */
    private const PERMISOS = [
        'entregas.redistribuir',
        'colaboradores.usuario-ver',
        'colaboradores.usuario-administrar',
        'activos.condicion',
        'unidades-activo.condicion',
    ];

    public function up(): void
    {
        $existentes = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISOS)
            ->pluck('name');

        $faltantes = collect(self::PERMISOS)
            ->diff($existentes)
            ->map(fn (string $nombre): array => [
                'name' => $nombre,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($faltantes !== []) {
            DB::table('permissions')->insert($faltantes);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only: pudieron asignarse después desde "Roles y permisos".
    }
};
