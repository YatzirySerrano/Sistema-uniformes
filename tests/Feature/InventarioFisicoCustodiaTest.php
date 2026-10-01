<?php

use App\Acciones\FinalizarRondaInventarioFisico;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\FinalidadCustodia;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Servicio;
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
 * La ronda integral también comprueba lo que cada colaborador tiene bajo
 * custodia POR CANTIDAD: un renglón por custodio + activo + variante +
 * finalidad, congelado al iniciar, contado con el mismo flujo (Coincide /
 * cantidad contada / 409) y cuya diferencia NUNCA toca el inventario.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Almacén DASTI']);
    $this->sucursal = Sucursal::factory()->for($this->empresa)->create(['nombre' => 'Monterrey']);
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

    $servicio = Servicio::factory()->create(['nombre' => 'Hospital Norte', 'sucursal_id' => $this->sucursal->id]);
    $this->dulce = Colaborador::factory()->for($this->empresa)->for($this->sucursal)
        ->create(['nombre_completo' => 'Dulce María', 'numero_empleado' => 'DM0002', 'servicio_actual_id' => $servicio->id]);
    $this->efren = Colaborador::factory()->for($this->empresa)->for($this->sucursal)
        ->create(['nombre_completo' => 'Efren Serrano', 'numero_empleado' => 'ES0001']);

    /** Custodia por cantidad: un renglón de una entrega firmada. */
    $this->custodia = fn (Colaborador $c, int $n, ?FinalidadCustodia $f, EstadoEntrega $estado = EstadoEntrega::Firmada, ?Empresa $empresa = null): DetalleEntrega => DetalleEntrega::factory()
        ->for(EntregaUniforme::factory()->create([
            'empresa_id' => ($empresa ?? $this->empresa)->id, 'sucursal_id' => $c->sucursal_id,
            'colaborador_id' => $c->id, 'estado' => $estado,
        ]), 'entrega')
        ->create([
            'activo_id' => $this->camisa->id, 'talla_id' => $this->talla->id, 'cantidad' => $n, 'finalidad' => $f,
            'activo_nombre_snapshot' => 'Camisa blanca mujer', 'talla_valor_snapshot' => '17',
        ]);

    $this->ronda = function (): InventarioFisico {
        $this->actingAs($this->admin)->post('/inventarios-fisicos', ['empresa_id' => $this->empresa->id, 'nombre' => 'Inventario general'])
            ->assertSessionHasNoErrors();

        return InventarioFisico::query()->latest('id')->firstOrFail();
    };
    $this->deCustodia = fn (InventarioFisico $r, Colaborador $c, ?FinalidadCustodia $f): InventarioFisicoExistencia => InventarioFisicoExistencia::query()
        ->where('inventario_fisico_id', $r->id)->where('colaborador_id', $c->id)
        ->when($f === null, fn ($q) => $q->whereNull('finalidad'), fn ($q) => $q->where('finalidad', $f->value))
        ->sole();
    $this->deAlmacen = fn (InventarioFisico $r): InventarioFisicoExistencia => InventarioFisicoExistencia::query()
        ->where('inventario_fisico_id', $r->id)->whereNull('colaborador_id')->sole();
    $this->contar = fn (User $u, InventarioFisico $r, InventarioFisicoExistencia $e, int $n, ?string $vista = null) => $this->actingAs($u)
        ->postJson("/inventarios-fisicos/{$r->id}/existencias/{$e->id}", ['cantidad_contada' => $n, 'verificada_en_vista' => $vista]);

    /** Renglones legibles: [origen, almacén o custodio, finalidad, talla, esperado]. */
    $this->renglones = fn (InventarioFisico $r): array => InventarioFisicoExistencia::query()
        ->where('inventario_fisico_id', $r->id)->with(['almacen', 'colaborador', 'talla'])->orderBy('id')->get()
        ->map(fn (InventarioFisicoExistencia $e): array => [
            $e->origen(), $e->colaborador->nombre_completo ?? $e->almacen?->nombre, $e->finalidad?->value, $e->talla?->valor, $e->cantidad_esperada,
        ])->all();
});

/*
|--------------------------------------------------------------------------
| Snapshot: almacén + custodia, bolsas separadas
|--------------------------------------------------------------------------
*/

it('incluye almacén y cada bolsa de custodia por separado: custodio + talla + finalidad, sin sumar entre ellas', function () {
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    ($this->custodia)($this->dulce, 1, FinalidadCustodia::UsoPersonal); // misma bolsa: se acumula
    ($this->custodia)($this->dulce, 4, FinalidadCustodia::Redistribucion);
    ($this->custodia)($this->dulce, 1, null); // histórico sin finalidad
    ($this->custodia)($this->efren, 3, FinalidadCustodia::Redistribucion);

    $ronda = ($this->ronda)();

    expect(($this->renglones)($ronda))->toBe([
        ['almacen', 'Almacén DASTI', null, '17', 20],
        ['custodia', 'Dulce María', 'uso_personal', '17', 3],
        ['custodia', 'Dulce María', 'redistribucion', '17', 4],
        ['custodia', 'Dulce María', null, '17', 1],
        ['custodia', 'Efren Serrano', 'redistribucion', '17', 3],
    ]);
});

it('usa la custodia real: descuenta devoluciones confirmadas e ignora entregas sin firmar y de otra empresa', function () {
    $detalle = ($this->custodia)($this->dulce, 5, FinalidadCustodia::UsoPersonal);
    DetalleDevolucion::factory()->for(Devolucion::factory()->create([
        'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id, 'colaborador_id' => $this->dulce->id, 'estado' => EstadoDevolucion::Confirmada,
    ]))->create(['detalle_entrega_id' => $detalle->id, 'activo_id' => $this->camisa->id, 'talla_id' => $this->talla->id, 'cantidad' => 2]);
    ($this->custodia)($this->efren, 3, FinalidadCustodia::UsoPersonal, EstadoEntrega::PendienteFirma);
    ($this->custodia)($this->efren, 3, FinalidadCustodia::UsoPersonal, empresa: Empresa::factory()->create());

    $ronda = ($this->ronda)();

    expect(($this->renglones)($ronda))->toBe([
        ['almacen', 'Almacén DASTI', null, '17', 20],
        ['custodia', 'Dulce María', 'uso_personal', '17', 3],
    ]);
});

it('la previsualización cuenta las bolsas de custodia', function () {
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    ($this->custodia)($this->dulce, 1, FinalidadCustodia::Redistribucion);

    $this->actingAs($this->admin)->getJson("/inventarios-fisicos/universo?empresa_id={$this->empresa->id}")
        ->assertOk()->assertJsonPath('custodias', 2)->assertJsonPath('existencias', 1);
});

it('el snapshot de custodia no cambia si después se reclasifica, devuelve o entrega más', function () {
    $detalle = ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    $ronda = ($this->ronda)();
    $antes = ($this->renglones)($ronda);

    $detalle->update(['finalidad' => FinalidadCustodia::Redistribucion]);
    DetalleDevolucion::factory()->for(Devolucion::factory()->create([
        'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id, 'colaborador_id' => $this->dulce->id, 'estado' => EstadoDevolucion::Confirmada,
    ]))->create(['detalle_entrega_id' => $detalle->id, 'activo_id' => $this->camisa->id, 'talla_id' => $this->talla->id, 'cantidad' => 1]);
    ($this->custodia)($this->efren, 7, null);

    expect(($this->renglones)($ronda))->toBe($antes)
        ->and(($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal)->cantidad_esperada)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Verificación: mismo flujo que almacén
|--------------------------------------------------------------------------
*/

it('la custodia empieza Pendiente y se cuenta con Coincide o cantidad real, igual que almacén', function () {
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    ($this->custodia)($this->dulce, 1, null);
    ($this->custodia)($this->efren, 3, FinalidadCustodia::Redistribucion);
    $ronda = ($this->ronda)();
    $personal = ($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal);

    $this->actingAs($this->encargadoA)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p
            ->where('existencias.1.origen', 'custodia')
            ->where('existencias.1.resultado', 'pendiente')
            ->where('existencias.1.custodio.nombre_completo', 'Dulce María')
            ->where('existencias.1.custodio.numero_empleado', 'DM0002')
            ->where('existencias.1.custodio_sucursal', 'Monterrey')
            ->where('existencias.1.custodio_servicio', 'Hospital Norte')
            ->where('existencias.1.finalidad_etiqueta', 'Uso personal')
            ->where('existencias.1.talla', '17')
            ->where('existencias.2.finalidad_etiqueta', 'Sin clasificar')
            ->where('existencias.0.origen', 'almacen')
            ->where('existencias.0.finalidad_etiqueta', null)
            ->where('contadores.cantidad_custodia_renglones', 3));

    // «Coincide» = enviar la cantidad esperada.
    ($this->contar)($this->encargadoA, $ronda, $personal, 2)->assertOk()
        ->assertJsonPath('existencia.resultado', 'coincide')
        ->assertJsonPath('existencia.verificada_por', $this->encargadoA->name);
    ($this->contar)($this->encargadoA, $ronda, ($this->deCustodia)($ronda, $this->dulce, null), 0)->assertOk()
        ->assertJsonPath('existencia.resultado', 'faltante');
    ($this->contar)($this->encargadoA, $ronda, ($this->deCustodia)($ronda, $this->efren, FinalidadCustodia::Redistribucion), 4)->assertOk()
        ->assertJsonPath('existencia.resultado', 'sobrante')
        ->assertJsonPath('existencia.diferencia', 1);

    $personal->refresh();
    expect($personal->verificada_por)->toBe($this->encargadoA->id)
        ->and($personal->verificada_en)->not->toBeNull()
        // Verificar no toca la custodia real.
        ->and(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->dulce))->toBe(3);
});

it('dos encargados cuentan custodias distintas a la vez; sobre la misma, el segundo recibe 409 sin pisar', function () {
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    ($this->custodia)($this->efren, 3, FinalidadCustodia::Redistribucion);
    $ronda = ($this->ronda)();
    $dulce = ($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal);
    $efren = ($this->deCustodia)($ronda, $this->efren, FinalidadCustodia::Redistribucion);

    ($this->contar)($this->encargadoA, $ronda, $dulce, 2)->assertOk();
    ($this->contar)($this->encargadoB, $ronda, $efren, 3)->assertOk();

    // B tenía a Dulce como pendiente en pantalla (vista null): no last-write-wins.
    ($this->contar)($this->encargadoB, $ronda, $dulce, 1)
        ->assertStatus(409)
        ->assertJsonPath('existencia.cantidad_contada', 2)
        ->assertJsonPath('existencia.verificada_por', $this->encargadoA->name)
        ->assertJsonPath('existencia.custodio.nombre_completo', 'Dulce María');

    expect($dulce->fresh()->cantidad_contada)->toBe(2)
        ->and($dulce->fresh()->verificada_por)->toBe($this->encargadoA->id)
        ->and($efren->fresh()->verificada_por)->toBe($this->encargadoB->id);
});

/*
|--------------------------------------------------------------------------
| Correcciones: sólo almacén
|--------------------------------------------------------------------------
*/

it('al aplicar correcciones sólo cambia el saldo de almacén; la diferencia de custodia queda registrada sin mover nada', function () {
    $detalle = ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    $ronda = ($this->ronda)();
    ($this->contar)($this->admin, $ronda, ($this->deAlmacen)($ronda), 18)->assertOk(); // −2 en almacén
    $custodia = ($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal);
    ($this->contar)($this->admin, $ronda, $custodia, 1)->assertOk(); // −1 en custodia
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p
            ->where('correcciones.estado', 'pendientes')
            ->where('correcciones.total_diferencias', 1)
            ->where('correcciones.diferencias_custodia', 1));

    $conteos = fn (): array => [EntregaUniforme::query()->count(), DetalleEntrega::query()->count(), Devolucion::query()->count(), MovimientoInventario::query()->count()];
    $antes = $conteos();

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")->assertSessionHasNoErrors();

    $saldo = (int) SaldoInventario::query()->where('almacen_id', $this->almacen->id)->where('activo_id', $this->camisa->id)->value('cantidad');
    $despues = $conteos();

    expect($saldo)->toBe(18)
        // Sólo UN movimiento nuevo (el ajuste de almacén); nada por la custodia.
        ->and($despues)->toBe([$antes[0], $antes[1], $antes[2], $antes[3] + 1])
        ->and(MovimientoInventario::query()->latest('id')->first()->tipo)->toBe(TipoMovimiento::AjusteSalida)
        ->and($detalle->fresh()->finalidad)->toBe(FinalidadCustodia::UsoPersonal)
        ->and($detalle->fresh()->entrega->colaborador_id)->toBe($this->dulce->id)
        ->and(app(ServicioCustodiaColaborador::class)->totalPiezasPendientes($this->dulce))->toBe(2)
        ->and($custodia->fresh()->only(['colaborador_id', 'cantidad_contada']))->toBe(['colaborador_id' => $this->dulce->id, 'cantidad_contada' => 1]);
});

it('si sólo hay diferencias de custodia no hay correcciones que aplicar', function () {
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    $ronda = ($this->ronda)();
    ($this->contar)($this->admin, $ronda, ($this->deAlmacen)($ronda), 20)->assertOk();
    ($this->contar)($this->admin, $ronda, ($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal), 0)->assertOk();
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p
            ->where('correcciones.estado', 'sin_diferencias')
            ->where('correcciones.diferencias_custodia', 1)
            ->where('permisos.aplicarCorrecciones', false));

    $movimientos = MovimientoInventario::query()->count();
    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones");

    expect(MovimientoInventario::query()->count())->toBe($movimientos)
        ->and($ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Autorización, exportación, rendimiento
|--------------------------------------------------------------------------
*/

it('sin permiso de administrar no se cuenta una custodia; otro encargado autorizado sí', function () {
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    $ronda = ($this->ronda)();
    $renglon = ($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal);
    $soloVer = ($this->conPermisos)(['inventario-fisico.ver']);

    ($this->contar)($soloVer, $ronda, $renglon, 2)->assertForbidden();
    expect($ronda->usuario_id)->not->toBe($this->encargadoB->id);
    ($this->contar)($this->encargadoB, $ronda, $renglon, 2)->assertOk();
});

it('Excel y PDF muestran origen, custodio, finalidad, talla y cantidades', function () {
    Excel::fake();
    Pdf::fake();
    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    ($this->custodia)($this->efren, 3, null);
    $ronda = ($this->ronda)();
    ($this->contar)($this->encargadoA, $ronda, ($this->deCustodia)($ronda, $this->dulce, FinalidadCustodia::UsoPersonal), 1)->assertOk();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?tipo=cantidad&formato=xlsx")->assertOk();

    $archivo = Str::slug('Inventario físico '.$ronda->folio).'-dasti-'.now()->toDateString().'.xlsx';
    Excel::assertDownloaded($archivo, function (ListadoExport $export): bool {
        $filas = collect($export->array())->keyBy(1);

        return $filas['Almacén DASTI'][0] === 'En almacén'
            && $filas['Almacén DASTI'][2] === 'No aplica'
            && $filas['Custodia: Dulce María (DM0002)'][0] === 'Bajo custodia'
            && $filas['Custodia: Dulce María (DM0002)'][2] === 'Uso personal'
            && $filas['Custodia: Dulce María (DM0002)'][4] === '17'
            && $filas['Custodia: Dulce María (DM0002)'][5] === 2
            && $filas['Custodia: Dulce María (DM0002)'][6] === 1
            && $filas['Custodia: Dulce María (DM0002)'][8] === 'Faltante'
            && $filas['Custodia: Dulce María (DM0002)'][9] === $this->encargadoA->name
            && $filas['Custodia: Efren Serrano (ES0001)'][2] === 'Sin clasificar';
    });

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=pdf")->assertOk();
    Pdf::assertSee(['Almacén / custodio', 'Talla / variante', 'Custodia: Dulce María (DM0002)', 'Uso personal', 'Sin clasificar', 'Almacén DASTI']);
});

it('el detalle no hace consultas por custodio, finalidad ni talla (sin N+1)', function () {
    $consultas = function (): int {
        $ronda = InventarioFisico::query()->latest('id')->firstOrFail();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}")->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    ($this->custodia)($this->dulce, 2, FinalidadCustodia::UsoPersonal);
    ($this->ronda)();
    $conPocas = $consultas();

    foreach (range(1, 6) as $i) {
        $otro = Colaborador::factory()->for($this->empresa)->for(Sucursal::factory()->for($this->empresa))->create();
        ($this->custodia)($otro, $i, $i % 2 === 0 ? FinalidadCustodia::Redistribucion : null);
        ($this->custodia)($otro, 1, FinalidadCustodia::UsoPersonal);
    }
    ($this->ronda)();
    $conMuchas = $consultas();

    expect(InventarioFisicoExistencia::query()->where('inventario_fisico_id', InventarioFisico::query()->latest('id')->value('id'))->count())->toBe(14)
        ->and($conMuchas)->toBe($conPocas);
});
