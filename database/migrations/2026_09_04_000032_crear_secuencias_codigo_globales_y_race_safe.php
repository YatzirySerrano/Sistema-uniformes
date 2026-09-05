<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUG (auditoría Fase 9): `AlmacenController`, `TipoActivoController`,
     * `SucursalController`, `AreaController` y `ActivoController` generaban su
     * `codigo` autogenerado con `count() + 1` (o `max('orden') + 1` para
     * variantes) y un bucle `while (...->exists())` de reintento — SIN ningún
     * lock. Dos altas concurrentes del mismo ámbito calculan el mismo
     * siguiente número, ambas ven que "no existe" todavía y ambas intentan
     * `INSERT` con el mismo código: la segunda revienta con
     * `UniqueConstraintViolationException` (`almacenes.codigo`,
     * `sucursales`/`areas`/`activos` `(empresa_id, codigo)`) → 500 crudo. Es
     * la misma clase de bug que ya se corrigió para los folios
     * (`..._000031_globalizar_secuencia_folios`).
     *
     * Fix: los 5 generadores pasan a usar contadores atómicos con
     * `lockForUpdate()` dentro de una transacción (`ServicioGeneradorCodigos`
     * ampliado para catálogos por empresa con prefijo literal —
     * `secuencias_codigo`, ámbitos nuevos `sucursal`/`area`/`activo` — y
     * `ServicioGeneradorCodigosGlobal` + tabla nueva `secuencias_codigo_globales`
     * para catálogos SIN dimensión de empresa: Almacén y Tipo de activo).
     *
     * El valor inicial de cada contador se deriva del código MÁS ALTO
     * REALMENTE USADO ya en cada tabla (nunca de `count()`, que ignora huecos
     * y bajas/`withTrashed`) — códigos capturados a mano con otro formato
     * (p. ej. "S01", "CAL-001", "62570") se ignoran, sólo importa el patrón
     * `PREFIJO-NNNN` que el propio generador produce. Forward-only,
     * idempotente, no toca ni renombra ningún código ya emitido.
     */
    public function up(): void
    {
        Schema::create('secuencias_codigo_globales', function (Blueprint $table): void {
            $table->id();
            $table->string('ambito', 40)->unique();
            $table->unsignedBigInteger('ultimo_valor')->default(0);
            $table->timestamps();
        });

        $ahora = now();

        // --- Ámbitos GLOBALES (sin empresa) ---------------------------------
        foreach ([
            'almacen' => ['tabla' => 'almacenes', 'prefijo' => 'ALM'],
            'tipo_activo' => ['tabla' => 'tipos_activo', 'prefijo' => 'TAC'],
        ] as $ambito => $info) {
            $maximo = $this->maximoConsecutivo($info['tabla'], $info['prefijo']);

            if ($maximo > 0) {
                DB::table('secuencias_codigo_globales')->insert([
                    'ambito' => $ambito,
                    'ultimo_valor' => $maximo,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        // --- Ámbitos POR EMPRESA (secuencias_codigo ya existe desde la Fase 3) ---
        foreach ([
            'sucursal' => ['tabla' => 'sucursales', 'prefijo' => 'SUC'],
            'area' => ['tabla' => 'areas', 'prefijo' => 'ARE'],
            'activo' => ['tabla' => 'activos', 'prefijo' => 'ACT'],
        ] as $ambito => $info) {
            foreach ($this->maximosPorEmpresa($info['tabla'], $info['prefijo']) as $empresaId => $maximo) {
                DB::table('secuencias_codigo')->updateOrInsert(
                    ['empresa_id' => $empresaId, 'ambito' => $ambito],
                    ['ultimo_valor' => $maximo, 'updated_at' => $ahora, 'created_at' => $ahora],
                );
            }
        }
    }

    private function maximoConsecutivo(string $tabla, string $prefijo): int
    {
        if (! Schema::hasTable($tabla)) {
            return 0;
        }

        $patron = '/^'.preg_quote($prefijo, '/').'-(\d+)$/';
        $maximo = 0;

        foreach (DB::table($tabla)->whereNotNull('codigo')->pluck('codigo') as $codigo) {
            if (preg_match($patron, (string) $codigo, $m) !== 1) {
                continue;
            }
            $maximo = max($maximo, (int) $m[1]);
        }

        return $maximo;
    }

    /**
     * @return array<int, int> empresa_id => máximo consecutivo usado
     */
    private function maximosPorEmpresa(string $tabla, string $prefijo): array
    {
        if (! Schema::hasTable($tabla)) {
            return [];
        }

        $patron = '/^'.preg_quote($prefijo, '/').'-(\d+)$/';
        $maximos = [];

        foreach (DB::table($tabla)->whereNotNull('codigo')->select('empresa_id', 'codigo')->get() as $fila) {
            if (preg_match($patron, (string) $fila->codigo, $m) !== 1) {
                continue;
            }
            $empresaId = (int) $fila->empresa_id;
            $numero = (int) $m[1];
            $maximos[$empresaId] = max($maximos[$empresaId] ?? 0, $numero);
        }

        return $maximos;
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_codigo_globales');
    }
};
