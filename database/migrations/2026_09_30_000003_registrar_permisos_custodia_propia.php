<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Registra (sólo crea la fila si falta, idempotente) los permisos de esta
     * funcionalidad para que aparezcan en "Roles y permisos". NO se asignan a
     * ningún rol: los concede el Administrador. Sin `RolesPermisosSeeder`.
     */
    private const PERMISOS = [
        'activos.ver-custodia-propia',
        'entregas.redistribuir-propios',
    ];

    public function up(): void
    {
        $existentes = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISOS)->pluck('name');

        $faltantes = collect(self::PERMISOS)
            ->diff($existentes)
            ->map(fn (string $nombre): array => ['name' => $nombre, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()])
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
