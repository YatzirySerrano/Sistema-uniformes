<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soporte de activos **por cantidad sin variante** (Mouse, Cable, Gorra
     * unitalla…). El inventario sigue exigiendo `talla_id` NOT NULL para que los
     * índices únicos sean simples; por eso cada empresa tiene una **talla
     * comodín** (`es_comodin = true`) que representa "sin variante".
     *
     * La talla comodín NO se muestra en la administración de variantes ni en el
     * selector del formulario de activo: se resuelve automáticamente en el
     * backend cuando un activo por cantidad no tiene variantes propias.
     *
     * Forward-only e idempotente.
     */
    public function up(): void
    {
        Schema::table('tallas', function (Blueprint $table): void {
            $table->boolean('es_comodin')->default(false)->after('activa');
        });

        $ahora = now();

        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            $existe = DB::table('tallas')
                ->where('empresa_id', $empresaId)
                ->where('es_comodin', true)
                ->exists();

            if ($existe) {
                continue;
            }

            $valor = 'Sin variante';
            // Evita chocar con el índice único (empresa_id, valor).
            $sufijo = 1;
            while (DB::table('tallas')->where('empresa_id', $empresaId)->where('valor', $valor)->exists()) {
                $valor = 'Sin variante '.(++$sufijo);
            }

            DB::table('tallas')->insert([
                'empresa_id' => $empresaId,
                'valor' => $valor,
                'orden' => 0,
                'activa' => true,
                'es_comodin' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tallas')
            ->where('es_comodin', true)
            ->whereNotIn('id', DB::table('saldos_inventario')->select('talla_id'))
            ->whereNotIn('id', DB::table('detalles_entrega')->select('talla_id'))
            ->delete();

        Schema::table('tallas', function (Blueprint $table): void {
            $table->dropColumn('es_comodin');
        });
    }
};
