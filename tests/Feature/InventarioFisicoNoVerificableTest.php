<?php

use App\Acciones\FinalizarRondaInventarioFisico;
use App\Enums\EstadoEntrega;
use App\Enums\FinalidadCustodia;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioCustodiaColaborador;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Permission\Models\Role;

/**
 * «No fue posible verificar»: resolución explícita de un renglón por cantidad
 * que no pudo comprobarse. No es un 0 ni una diferencia: permite cerrar la
 * ronda pero nunca entra a correcciones ni toca stock o custodia.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Almacén DASTI']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->conPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($rol)->empresas()->sync([$this->empresa->id]));
    };
    $this->encargadoA = ($this->conPermisos)(['inventario-fisico.ver', 'inventario-fisico.administrar']);
    $this->encargadoB = ($this->conPermisos)(['inventario-fisico.ver', 'inventario-fisico.administrar']);

    $this->camisa = Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisa blanca mujer']);
    $this->talla = Talla::factory()->create(['valor' => '17']);
    $this->camisa->tallas()->attach($this->talla);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id, activoId: $this->camisa->id,
        tallaId: $this->talla->id, tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));

    // Custodia por cantidad de Dulce: 2 de uso personal.
    $this->dulce = Colaborador::factory()->for($this->empresa)
        ->for(Sucursal::factory()->for($this->empresa))
        ->create(['nombre_completo' => 'Dulce María', 'numero_empleado' => 'DM0002']);
    $this->detalleDulce = DetalleEntrega::factory()
        ->for(EntregaUniforme::factory()->create([
            'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->dulce->sucursal_id,
            'colaborador_id' => $this->dulce->id, 'estado' => EstadoEntrega::Firmada,
        ]), 'entrega')
        ->create([
            'activo_id' => $this->camisa->id, 'talla_id' => $this->talla->id, 'cantidad' => 2, 'finalidad' => FinalidadCustodia::UsoPersonal,
            'activo_nombre_snapshot' => 'Camisa blanca mujer', 'talla_valor_snapshot' => '17',
        ]);

    $this->actingAs($this->admin)->post('/inventarios-fisicos', ['empresa_id' => $this->empresa->id, 'nombre' => 'Inventario general'])
        ->assertSessionHasNoErrors();
    $this->ronda = InventarioFisico::query()->latest('id')->firstOrFail();
    $this->almacenFila = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $this->ronda->id)->whereNull('colaborador_id')->sole();
    $this->custodiaFila = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $this->ronda->id)->whereNotNull('colaborador_id')->sole();

    $this->base = fn (InventarioFisicoExistencia $e): string => "/inventarios-fisicos/{$this->ronda->id}/existencias/{$e->id}";
    $this->contar = fn (User $u, InventarioFisicoExistencia $e, int $n, ?string $vista = null) => $this->actingAs($u)
        ->postJson(($this->base)($e), ['cantidad_contada' => $n, 'verificada_en_vista' => $vista]);
    $this->noVerificable = fn (User $u, InventarioFisicoExistencia $e, ?string $motivo = null, ?string $vista = null) => $this->actingAs($u)
        ->postJson(($this->base)($e).'/no-verificable', ['motivo' => $motivo, 'verificada_en_vista' => $vista]);
    $this->reabrir = fn (User $u, InventarioFisicoExistencia $e, ?string $vista = null) => $this->actingAs($u)
        ->deleteJson(($this->base)($e).'/no-verificable', ['verificada_en_vista' => $vista]);
    $this->finalizar = fn () => app(FinalizarRondaInventarioFisico::class)->ejecutar($this->ronda->fresh(), firmaDemoBase64(), $this->admin);
});

/*
|--------------------------------------------------------------------------
| Resolución y cierre
|--------------------------------------------------------------------------
*/

it('un renglón pendiente real bloquea el cierre en backend con un mensaje claro', function () {
    ($this->contar)($this->admin, $this->almacenFila, 20)->assertOk();

    expect(fn () => ($this->finalizar)())->toThrow(
        ExcepcionDeNegocioSimple::class,
        'Aún hay artículos pendientes de verificar. Captura una cantidad o marca «No fue posible verificar» antes de finalizar la ronda.',
    );
    expect($this->ronda->fresh()->estaEnProceso())->toBeTrue();
});

it('con todo contado o marcado como no verificable la ronda se puede cerrar', function () {
    ($this->contar)($this->admin, $this->almacenFila, 20)->assertOk();
    ($this->noVerificable)($this->admin, $this->custodiaFila)->assertOk();

    ($this->finalizar)();

    expect($this->ronda->fresh()->estaFinalizada())->toBeTrue();
});

it('marca «No fue posible verificar» sin cantidad, sin diferencia y con quién/cuándo; el motivo es opcional', function () {
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila)->assertOk()
        ->assertJsonPath('existencia.resultado', 'no_verificable')
        ->assertJsonPath('existencia.no_verificable', true)
        ->assertJsonPath('existencia.cantidad_contada', null)
        ->assertJsonPath('existencia.diferencia', null)
        ->assertJsonPath('existencia.motivo_no_verificable', null)
        ->assertJsonPath('existencia.verificada_por', $this->encargadoA->name)
        ->assertJsonPath('contadores.cantidad_no_verificables', 1)
        ->assertJsonPath('contadores.cantidad_pendientes', 1)
        ->assertJsonPath('contadores.cantidad_verificados', 0);

    $fila = $this->custodiaFila->fresh();
    expect($fila->cantidad_contada)->toBeNull()
        ->and($fila->diferencia())->toBeNull()
        ->and($fila->verificada_por)->toBe($this->encargadoA->id)
        ->and($fila->verificada_en)->not->toBeNull();

    // Con motivo: se guarda (recortado) en la bitácora de la resolución, con su autor.
    ($this->noVerificable)($this->encargadoA, $this->almacenFila, '  Colaborador no respondió  ')->assertOk()
        ->assertJsonPath('existencia.motivo_no_verificable', 'Colaborador no respondió');
    $registro = BitacoraAuditoria::query()->where('accion', 'existencia_no_verificable')->where('entidad_id', $this->almacenFila->id)->sole();
    expect($registro->valores_nuevos)->toBe(['no_verificable' => true, 'motivo_no_verificable' => 'Colaborador no respondió'])
        ->and($registro->usuario_id)->toBe($this->encargadoA->id);
});

it('el detalle muestra la resolución, el motivo y el contador separado', function () {
    ($this->noVerificable)($this->admin, $this->custodiaFila, 'Está de vacaciones')->assertOk();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$this->ronda->id}")
        ->assertInertia(fn ($p) => $p
            ->where('existencias.1.resultado', 'no_verificable')
            ->where('existencias.1.motivo_no_verificable', 'Está de vacaciones')
            ->where('existencias.1.cantidad_contada', null)
            ->where('contadores.cantidad_no_verificables', 1)
            ->where('contadores.cantidad_pendientes', 1)
            ->where('permisos.finalizar', false));
});

/*
|--------------------------------------------------------------------------
| Correcciones: nunca entra
|--------------------------------------------------------------------------
*/

it('no entra a correcciones: no ajusta stock, ni custodia, ni crea movimientos', function () {
    ($this->noVerificable)($this->admin, $this->almacenFila, 'No se tuvo acceso al área')->assertOk();
    ($this->noVerificable)($this->admin, $this->custodiaFila)->assertOk();
    ($this->finalizar)();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$this->ronda->id}")
        ->assertInertia(fn ($p) => $p
            ->where('correcciones.estado', 'sin_diferencias')
            ->where('correcciones.total_diferencias', 0)
            ->where('correcciones.diferencias_custodia', 0)
            ->where('permisos.aplicarCorrecciones', false));

    $movimientos = MovimientoInventario::query()->count();
    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$this->ronda->id}/aplicar-correcciones");

    expect(MovimientoInventario::query()->count())->toBe($movimientos)
        ->and((int) SaldoInventario::query()->where('almacen_id', $this->almacen->id)->value('cantidad'))->toBe(20)
        ->and($this->detalleDulce->fresh()->finalidad)->toBe(FinalidadCustodia::UsoPersonal)
        ->and(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->dulce))->toBe(2)
        ->and($this->ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

it('con una diferencia real de almacén, sólo esa se aplica; la no verificable queda fuera', function () {
    ($this->contar)($this->admin, $this->almacenFila, 18)->assertOk();
    ($this->noVerificable)($this->admin, $this->custodiaFila)->assertOk();
    ($this->finalizar)();

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$this->ronda->id}/aplicar-correcciones")->assertSessionHasNoErrors();

    expect((int) SaldoInventario::query()->where('almacen_id', $this->almacen->id)->value('cantidad'))->toBe(18)
        ->and(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->count())->toBe(1)
        ->and(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->dulce))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Concurrencia y deshacer
|--------------------------------------------------------------------------
*/

it('A marca no verificable y B, con la vista vieja, intenta contar → 409 sin pisar', function () {
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila, 'Colaborador no respondió')->assertOk();

    ($this->contar)($this->encargadoB, $this->custodiaFila, 0)
        ->assertStatus(409)
        ->assertJsonPath('existencia.resultado', 'no_verificable')
        ->assertJsonPath('existencia.verificada_por', $this->encargadoA->name);

    expect($this->custodiaFila->fresh()->esNoVerificable())->toBeTrue()
        ->and($this->custodiaFila->fresh()->cantidad_contada)->toBeNull();
});

it('A cuenta y B, con la vista vieja, intenta marcar no verificable → 409 sin pisar', function () {
    ($this->contar)($this->encargadoA, $this->custodiaFila, 2)->assertOk();

    ($this->noVerificable)($this->encargadoB, $this->custodiaFila, 'No respondió')
        ->assertStatus(409)
        ->assertJsonPath('existencia.cantidad_contada', 2);

    expect($this->custodiaFila->fresh()->esNoVerificable())->toBeFalse()
        ->and($this->custodiaFila->fresh()->cantidad_contada)->toBe(2);
});

it('con la ronda abierta se reabre a Pendiente o se sustituye por un conteo real, con bitácora', function () {
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila, 'Fuera de oficina')->assertOk();
    $vista = $this->custodiaFila->fresh()->verificada_en->toIso8601String();

    ($this->reabrir)($this->encargadoB, $this->custodiaFila, $vista)->assertOk()
        ->assertJsonPath('existencia.resultado', 'pendiente')
        ->assertJsonPath('contadores.cantidad_no_verificables', 0);

    $fila = $this->custodiaFila->fresh();
    expect($fila->resultado())->toBe(InventarioFisicoExistencia::RESULTADO_PENDIENTE)
        ->and($fila->verificada_por)->toBeNull()
        ->and($fila->verificada_en)->toBeNull();

    // Vuelve a marcarse SIN motivo: no reaparece el anterior («Fuera de oficina»).
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila)->assertOk()
        ->assertJsonPath('existencia.motivo_no_verificable', null);
    // Después se logra contar: el conteo lo sustituye.
    ($this->contar)($this->encargadoA, $this->custodiaFila, 2, $this->custodiaFila->fresh()->verificada_en->toIso8601String())->assertOk()
        ->assertJsonPath('existencia.resultado', 'coincide')
        ->assertJsonPath('existencia.no_verificable', false);

    expect(BitacoraAuditoria::query()->where('accion', 'existencia_no_verificable')->count())->toBe(2)
        ->and(BitacoraAuditoria::query()->where('accion', 'existencia_reabrir')->sole()->usuario_id)->toBe($this->encargadoB->id)
        ->and(BitacoraAuditoria::query()->where('accion', 'existencia_corregir_conteo')->sole()->valores_anteriores)
        ->toBe(['no_verificable' => true]);
});

it('reabrir con la vista desactualizada → 409; tras cerrar la ronda ya no se puede reabrir', function () {
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila)->assertOk();
    ($this->reabrir)($this->encargadoB, $this->custodiaFila)->assertStatus(409);

    ($this->contar)($this->admin, $this->almacenFila, 20)->assertOk();
    ($this->finalizar)();

    ($this->reabrir)($this->admin, $this->custodiaFila, $this->custodiaFila->fresh()->verificada_en->toIso8601String())
        ->assertStatus(422);
    ($this->noVerificable)($this->admin, $this->almacenFila, null, $this->almacenFila->fresh()->verificada_en->toIso8601String())
        ->assertStatus(422);

    expect($this->custodiaFila->fresh()->esNoVerificable())->toBeTrue();
});

it('un usuario sin permiso de administrar no puede marcar ni reabrir', function () {
    $soloVer = ($this->conPermisos)(['inventario-fisico.ver']);

    ($this->noVerificable)($soloVer, $this->custodiaFila)->assertForbidden();
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila)->assertOk();
    ($this->reabrir)($soloVer, $this->custodiaFila, $this->custodiaFila->fresh()->verificada_en->toIso8601String())->assertForbidden();

    expect($this->custodiaFila->fresh()->esNoVerificable())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Exportación y rendimiento
|--------------------------------------------------------------------------
*/

it('Excel y PDF muestran «No fue posible verificar», contada —, motivo, quién y cuándo', function () {
    Excel::fake();
    Pdf::fake();
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila, 'Colaborador no respondió')->assertOk();
    ($this->contar)($this->encargadoA, $this->almacenFila, 20)->assertOk();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$this->ronda->id}/exportar?tipo=cantidad&formato=xlsx")->assertOk();

    $archivo = Str::slug('Inventario físico '.$this->ronda->folio).'-dasti-'.now()->toDateString().'.xlsx';
    Excel::assertDownloaded($archivo, function (ListadoExport $export): bool {
        $filas = collect($export->array())->keyBy(1);
        $dulce = $filas['Custodia: Dulce María (DM0002)'];

        return $dulce[2] === 'Uso personal'
            && $dulce[4] === '17'
            && $dulce[5] === 2
            && $dulce[6] === '—'
            && $dulce[7] === '—'
            && $dulce[8] === 'No fue posible verificar'
            && $dulce[9] === $this->encargadoA->name
            && $dulce[10] !== '—'
            && $dulce[11] === 'Colaborador no respondió'
            && $filas['Almacén DASTI'][8] === 'Coincide'
            && $filas['Almacén DASTI'][11] === '—';
    });

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$this->ronda->id}/exportar?formato=pdf")->assertOk();
    Pdf::assertSee(['No fue posible verificar', 'Motivo: Colaborador no respondió', $this->encargadoA->name, 'Custodia: Dulce María (DM0002)']);
});

it('marcar no verificable no agrega consultas por renglón al detalle (sin N+1)', function () {
    $consultas = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get("/inventarios-fisicos/{$this->ronda->id}")->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    // Con un renglón resuelto ya se carga «verificado por» (una consulta en
    // lote); resolver más renglones no debe agregar ninguna.
    ($this->noVerificable)($this->encargadoA, $this->custodiaFila, 'No respondió')->assertOk();
    $antes = $consultas();
    ($this->noVerificable)($this->encargadoB, $this->almacenFila)->assertOk();

    expect($consultas())->toBe($antes);
});
