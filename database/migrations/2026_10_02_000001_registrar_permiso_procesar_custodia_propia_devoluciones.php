<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Registra (sólo crea la fila si falta, idempotente) el permiso que
     * permite recibir la devolución de la propia custodia
     * (`DevolucionPolicy::recibirDe`) para que aparezca y pueda asignarse en
     * "Roles y permisos". NO se asigna a ningún rol ni usuario: lo concede el
     * Administrador. Sin `RolesPermisosSeeder`.
     */
    private const PERMISOS = [
        'devoluciones.procesar-custodia-propia',
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
        // Forward-only: pudo asignarse después desde "Roles y permisos".
    }
};
