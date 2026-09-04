<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redefinición funcional: "Serializado" deja de ser un concepto visible
     * para el usuario. `TipoControlActivo::Serializado` (valor `'serializado'`)
     * se renombra a `SeguimientoIndividual` (valor `'individual'`). Sólo
     * afecta datos demo (no hay despliegue en producción todavía).
     *
     * Forward-only.
     */
    public function up(): void
    {
        DB::table('activos')->where('tipo_control', 'serializado')->update(['tipo_control' => 'individual']);
    }

    public function down(): void
    {
        DB::table('activos')->where('tipo_control', 'individual')->update(['tipo_control' => 'serializado']);
    }
};
