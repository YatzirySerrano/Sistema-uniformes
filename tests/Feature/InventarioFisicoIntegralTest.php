<?php

use App\Acciones\FinalizarRondaInventarioFisico;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoUnidad;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Permission\Models\Role;

/**
 * Ronda de inventario físico INTEGRAL por empresa: una sola ronda con las
 * existencias por cantidad de todos sus almacenes (un renglón por almacén +
 * activo + variante) y todas sus unidades verificables. Varios encargados la
 * trabajan a la vez: gana la primera verificación, nada se pisa en silencio.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->almacenA = Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Almacén Norte']);
    $this->almacenB = Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Almacén Sur']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    // Dos encargados con permisos EFECTIVOS (rol personalizado, nunca por nombre).
    $this->conPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($rol)->empresas()->sync([$this->empresa->id]));
    };
    $this->encargadoA = ($this->conPermisos)(['inventario-fisico.ver', 'inventario-fisico.administrar']);
    $this->encargadoB = ($this->conPermisos)(['inventario-fisico.ver', 'inventario-fisico.administrar']);

    // Existencias por cantidad: la MISMA camisa M en dos almacenes.
    $this->camisa = Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisa']);
    $this->tallaM = Talla::factory()->create(['valor' => 'M']);
    $this->camisa->tallas()->attach($this->tallaM);
    foreach ([[$this->almacenA, 30], [$this->almacenB, 20]] as [$almacen, $cantidad]) {
        app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $this->empresa->id, almacenId: $almacen->id, activoId: $this->camisa->id,
            tallaId: $this->tallaM->id, tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
        ));
    }

    // Unidades identificadas.
    $this->laptop = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $this->juan = Colaborador::factory()->for($this->empresa)
        ->for(Sucursal::factory()->for($this->empresa)->create(['nombre' => 'Monterrey']))
        ->create(['nombre_completo' => 'Juan Pérez']);
    $this->unidad = fn (array $atributos = [], ?Almacen $almacen = null): UnidadActivo => UnidadActivo::factory()
        ->for($this->empresa)->for($this->laptop)->for($almacen ?? $this->almacenA)->create($atributos);

    $this->crear = fn (?User $usuario = null) => $this->actingAs($usuario ?? $this->admin)->post('/inventarios-fisicos', [
        'empresa_id' => $this->empresa->id, 'nombre' => 'Inventario general',
    ]);
    $this->ronda = function (): InventarioFisico {
        ($this->crear)()->assertSessionHasNoErrors();

        return InventarioFisico::query()->latest('id')->firstOrFail();
    };
    $this->renglon = fn (InventarioFisico $r, UnidadActivo $u): InventarioFisicoUnidad => InventarioFisicoUnidad::query()
        ->where('inventario_fisico_id', $r->id)->where('unidad_activo_id', $u->id)->firstOrFail();
    $this->existencia = fn (InventarioFisico $r, Almacen $a): InventarioFisicoExistencia => InventarioFisicoExistencia::query()
        ->where('inventario_fisico_id', $r->id)->where('almacen_id', $a->id)->firstOrFail();

    $this->marcar = fn (User $u, InventarioFisico $r, InventarioFisicoUnidad $f) => $this->actingAs($u)
        ->postJson("/inventarios-fisicos/{$r->id}/unidades/{$f->id}/presente");
    $this->contar = fn (User $u, InventarioFisico $r, InventarioFisicoExistencia $e, int $n, ?string $vista = null) => $this->actingAs($u)
        ->postJson("/inventarios-fisicos/{$r->id}/existencias/{$e->id}", ['cantidad_contada' => $n, 'verificada_en_vista' => $vista]);
});

/*
|--------------------------------------------------------------------------
| Una sola ronda integral
|--------------------------------------------------------------------------
*/

it('crea UNA ronda integral por empresa: sin elegir alcance ni almacén', function () {
    ($this->crear)()->assertSessionHasNoErrors()->assertRedirect();

    $ronda = InventarioFisico::query()->sole();
    expect($ronda->almacen_id)->toBeNull()
        ->and($ronda->usuario_id)->toBe($this->admin->id);
});

it('incluye las existencias por cantidad de varios almacenes SIN mezclarlas: cada renglón conserva su almacén', function () {
    $ronda = ($this->ronda)();

    $renglones = $ronda->existencias()->get()->keyBy('almacen_id');

    expect($renglones)->toHaveCount(2)
        ->and($renglones[$this->almacenA->id]->cantidad_esperada)->toBe(30)
        ->and($renglones[$this->almacenB->id]->cantidad_esperada)->toBe(20)
        ->and($renglones->pluck('activo_id')->unique()->all())->toBe([$this->camisa->id]);
});

it('incluye unidades en almacén, asignadas, en reparación e inservibles; excluye perdidas, robadas y baja', function () {
    $enAlmacen = ($this->unidad)();
    $asignada = ($this->unidad)(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $this->juan->id]);
    $reparacion = ($this->unidad)(['condicion' => CondicionUnidadActivo::EnReparacion], $this->almacenB);
    $inservible = ($this->unidad)(['condicion' => CondicionUnidadActivo::Inservible]);
    $perdida = ($this->unidad)(['condicion' => CondicionUnidadActivo::Perdido]);
    $robada = ($this->unidad)(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $this->juan->id, 'condicion' => CondicionUnidadActivo::Robado]);
    $baja = ($this->unidad)(['estado' => EstadoUnidadActivo::Baja, 'dado_de_baja_en' => now()]);

    $ronda = ($this->ronda)();
    $esperadas = $ronda->unidades()->pluck('unidad_activo_id');

    expect($esperadas->sort()->values()->all())->toBe(collect([$enAlmacen, $asignada, $reparacion, $inservible])->pluck('id')->sort()->values()->all())
        ->and($esperadas)->not->toContain($perdida->id)
        ->and($esperadas)->not->toContain($robada->id)
        ->and($esperadas)->not->toContain($baja->id);
});

it('el snapshot no cambia retroactivamente si después cambia el stock o se crean unidades', function () {
    ($this->unidad)();
    $ronda = ($this->ronda)();

    ($this->unidad)();
    SaldoInventario::query()->where('almacen_id', $this->almacenA->id)->update(['cantidad' => 99]);

    expect($ronda->unidades()->count())->toBe(1)
        ->and(($this->existencia)($ronda, $this->almacenA)->cantidad_esperada)->toBe(30);
});

it('la previsualización cuenta unidades, renglones y almacenes involucrados', function () {
    ($this->unidad)();
    ($this->unidad)(['condicion' => CondicionUnidadActivo::Robado]);

    $this->actingAs($this->admin)->getJson("/inventarios-fisicos/universo?empresa_id={$this->empresa->id}")
        ->assertOk()
        ->assertExactJson(['total' => 1, 'existencias' => 2, 'almacenes' => 2]);
});

/*
|--------------------------------------------------------------------------
| Verificación: pendiente / presente / faltante
|--------------------------------------------------------------------------
*/

it('lo no revisado es Pendiente mientras la ronda está abierta y Faltante sólo al cerrarla', function () {
    $unidad = ($this->unidad)();
    $ronda = ($this->ronda)();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p->where('unidades.data.0.codigo', $unidad->codigo)->where('unidades.data.0.clasificacion', 'pendiente'));

    // Cerrar exige contar las cantidades; lo QR sin verificar sí se permite.
    ($this->contar)($this->admin, $ronda, ($this->existencia)($ronda, $this->almacenA), 30)->assertOk();
    ($this->contar)($this->admin, $ronda, ($this->existencia)($ronda, $this->almacenB), 20)->assertOk();
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p->where('unidades.data.0.clasificacion', 'faltante'));
});

it('marcar Presente una asignada no cambia estado, condición, custodio ni almacén', function () {
    $asignada = ($this->unidad)(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $this->juan->id]);
    $ronda = ($this->ronda)();
    $antes = $asignada->fresh()->only(['estado', 'condicion', 'colaborador_id', 'almacen_id']);

    ($this->marcar)($this->encargadoA, $ronda, ($this->renglon)($ronda, $asignada))
        ->assertOk()
        ->assertJsonPath('unidad.clasificacion', 'encontrado')
        ->assertJsonPath('unidad.colaborador', 'Juan Pérez')
        ->assertJsonPath('unidad.colaborador_sucursal', 'Monterrey');

    expect($asignada->fresh()->only(['estado', 'condicion', 'colaborador_id', 'almacen_id']))->toBe($antes);
});

/*
|--------------------------------------------------------------------------
| Dos encargados en la MISMA ronda
|--------------------------------------------------------------------------
*/

it('dos encargados verifican unidades distintas en la misma ronda y cada verificación registra a su autor', function () {
    $u1 = ($this->unidad)();
    $u2 = ($this->unidad)();
    $ronda = ($this->ronda)(); // la inicia el admin; A y B sólo colaboran

    ($this->marcar)($this->encargadoA, $ronda, ($this->renglon)($ronda, $u1))->assertOk();
    ($this->marcar)($this->encargadoB, $ronda, ($this->renglon)($ronda, $u2))->assertOk();

    expect(($this->renglon)($ronda, $u1)->escaneado_por)->toBe($this->encargadoA->id)
        ->and(($this->renglon)($ronda, $u2)->escaneado_por)->toBe($this->encargadoB->id)
        ->and(($this->renglon)($ronda, $u1)->escaneado_en)->not->toBeNull();

    // Ambos ven el avance combinado.
    $this->actingAs($this->encargadoB)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p->where('contadores.encontrados', 2)->where('contadores.pendientes', 0));
});

it('si dos encargados verifican la misma unidad, gana el primero y el segundo recibe quién y cuándo', function () {
    $unidad = ($this->unidad)();
    $ronda = ($this->ronda)();
    $renglon = ($this->renglon)($ronda, $unidad);

    ($this->marcar)($this->encargadoA, $ronda, $renglon)->assertOk();
    $primera = $renglon->fresh()->escaneado_en;

    ($this->marcar)($this->encargadoB, $ronda, $renglon)
        ->assertStatus(409)
        ->assertJsonPath('unidad.escaneado_por', $this->encargadoA->name)
        ->assertJsonPath('unidad.clasificacion', 'encontrado')
        ->assertJson(fn ($j) => $j->where('message', fn (string $m) => str_contains($m, $this->encargadoA->name))->etc());

    expect($renglon->fresh()->escaneado_por)->toBe($this->encargadoA->id)
        ->and($renglon->fresh()->escaneado_en->equalTo($primera))->toBeTrue()
        ->and(InventarioFisicoUnidad::query()->where('unidad_activo_id', $unidad->id)->count())->toBe(1);
});

it('si dos encargados cuentan el mismo renglón, el segundo no pisa al primero en silencio; puede corregir conociendo el conteo', function () {
    $ronda = ($this->ronda)();
    $renglon = ($this->existencia)($ronda, $this->almacenA);

    ($this->contar)($this->encargadoA, $ronda, $renglon, 28)->assertOk();

    // B tenía el renglón como pendiente (vista null): se rechaza con el conteo real.
    ($this->contar)($this->encargadoB, $ronda, $renglon, 30)
        ->assertStatus(409)
        ->assertJsonPath('existencia.cantidad_contada', 28)
        ->assertJsonPath('existencia.verificada_por', $this->encargadoA->name)
        ->assertJsonPath('existencia.almacen', 'Almacén Norte');

    expect($renglon->fresh())->cantidad_contada->toBe(28)->verificada_por->toBe($this->encargadoA->id);

    // Corrección consciente (conoce la versión vigente): se aplica y queda en la bitácora.
    ($this->contar)($this->encargadoB, $ronda, $renglon, 30, $renglon->fresh()->verificada_en->toIso8601String())->assertOk();

    expect($renglon->fresh())->cantidad_contada->toBe(30)->verificada_por->toBe($this->encargadoB->id)
        ->and(BitacoraAuditoria::query()->where('accion', 'existencia_corregir_conteo')->sole()->valores_anteriores)->toBe(['cantidad_contada' => 28]);
});

it('deshacer se revalida: con una verificación desactualizada se rechaza y el deshacer real queda auditado', function () {
    $unidad = ($this->unidad)();
    $ronda = ($this->ronda)();
    $renglon = ($this->renglon)($ronda, $unidad);
    ($this->marcar)($this->encargadoA, $ronda, $renglon)->assertOk();
    $vista = $renglon->fresh()->escaneado_en->toIso8601String();

    $this->actingAs($this->encargadoB)
        ->deleteJson("/inventarios-fisicos/{$ronda->id}/unidades/{$renglon->id}/presente", ['escaneado_en_vista' => '2000-01-01T00:00:00+00:00'])
        ->assertStatus(409);
    expect($renglon->fresh()->escaneado_por)->toBe($this->encargadoA->id);

    $this->actingAs($this->encargadoB)
        ->deleteJson("/inventarios-fisicos/{$ronda->id}/unidades/{$renglon->id}/presente", ['escaneado_en_vista' => $vista])
        ->assertOk()
        ->assertJsonPath('unidad.clasificacion', 'pendiente');

    $auditoria = BitacoraAuditoria::query()->where('accion', 'unidad_deshacer_presente')->sole();
    expect($auditoria->usuario_id)->toBe($this->encargadoB->id)
        ->and($auditoria->valores_anteriores['verificada_por'])->toBe($this->encargadoA->name);
});

it('deshacer sin versión a la vista sólo revierte la marca propia, nunca la de otro encargado', function () {
    $unidad = ($this->unidad)();
    $ronda = ($this->ronda)();
    $renglon = ($this->renglon)($ronda, $unidad);
    ($this->marcar)($this->encargadoA, $ronda, $renglon)->assertOk();

    $this->actingAs($this->encargadoB)
        ->deleteJson("/inventarios-fisicos/{$ronda->id}/unidades/{$renglon->id}/presente")
        ->assertStatus(409)
        ->assertJsonPath('unidad.escaneado_por', $this->encargadoA->name);
    expect($renglon->fresh()->escaneado_por)->toBe($this->encargadoA->id);

    $this->actingAs($this->encargadoA)
        ->deleteJson("/inventarios-fisicos/{$ronda->id}/unidades/{$renglon->id}/presente")
        ->assertOk();
    expect($renglon->fresh()->escaneado_en)->toBeNull();
});

it('nadie puede verificar después de que otro encargado finalizó la ronda', function () {
    $unidad = ($this->unidad)();
    $ronda = ($this->ronda)();
    $existencia = ($this->existencia)($ronda, $this->almacenA);
    ($this->contar)($this->encargadoB, $ronda, $existencia, 30)->assertOk();
    ($this->contar)($this->encargadoB, $ronda, ($this->existencia)($ronda, $this->almacenB), 20)->assertOk();
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->encargadoB);

    ($this->marcar)($this->encargadoA, $ronda, ($this->renglon)($ronda, $unidad))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Esta ronda ya fue finalizada; no admite cambios.');
    ($this->contar)($this->encargadoA, $ronda, $existencia, 1, $existencia->fresh()->verificada_en->toIso8601String())
        ->assertStatus(422);

    $this->actingAs($this->encargadoB)
        ->deleteJson("/inventarios-fisicos/{$ronda->id}/unidades/".($this->renglon)($ronda, $unidad)->id.'/presente')
        ->assertStatus(422);

    expect(($this->renglon)($ronda, $unidad)->escaneado_en)->toBeNull()
        ->and($existencia->fresh()->cantidad_contada)->toBe(30);
});

/*
|--------------------------------------------------------------------------
| Correcciones por almacén
|--------------------------------------------------------------------------
*/

it('las correcciones se aplican en el almacén de cada renglón', function () {
    $ronda = ($this->ronda)();
    ($this->contar)($this->admin, $ronda, ($this->existencia)($ronda, $this->almacenA), 27)->assertOk(); // −3 en Norte
    ($this->contar)($this->admin, $ronda, ($this->existencia)($ronda, $this->almacenB), 20)->assertOk(); // coincide en Sur
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")->assertSessionHasNoErrors();

    $saldo = fn (Almacen $a): int => (int) SaldoInventario::query()->where('almacen_id', $a->id)->where('activo_id', $this->camisa->id)->value('cantidad');
    expect($saldo($this->almacenA))->toBe(27)
        ->and($saldo($this->almacenB))->toBe(20);
});

it('aplicar correcciones exige el permiso de ajuste y operar TODOS los almacenes de la ronda', function () {
    $ronda = ($this->ronda)();
    ($this->contar)($this->admin, $ronda, ($this->existencia)($ronda, $this->almacenA), 27)->assertOk();
    ($this->contar)($this->admin, $ronda, ($this->existencia)($ronda, $this->almacenB), 20)->assertOk();
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    // Administrar la ronda no basta: aplicar mueve saldos reales.
    $this->actingAs($this->encargadoA)->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")->assertForbidden();

    // Aunque la diferencia sólo esté en Norte, Sur fuera de operación bloquea el lote.
    $this->almacenB->update(['activo' => false]);
    $ajustador = ($this->conPermisos)(['inventario-fisico.ver', 'inventario.ajustar']);
    $this->actingAs($ajustador)->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")->assertForbidden();

    $this->almacenB->update(['activo' => true]);
    $this->actingAs($ajustador)->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")->assertSessionHasNoErrors();
    expect($ronda->fresh()->correcciones_aplicadas_por)->toBe($ajustador->id);
});

/*
|--------------------------------------------------------------------------
| Filtros, exportación, acta
|--------------------------------------------------------------------------
*/

it('filtra por estado operativo, por verificación y por almacén', function () {
    $norte = ($this->unidad)();
    $sur = ($this->unidad)([], $this->almacenB);
    $asignada = ($this->unidad)(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $this->juan->id]);
    $ronda = ($this->ronda)();
    ($this->marcar)($this->admin, $ronda, ($this->renglon)($ronda, $norte))->assertOk();

    $codigos = fn (array $q): array => collect($this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?".http_build_query($q))
        ->viewData('page')['props']['unidades']['data'])->pluck('codigo')->sort()->values()->all();

    expect($codigos(['seccion' => 'todos', 'estado_unidad' => 'asignado']))->toBe([$asignada->codigo])
        ->and($codigos(['seccion' => 'encontrados']))->toBe([$norte->codigo])
        ->and($codigos(['seccion' => 'faltantes', 'almacen_id' => $this->almacenB->id]))->toBe([$sur->codigo])
        // Por almacén = lo que HOY está guardado ahí (la asignada salió de Norte pero no está ahí).
        ->and($codigos(['seccion' => 'todos', 'almacen_id' => $this->almacenA->id]))->toBe([$norte->codigo]);

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?almacen_id={$this->almacenB->id}")
        ->assertInertia(fn ($p) => $p->count('existencias', 1)->where('existencias.0.almacen', 'Almacén Sur')->count('almacenes', 2));
});

it('la exportación por cantidad conserva almacén, resultado y quién verificó', function () {
    Excel::fake();
    $ronda = ($this->ronda)();
    ($this->contar)($this->encargadoA, $ronda, ($this->existencia)($ronda, $this->almacenA), 28)->assertOk();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?tipo=cantidad&formato=xlsx")->assertOk();

    $archivo = Str::slug('Inventario físico '.$ronda->folio).'-dasti-'.now()->toDateString().'.xlsx';
    Excel::assertDownloaded($archivo, function (ListadoExport $export): bool {
        $filas = collect($export->array())->keyBy(0);

        return isset($filas['Almacén Norte'], $filas['Almacén Sur'])
            && $filas['Almacén Norte'][6] === 'Faltante'
            && $filas['Almacén Norte'][7] === $this->encargadoA->name
            && $filas['Almacén Sur'][7] === '—';
    });
});

it('el acta PDF muestra el alcance integral y el almacén de cada renglón', function () {
    Pdf::fake();
    $ronda = ($this->ronda)();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=pdf")->assertOk();

    Pdf::assertSee(['Toda la empresa', 'Iniciada por', 'Almacén Norte', 'Almacén Sur', 'Camisa']);
});

/*
|--------------------------------------------------------------------------
| Autorización y rendimiento
|--------------------------------------------------------------------------
*/

it('un usuario sin permiso de administrar no puede verificar; otro autorizado distinto al creador sí colabora', function () {
    $unidad = ($this->unidad)();
    $ronda = ($this->ronda)();
    $soloVer = ($this->conPermisos)(['inventario-fisico.ver']);

    ($this->marcar)($soloVer, $ronda, ($this->renglon)($ronda, $unidad))->assertForbidden();
    ($this->contar)($soloVer, $ronda, ($this->existencia)($ronda, $this->almacenA), 1)->assertForbidden();

    expect($ronda->usuario_id)->not->toBe($this->encargadoA->id);
    ($this->marcar)($this->encargadoA, $ronda, ($this->renglon)($ronda, $unidad))->assertOk();
});

it('el detalle no hace una consulta por tarjeta (sin N+1)', function () {
    $consultas = function (): int {
        $ronda = InventarioFisico::query()->latest('id')->firstOrFail();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion=todos")->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    foreach (range(1, 3) as $_) {
        ($this->unidad)(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $this->juan->id]);
    }
    ($this->ronda)();
    $conPocas = $consultas();

    foreach (range(1, 12) as $_) {
        ($this->unidad)(['estado' => EstadoUnidadActivo::Asignada, 'colaborador_id' => $this->juan->id]);
    }
    ($this->ronda)();
    $conMuchas = $consultas();

    expect($conMuchas)->toBe($conPocas);
});
