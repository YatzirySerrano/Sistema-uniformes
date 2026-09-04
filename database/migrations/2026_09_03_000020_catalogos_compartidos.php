<?php

use App\Soporte\NormalizadorNombre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CATÁLOGOS COMPARTIDOS (Bloque C · Etapa 1).
     *
     * `tipos_activo`, `categorias_activo` y `tallas` dejan de pertenecer a una
     * empresa y pasan a ser catálogos **reutilizables a nivel plataforma**,
     * **habilitados por empresa** mediante pivotes N:M
     * (`tipo_activo_empresa`, `categoria_activo_empresa`, `talla_empresa`).
     *
     * Catálogo compartido ≠ inventario compartido: los `activos` siguen
     * perteneciendo a una empresa y el stock sigue llaveado por
     * `empresa + almacen + activo + talla`. Que "M" o "Prenda" sean el mismo
     * registro para SIESA e INMAG no mezcla sus activos ni sus saldos.
     *
     * Cambios:
     *  - 3 pivotes catálogo ↔ empresa (backfill desde `empresa_id` actual).
     *  - Consolidación por nombre normalizado: filas de distintas empresas con el
     *    mismo `nombre_normalizado` / `valor_normalizado` se funden en una sola,
     *    habilitada para todas esas empresas. Nombres distintos NO se funden.
     *  - Se elimina la "talla comodín" por empresa (`tallas.es_comodin`): el
     *    stock sin variante usa `talla_id = NULL`. `saldos_inventario` conserva
     *    unicidad real vía columna generada `talla_ref = COALESCE(talla_id, 0)`.
     *  - `saldos_inventario` / `movimientos_inventario` / `detalles_entrega` /
     *    `detalles_devolucion`: `talla_id` pasa a nullable + `nullOnDelete`.
     *  - Se elimina `empresa_id` de los tres catálogos y sus índices únicos por
     *    empresa se sustituyen por únicos de plataforma sobre la columna
     *    normalizada.
     *
     * Forward-only. Backfill controlado. En una BD nueva de producción los pasos
     * de consolidación son no-ops (1 fila por concepto) y el resto es estructura.
     */
    public function up(): void
    {
        Schema::create('tipo_activo_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tipo_activo_id')->constrained('tipos_activo')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tipo_activo_id', 'empresa_id']);
        });

        Schema::create('categoria_activo_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('categoria_activo_id')->constrained('categorias_activo')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['categoria_activo_id', 'empresa_id']);
        });

        Schema::create('talla_empresa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('talla_id')->constrained('tallas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['talla_id', 'empresa_id']);
        });

        Schema::table('tallas', function (Blueprint $table): void {
            $table->string('valor_normalizado')->default('')->after('valor');
        });

        $ahora = now();

        DB::table('tipos_activo')->orderBy('id')->each(function ($t) use ($ahora): void {
            DB::table('tipo_activo_empresa')->insert([
                'tipo_activo_id' => $t->id, 'empresa_id' => $t->empresa_id,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        });

        DB::table('categorias_activo')->orderBy('id')->each(function ($c) use ($ahora): void {
            DB::table('categoria_activo_empresa')->insert([
                'categoria_activo_id' => $c->id, 'empresa_id' => $c->empresa_id,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        });

        // --- Eliminación de la talla comodín ---------------------------------
        $comodines = DB::table('tallas')->where('es_comodin', true)->pluck('id')->all();

        if ($comodines !== []) {
            foreach (['saldos_inventario', 'movimientos_inventario', 'detalles_entrega', 'detalles_devolucion'] as $tabla) {
                DB::table($tabla)->whereIn('talla_id', $comodines)->update(['talla_id' => null]);
            }
            DB::table('activo_talla')->whereIn('talla_id', $comodines)->delete();
            DB::table('tallas')->whereIn('id', $comodines)->delete();
        }

        DB::table('tallas')->whereNotIn('id', $comodines)->orderBy('id')->each(function ($t) use ($ahora): void {
            DB::table('talla_empresa')->insert([
                'talla_id' => $t->id, 'empresa_id' => $t->empresa_id,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('tallas')->where('id', $t->id)->update([
                'valor_normalizado' => NormalizadorNombre::catalogo($t->valor),
            ]);
        });

        // --- Consolidación por nombre / valor normalizado -------------------
        $this->consolidar(
            tabla: 'tipos_activo',
            columnaNorm: 'nombre_normalizado',
            pivote: 'tipo_activo_empresa',
            pivoteFk: 'tipo_activo_id',
            repunteFk: [['activos', 'tipo_activo_id'], ['categorias_activo', 'tipo_activo_id']],
        );

        $this->consolidar(
            tabla: 'categorias_activo',
            columnaNorm: 'nombre_normalizado',
            pivote: 'categoria_activo_empresa',
            pivoteFk: 'categoria_activo_id',
            repunteFk: [['activos', 'categoria_id']],
        );

        $this->consolidarTallas();

        // --- Swap de esquema: quitar empresa_id, únicos de plataforma -------
        Schema::table('tipos_activo', function (Blueprint $table): void {
            $table->dropForeign(['empresa_id']);
            $table->dropUnique('tipos_activo_empresa_id_nombre_normalizado_unique');
        });
        Schema::table('tipos_activo', fn (Blueprint $table) => $table->dropColumn('empresa_id'));
        Schema::table('tipos_activo', fn (Blueprint $table) => $table->unique('nombre_normalizado'));

        Schema::table('categorias_activo', function (Blueprint $table): void {
            $table->dropForeign(['empresa_id']);
            $table->dropUnique('categorias_activo_empresa_id_nombre_normalizado_unique');
        });
        Schema::table('categorias_activo', fn (Blueprint $table) => $table->dropColumn('empresa_id'));
        Schema::table('categorias_activo', fn (Blueprint $table) => $table->unique('nombre_normalizado'));

        Schema::table('tallas', function (Blueprint $table): void {
            $table->dropForeign(['empresa_id']);
            $table->dropUnique('tallas_empresa_id_valor_unique');
        });
        Schema::table('tallas', fn (Blueprint $table) => $table->dropColumn(['empresa_id', 'es_comodin']));
        Schema::table('tallas', fn (Blueprint $table) => $table->unique('valor_normalizado'));

        // --- talla_id nullable + unicidad real de saldos ------------------
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropForeign(['talla_id']);
            $table->dropUnique('saldos_inv_almacen_unico');
        });
        Schema::table('saldos_inventario', fn (Blueprint $table) => $table->unsignedBigInteger('talla_id')->nullable()->change());
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->foreign('talla_id')->references('id')->on('tallas')->nullOnDelete();
            $table->unsignedBigInteger('talla_ref')->virtualAs('coalesce(talla_id, 0)')->after('talla_id');
            $table->unique(['empresa_id', 'almacen_id', 'activo_id', 'talla_ref'], 'saldos_inv_almacen_unico');
        });

        Schema::table('movimientos_inventario', fn (Blueprint $table) => $table->dropForeign(['talla_id']));
        Schema::table('movimientos_inventario', fn (Blueprint $table) => $table->unsignedBigInteger('talla_id')->nullable()->change());
        Schema::table('movimientos_inventario', fn (Blueprint $table) => $table->foreign('talla_id')->references('id')->on('tallas')->nullOnDelete());

        foreach (['detalles_entrega', 'detalles_devolucion'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropForeign(['talla_id']));
            Schema::table($tabla, fn (Blueprint $table) => $table->unsignedBigInteger('talla_id')->nullable()->change());
            Schema::table($tabla, fn (Blueprint $table) => $table->foreign('talla_id')->references('id')->on('tallas')->nullOnDelete());
        }
    }

    /**
     * Funde en una sola fila los registros de un catálogo que comparten el mismo
     * nombre normalizado, repuntando sus FKs y habilitando el registro canónico
     * para todas las empresas involucradas.
     *
     * @param  array<int, array{0: string, 1: string}>  $repunteFk  [tabla, columna]
     */
    private function consolidar(string $tabla, string $columnaNorm, string $pivote, string $pivoteFk, array $repunteFk): void
    {
        $grupos = DB::table($tabla)
            ->select($columnaNorm, DB::raw('MIN(id) as canonico'))
            ->groupBy($columnaNorm)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grupos as $grupo) {
            $canonico = (int) $grupo->canonico;
            $dups = DB::table($tabla)
                ->where($columnaNorm, $grupo->{$columnaNorm})
                ->where('id', '!=', $canonico)
                ->pluck('id')->all();

            if ($dups === []) {
                continue;
            }

            foreach ($repunteFk as [$fkTabla, $fkColumna]) {
                DB::table($fkTabla)->whereIn($fkColumna, $dups)->update([$fkColumna => $canonico]);
            }

            $this->fusionarPivote($pivote, $pivoteFk, $canonico, $dups);
            DB::table($tabla)->whereIn('id', $dups)->delete();
        }
    }

    private function consolidarTallas(): void
    {
        $grupos = DB::table('tallas')
            ->select('valor_normalizado', DB::raw('MIN(id) as canonico'))
            ->groupBy('valor_normalizado')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grupos as $grupo) {
            $canonico = (int) $grupo->canonico;
            $dups = DB::table('tallas')
                ->where('valor_normalizado', $grupo->valor_normalizado)
                ->where('id', '!=', $canonico)
                ->pluck('id')->all();

            if ($dups === []) {
                continue;
            }

            // activo_talla: repunta evitando duplicar (activo_id, talla_id).
            foreach (DB::table('activo_talla')->whereIn('talla_id', $dups)->get() as $fila) {
                $yaExiste = DB::table('activo_talla')
                    ->where('activo_id', $fila->activo_id)->where('talla_id', $canonico)->exists();
                DB::table('activo_talla')->where('id', $fila->id)
                    ->{$yaExiste ? 'delete' : 'update'}($yaExiste ? [] : ['talla_id' => $canonico]);
            }

            // saldos_inventario: repunta fusionando el gemelo si existe.
            foreach (DB::table('saldos_inventario')->whereIn('talla_id', $dups)->get() as $saldo) {
                $gemelo = DB::table('saldos_inventario')
                    ->where('empresa_id', $saldo->empresa_id)
                    ->where('almacen_id', $saldo->almacen_id)
                    ->where('activo_id', $saldo->activo_id)
                    ->where('talla_id', $canonico)
                    ->first();

                if ($gemelo !== null) {
                    DB::table('saldos_inventario')->where('id', $gemelo->id)->update([
                        'cantidad' => $gemelo->cantidad + $saldo->cantidad,
                        'minimo' => max($gemelo->minimo, $saldo->minimo),
                    ]);
                    DB::table('saldos_inventario')->where('id', $saldo->id)->delete();
                } else {
                    DB::table('saldos_inventario')->where('id', $saldo->id)->update(['talla_id' => $canonico]);
                }
            }

            foreach (['movimientos_inventario', 'detalles_entrega', 'detalles_devolucion'] as $tabla) {
                DB::table($tabla)->whereIn('talla_id', $dups)->update(['talla_id' => $canonico]);
            }

            $this->fusionarPivote('talla_empresa', 'talla_id', $canonico, $dups);
            DB::table('tallas')->whereIn('id', $dups)->delete();
        }
    }

    /**
     * @param  array<int, int>  $dups
     */
    private function fusionarPivote(string $pivote, string $fk, int $canonico, array $dups): void
    {
        $empresas = DB::table($pivote)->whereIn($fk, $dups)->pluck('empresa_id')->unique();

        foreach ($empresas as $empresaId) {
            DB::table($pivote)->insertOrIgnore([
                $fk => $canonico, 'empresa_id' => $empresaId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table($pivote)->whereIn($fk, $dups)->delete();
    }

    /**
     * No reversible en datos (la consolidación funde filas y la relación
     * 1 catálogo → 1 empresa se pierde). Restaura sólo la estructura mínima.
     */
    public function down(): void
    {
        foreach (['detalles_devolucion', 'detalles_entrega', 'movimientos_inventario'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropForeign(['talla_id']));
            Schema::table($tabla, fn (Blueprint $table) => $table->foreign('talla_id')->references('id')->on('tallas')->restrictOnDelete());
        }

        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->dropUnique('saldos_inv_almacen_unico');
            $table->dropColumn('talla_ref');
            $table->dropForeign(['talla_id']);
        });
        Schema::table('saldos_inventario', function (Blueprint $table): void {
            $table->foreign('talla_id')->references('id')->on('tallas')->restrictOnDelete();
            $table->unique(['empresa_id', 'almacen_id', 'activo_id', 'talla_id'], 'saldos_inv_almacen_unico');
        });

        Schema::table('tallas', function (Blueprint $table): void {
            $table->dropUnique('tallas_valor_normalizado_unique');
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('es_comodin')->default(false)->after('activa');
            $table->dropColumn('valor_normalizado');
        });

        Schema::table('categorias_activo', function (Blueprint $table): void {
            $table->dropUnique('categorias_activo_nombre_normalizado_unique');
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('tipos_activo', function (Blueprint $table): void {
            $table->dropUnique('tipos_activo_nombre_normalizado_unique');
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::dropIfExists('talla_empresa');
        Schema::dropIfExists('categoria_activo_empresa');
        Schema::dropIfExists('tipo_activo_empresa');
    }
};
