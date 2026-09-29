<?php

use App\Enums\RolSistema;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot HISTÓRICO e inmutable de "esta acción la realizó un
     * Superadministrador". Antes la privacidad de la bitácora dependía del
     * rol ACTUAL del actor (join a `model_has_roles`), así que degradar o
     * eliminar a un Superadministrador (FK `usuario_id` con `nullOnDelete`)
     * volvía visibles sus acciones pasadas.
     *
     * Tri-estado a propósito (nunca se inventan valores):
     *  - `true`: el actor era Superadministrador (filas nuevas, o evidencia
     *    en el backfill: rol actual o un cambio de roles auditado que lo
     *    muestra con ese rol en algún momento).
     *  - `false`: no lo era (filas nuevas) o la fila no tiene actor alguno
     *    (sin `usuario_id` ni nombre: acción de sistema).
     *  - `null`: fila histórica sin evidencia concluyente. `BitacoraAuditoria::
     *    scopeVisiblePara()` la trata de forma conservadora: sólo es visible
     *    para un no-Superadministrador si el actor sigue existiendo y hoy no
     *    es Superadministrador; con el actor eliminado queda oculta.
     */
    public function up(): void
    {
        Schema::table('bitacora_auditoria', function (Blueprint $table): void {
            $table->boolean('realizada_por_superadministrador')->nullable()->after('nombre_usuario_snapshot');
            $table->index('realizada_por_superadministrador');
        });

        $rolSuperadmin = DB::table('roles')->where('name', RolSistema::Superadministrador->value)->value('id');

        $superadminsActuales = $rolSuperadmin === null ? collect() : DB::table('model_has_roles')
            ->where('role_id', $rolSuperadmin)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id');

        $superadminsSegunCambiosDeRol = DB::table('bitacora_auditoria')
            ->where('modulo', 'usuarios')
            ->where('tipo_entidad', User::class)
            ->where(fn ($q) => $q
                ->where('valores_anteriores', 'like', '%"'.RolSistema::Superadministrador->value.'"%')
                ->orWhere('valores_nuevos', 'like', '%"'.RolSistema::Superadministrador->value.'"%'))
            ->whereNotNull('entidad_id')
            ->pluck('entidad_id');

        $idsConEvidencia = $superadminsActuales->merge($superadminsSegunCambiosDeRol)->unique()->values();

        foreach ($idsConEvidencia->chunk(500) as $lote) {
            DB::table('bitacora_auditoria')
                ->whereIn('usuario_id', $lote->all())
                ->update(['realizada_por_superadministrador' => true]);
        }

        DB::table('bitacora_auditoria')
            ->whereNull('usuario_id')
            ->whereNull('nombre_usuario_snapshot')
            ->update(['realizada_por_superadministrador' => false]);
    }

    public function down(): void
    {
        Schema::table('bitacora_auditoria', function (Blueprint $table): void {
            $table->dropIndex(['realizada_por_superadministrador']);
            $table->dropColumn('realizada_por_superadministrador');
        });
    }
};
