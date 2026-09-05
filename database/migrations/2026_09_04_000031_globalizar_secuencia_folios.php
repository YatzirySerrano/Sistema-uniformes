<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG CRÍTICO (QA): `folios` particionaba el contador por
     * `empresa_id` + `tipo` + `anio`, pero `entregas_uniformes.folio` /
     * `acuses_recepcion.folio` / `devoluciones.folio` son ÚNICOS A NIVEL DE
     * TODA LA PLATAFORMA (sin el código de empresa en el string) — en cuanto
     * una SEGUNDA empresa registraba su primer documento del año, su contador
     * también arrancaba en 1 y producía el mismo "ENT-2026-000001" ya usado
     * por la primera → `SQLSTATE[23000] 1062 Duplicate entry` en el INSERT.
     *
     * La secuencia pasa a ser GLOBAL por tipo+año (`App\Servicios\ServicioFolios`
     * ya no recibe `empresa_id`). El valor inicial del contador global NO se
     * calcula a partir de la tabla `folios` (que es precisamente la fuente
     * corrupta/particionada): se deriva del folio MÁS ALTO REALMENTE EMITIDO
     * en cada tabla de destino (`entregas_uniformes`/`acuses_recepcion`/
     * `devoluciones`) para ese tipo+año, así ningún folio ya emitido —de
     * cualquier empresa— se reutiliza, y los huecos históricos no se rellenan.
     * Forward-only, idempotente (no borra documentos ni recalcula folios ya
     * emitidos, sólo el contador que decide el SIGUIENTE).
     */
    public function up(): void
    {
        $mapaTablas = [
            'entrega' => ['tabla' => 'entregas_uniformes', 'prefijo' => 'ENT'],
            'acuse' => ['tabla' => 'acuses_recepcion', 'prefijo' => 'ACU'],
            'devolucion' => ['tabla' => 'devoluciones', 'prefijo' => 'DEV'],
        ];

        /** @var array<string, array<int, int>> $maximos */
        $maximos = [];

        foreach ($mapaTablas as $tipo => $info) {
            if (! Schema::hasTable($info['tabla'])) {
                continue;
            }

            $patron = '/^'.preg_quote($info['prefijo'], '/').'-(\d{4})-(\d+)$/';

            foreach (DB::table($info['tabla'])->pluck('folio') as $folio) {
                if (preg_match($patron, (string) $folio, $coincidencias) !== 1) {
                    continue;
                }

                $anio = (int) $coincidencias[1];
                $numero = (int) $coincidencias[2];
                $actual = $maximos[$tipo][$anio] ?? 0;

                if ($numero > $actual) {
                    $maximos[$tipo][$anio] = $numero;
                }
            }
        }

        // La FK de `empresa_id` usa el índice único compuesto para cumplir su
        // requisito de índice — hay que soltar la FK antes de poder soltar
        // ese índice (MariaDB: error 1553 si se intenta al revés).
        Schema::table('folios', function (Blueprint $table): void {
            $table->dropForeign(['empresa_id']);
        });

        Schema::table('folios', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'tipo', 'anio']);
        });

        // Consolidar a una sola fila GLOBAL por tipo+año — se descarta el
        // contador previo (particionado, no confiable) y se reconstruye a
        // partir de lo realmente emitido.
        DB::table('folios')->delete();

        $ahora = now();
        foreach ($maximos as $tipo => $porAnio) {
            foreach ($porAnio as $anio => $maximo) {
                DB::table('folios')->insert([
                    'tipo' => $tipo,
                    'anio' => $anio,
                    'consecutivo' => $maximo,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        Schema::table('folios', function (Blueprint $table): void {
            $table->dropColumn('empresa_id');
            $table->unique(['tipo', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table): void {
            $table->dropUnique(['tipo', 'anio']);
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->cascadeOnDelete();
            $table->unique(['empresa_id', 'tipo', 'anio']);
        });
    }
};
