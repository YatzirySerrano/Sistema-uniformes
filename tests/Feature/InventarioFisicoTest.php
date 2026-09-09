<?php

use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\EscanearUnidadInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoInventarioFisico;
use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoUnidad;
use App\Models\UnidadActivo;
use App\Models\User;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Módulo de inventario físico por rondas de escaneo QR. Es un módulo de
 * VERIFICACIÓN: compara lo hallado físicamente contra el snapshot congelado al
 * iniciar la ronda; nunca mueve stock ni cambia estado/almacén/asignación de
 * las unidades.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create(['nombre' => 'Laptop Dell']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->unidad = fn (array $estado = []): UnidadActivo => UnidadActivo::factory()
        ->for($this->empresa)->for($this->activo)->for($this->almacen)->create($estado);
});

/*
|--------------------------------------------------------------------------
| Alta de ronda y snapshot
|--------------------------------------------------------------------------
*/

it('crea una ronda en proceso con folio y congela el universo esperado', function () {
    ($this->unidad)();
    ($this->unidad)();
    ($this->unidad)();

    $this->actingAs($this->admin)
        ->post('/inventarios-fisicos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Inventario diciembre 2026 – DASTI',
        ])
        ->assertRedirect();

    $ronda = InventarioFisico::query()->latest('id')->firstOrFail();

    expect($ronda->estado)->toBe(EstadoInventarioFisico::EnProceso)
        ->and($ronda->folio)->toStartWith('INVF-'.now()->year.'-')
        ->and($ronda->usuario_id)->toBe($this->admin->id);

    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->where('esperada', true)->count())->toBe(3)
        ->and(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->whereNotNull('escaneado_en')->count())->toBe(0);
});

it('el snapshot excluye las unidades de otra empresa', function () {
    ($this->unidad)();

    $otra = Empresa::factory()->create();
    $almacenOtra = Almacen::factory()->paraEmpresa($otra)->create();
    $activoOtra = Activo::factory()->for($otra)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($otra, 'empresa')->for($activoOtra)->for($almacenOtra)->create();

    $ronda = crearRonda($this->admin, $this->empresa);

    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->count())->toBe(1);
});

it('el snapshot excluye las unidades dadas de baja pero incluye perdidas / robadas / en reparación', function () {
    ($this->unidad)();                                                   // disponible
    ($this->unidad)(['estado' => 'baja', 'dado_de_baja_en' => now()]);   // baja → fuera
    ($this->unidad)(['condicion' => CondicionUnidadActivo::Perdido]);    // perdida → dentro
    ($this->unidad)(['condicion' => CondicionUnidadActivo::EnReparacion]); // reparación → dentro
    ($this->unidad)()->update(['estado' => 'asignada']);                 // asignada → dentro

    $ronda = crearRonda($this->admin, $this->empresa);

    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->count())->toBe(4);
});

it('una unidad creada DESPUÉS de iniciar la ronda no infla el universo esperado', function () {
    ($this->unidad)();
    ($this->unidad)();

    $ronda = crearRonda($this->admin, $this->empresa);
    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->count())->toBe(2);

    ($this->unidad)(); // nueva unidad tras el snapshot

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($p) => $p->where('contadores.esperados', 2));
});

it('el alcance por almacén limita el snapshot a las unidades de ese almacén', function () {
    ($this->unidad)();
    ($this->unidad)();
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($otroAlmacen)->create();

    $ronda = crearRonda($this->admin, $this->empresa, $this->almacen->id);

    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->count())->toBe(2);
});

it('la previsualización del universo cuenta las mismas unidades que el snapshot', function () {
    ($this->unidad)();
    ($this->unidad)();
    ($this->unidad)(['estado' => 'baja', 'dado_de_baja_en' => now()]);

    $this->actingAs($this->admin)
        ->getJson("/inventarios-fisicos/universo?empresa_id={$this->empresa->id}")
        ->assertOk()
        ->assertJson(['total' => 2]);
});

/*
|--------------------------------------------------------------------------
| Escaneo
|--------------------------------------------------------------------------
*/

it('escanea una unidad esperada por public_token y la marca como encontrada', function () {
    $unidad = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])
        ->assertOk()
        ->assertJson([
            'resultado' => 'encontrada',
            'unidad' => ['codigo' => $unidad->codigo, 'clasificacion' => 'encontrado'],
            'contadores' => ['esperados' => 1, 'encontrados' => 1, 'pendientes' => 0, 'no_esperados' => 0],
        ]);

    $fila = InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->where('unidad_activo_id', $unidad->id)->firstOrFail();
    expect($fila->escaneado_en)->not->toBeNull()
        ->and($fila->escaneado_por)->toBe($this->admin->id)
        ->and($fila->esperada)->toBeTrue();
});

it('escanea por el código de unidad (entrada manual) y por la URL completa del QR', function () {
    $porCodigo = ($this->unidad)();
    $porUrl = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $porCodigo->codigo])
        ->assertOk()->assertJson(['resultado' => 'encontrada']);

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => url("/activos/unidades/{$porUrl->public_token}")])
        ->assertOk()->assertJson(['resultado' => 'encontrada']);

    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->whereNotNull('escaneado_en')->count())->toBe(2);
});

it('el doble escaneo no crea una segunda fila y responde "ya escaneada"', function () {
    $unidad = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])->assertOk();
    $primera = InventarioFisicoUnidad::query()->where('unidad_activo_id', $unidad->id)->firstOrFail()->escaneado_en;

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])
        ->assertOk()
        ->assertJson(['resultado' => 'ya_escaneada', 'contadores' => ['encontrados' => 1]]);

    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->where('unidad_activo_id', $unidad->id)->count())->toBe(1)
        ->and(InventarioFisicoUnidad::query()->where('unidad_activo_id', $unidad->id)->firstOrFail()->escaneado_en->eq($primera))->toBeTrue();
});

it('dos escaneos "simultáneos" de la misma unidad terminan con una sola fila (idempotente)', function () {
    $unidad = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);
    $accion = app(EscanearUnidadInventarioFisico::class);

    $a = $accion->ejecutar($ronda, $unidad->public_token, $this->admin);
    $b = $accion->ejecutar($ronda, $unidad->public_token, $this->admin);

    expect($a['resultado'])->toBe('encontrada')
        ->and($b['resultado'])->toBe('ya_escaneada')
        ->and(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->count())->toBe(1);
});

it('escanear una unidad de la misma empresa que no estaba en el snapshot la registra como NO esperada', function () {
    ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);

    $tardía = ($this->unidad)(); // creada después del snapshot

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $tardía->public_token])
        ->assertOk()
        ->assertJson(['resultado' => 'no_esperada', 'contadores' => ['esperados' => 1, 'no_esperados' => 1]]);

    $fila = InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->where('unidad_activo_id', $tardía->id)->firstOrFail();
    expect($fila->esperada)->toBeFalse()
        ->and($fila->escaneado_en)->not->toBeNull();
});

it('escanear una unidad de OTRA empresa se rechaza sin filtrar información (IDOR)', function () {
    $ronda = crearRonda($this->admin, $this->empresa);

    $otra = Empresa::factory()->create();
    $almacenOtra = Almacen::factory()->paraEmpresa($otra)->create();
    $activoOtra = Activo::factory()->for($otra)->seguimientoIndividual()->create();
    $ajena = UnidadActivo::factory()->for($otra, 'empresa')->for($activoOtra)->for($almacenOtra)->create();

    $respuesta = $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $ajena->public_token])
        ->assertStatus(422);

    expect($respuesta->json('message'))->not->toContain($ajena->codigo);
    expect(InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->where('unidad_activo_id', $ajena->id)->exists())->toBeFalse();
});

it('un código inexistente responde 422 controlado, no un 500', function () {
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => 'NO-EXISTE-123'])
        ->assertStatus(422);
});

it('una ronda finalizada no acepta más escaneos', function () {
    $unidad = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar")->assertRedirect();

    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])
        ->assertStatus(422);

    $ronda->refresh();
    expect($ronda->estado)->toBe(EstadoInventarioFisico::Finalizado)
        ->and($ronda->finalizado_en)->not->toBeNull();
});

it('finalizar dos veces la misma ronda es un error controlado', function () {
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar")->assertRedirect();
    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar")->assertSessionHasErrors('negocio');
});

/*
|--------------------------------------------------------------------------
| Resumen / contadores / historial
|--------------------------------------------------------------------------
*/

it('el resumen deriva encontrados, faltantes y no esperados del snapshot', function () {
    $encontrada = ($this->unidad)();
    $faltante = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);
    $noEsperada = ($this->unidad)();

    $accion = app(EscanearUnidadInventarioFisico::class);
    $accion->ejecutar($ronda, $encontrada->public_token, $this->admin);
    $accion->ejecutar($ronda, $noEsperada->public_token, $this->admin);

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion=faltantes")
        ->assertInertia(fn ($p) => $p
            ->where('contadores.esperados', 2)
            ->where('contadores.encontrados', 2)
            ->where('contadores.pendientes', 1)
            ->where('contadores.no_esperados', 1)
            ->where('seccion', 'faltantes')
            ->where('unidades.data', fn ($d) => count($d) === 1 && $d[0]['codigo'] === $faltante->codigo && $d[0]['clasificacion'] === 'faltante'));

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion=no_esperados")
        ->assertInertia(fn ($p) => $p
            ->where('unidades.data', fn ($d) => count($d) === 1 && $d[0]['codigo'] === $noEsperada->codigo && $d[0]['clasificacion'] === 'no_esperado'));

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion=encontrados")
        ->assertInertia(fn ($p) => $p->where('unidades.data', fn ($d) => count($d) === 2));
});

it('el resumen muestra el estado visible actual de cada unidad', function () {
    $perdida = ($this->unidad)(['condicion' => CondicionUnidadActivo::Perdido]);
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion=faltantes")
        ->assertInertia(fn ($p) => $p->where('unidades.data.0.estado_visible', 'perdido'));
});

it('el listado histórico muestra contadores por ronda y respeta el alcance multiempresa', function () {
    ($this->unidad)();
    ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);
    app(EscanearUnidadInventarioFisico::class)->ejecutar($ronda, InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->first()->unidad->public_token, $this->admin);

    // Ronda de otra empresa, invisible para un supervisor de la nuestra.
    $otra = Empresa::factory()->create();
    InventarioFisico::factory()->for($otra)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)->get('/inventarios-fisicos')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('rondas.data', fn ($d) => count($d) === 1
                && $d[0]['id'] === $ronda->id
                && $d[0]['esperados'] === 2
                && $d[0]['escaneados'] === 1
                && $d[0]['faltantes'] === 1));
});

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

it('sin permiso de ver, el módulo responde 403', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($sinPermiso)->get('/inventarios-fisicos')->assertForbidden();
});

it('el rol Encargado ve pero no puede iniciar ni escanear', function () {
    $unidad = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($encargado)->get("/inventarios-fisicos/{$ronda->id}")->assertOk();
    $this->actingAs($encargado)->get('/inventarios-fisicos/crear')->assertForbidden();
    $this->actingAs($encargado)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])
        ->assertForbidden();
    $this->actingAs($encargado)->post("/inventarios-fisicos/{$ronda->id}/finalizar")->assertForbidden();
});

it('un usuario sin acceso a la empresa de la ronda no puede verla ni escanearla', function () {
    $unidad = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);

    $otra = Empresa::factory()->create();
    $forastero = usuarioCon(RolSistema::Supervisor->value, [$otra]);

    $this->actingAs($forastero)->get("/inventarios-fisicos/{$ronda->id}")->assertForbidden();
    $this->actingAs($forastero)
        ->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| No modifica el inventario operativo
|--------------------------------------------------------------------------
*/

it('escanear NO cambia el estado, condición, almacén ni asignación de la unidad', function () {
    $unidad = ($this->unidad)(['condicion' => CondicionUnidadActivo::Perdido]);
    $antes = $unidad->only(['estado', 'condicion', 'almacen_id', 'colaborador_id']);
    $ronda = crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])->assertOk();

    expect($unidad->fresh()->only(['estado', 'condicion', 'almacen_id', 'colaborador_id']))->toBe($antes);
});

/*
|--------------------------------------------------------------------------
| Exportaciones
|--------------------------------------------------------------------------
*/

it('exporta el resumen de la ronda en Excel y PDF respetando la sección', function () {
    $encontrada = ($this->unidad)();
    ($this->unidad)(); // faltante
    $ronda = crearRonda($this->admin, $this->empresa);
    app(EscanearUnidadInventarioFisico::class)->ejecutar($ronda, $encontrada->public_token, $this->admin);

    $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=xlsx&seccion=faltantes")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=pdf");
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('la exportación exige el mismo permiso que ver la ronda', function () {
    $ronda = crearRonda($this->admin, $this->empresa);
    $forastero = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);

    $this->actingAs($forastero)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=xlsx")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Detalle: sección "Todos"
|--------------------------------------------------------------------------
*/

it('la sección "todos" lista el universo registrado en la ronda sin recalcular UnidadActivo ni duplicar', function () {
    $encontrada = ($this->unidad)();
    $faltante = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);
    $noEsperada = ($this->unidad)(); // creada tras el snapshot

    $accion = app(EscanearUnidadInventarioFisico::class);
    $accion->ejecutar($ronda, $encontrada->public_token, $this->admin);
    $accion->ejecutar($ronda, $noEsperada->public_token, $this->admin);

    // Otra unidad NUEVA que jamás se escaneó: no debe aparecer (la ronda es histórica).
    ($this->unidad)();

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion=todos")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('seccion', 'todos')
            ->where('contadores.todos', 3)                // 2 esperados + 1 no esperado
            ->where('contadores.esperados', 2)
            ->where('contadores.no_esperados', 1)
            ->where('unidades.data', function ($data) use ($encontrada, $faltante, $noEsperada) {
                $codigos = collect($data)->pluck('codigo');
                $clasif = collect($data)->pluck('clasificacion', 'codigo');

                return count($data) === 3
                    && $codigos->duplicates()->isEmpty()
                    && $clasif[$encontrada->codigo] === 'encontrado'
                    && $clasif[$faltante->codigo] === 'faltante'
                    && $clasif[$noEsperada->codigo] === 'no_esperado';
            }));
});

it('las secciones encontrados / faltantes / no_esperados siguen siendo correctas junto a "todos"', function () {
    $encontrada = ($this->unidad)();
    $faltante = ($this->unidad)();
    $ronda = crearRonda($this->admin, $this->empresa);
    $noEsperada = ($this->unidad)();

    $accion = app(EscanearUnidadInventarioFisico::class);
    $accion->ejecutar($ronda, $encontrada->public_token, $this->admin);
    $accion->ejecutar($ronda, $noEsperada->public_token, $this->admin);

    // "Encontrados" = todo lo escaneado (esperado o no); "Faltantes" = esperado
    // sin escanear; "No esperados" = escaneado fuera del snapshot.
    foreach ([
        'encontrados' => collect([$encontrada->codigo, $noEsperada->codigo])->sort()->values()->all(),
        'faltantes' => [$faltante->codigo],
        'no_esperados' => [$noEsperada->codigo],
    ] as $seccion => $esperados) {
        $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}?seccion={$seccion}")
            ->assertInertia(fn ($p) => $p
                ->where('unidades.data', fn ($d) => collect($d)->pluck('codigo')->sort()->values()->all() === $esperados));
    }
});

it('exporta la sección "todos" con la columna Clasificación', function () {
    Excel::fake();
    $encontrada = ($this->unidad)();
    ($this->unidad)(); // faltante
    $ronda = crearRonda($this->admin, $this->empresa);
    $noEsperada = ($this->unidad)();
    app(EscanearUnidadInventarioFisico::class)->ejecutar($ronda, $encontrada->public_token, $this->admin);
    app(EscanearUnidadInventarioFisico::class)->ejecutar($ronda, $noEsperada->public_token, $this->admin);

    $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=xlsx&seccion=todos")
        ->assertOk();

    Excel::assertDownloaded('inventario-fisico-'.Str::slug($ronda->folio).'-'.Str::slug($this->empresa->nombre_comercial).'-'.now()->toDateString().'.xlsx', function (ListadoExport $e): bool {
        $clasificaciones = array_column($e->array(), 0);
        expect($e->array())->toHaveCount(3)
            ->and($clasificaciones)->toContain('Encontrado')->toContain('Faltante')->toContain('No esperado');

        return true;
    });
});

it('exportar una sección concreta sólo incluye sus filas y no se limita a la página visible', function () {
    config()->set('uniformes.por_pagina', 2);
    Excel::fake();

    $encontradas = collect(range(1, 3))->map(fn () => ($this->unidad)());
    collect(range(1, 5))->each(fn () => ($this->unidad)()); // 5 faltantes
    $ronda = crearRonda($this->admin, $this->empresa);
    $encontradas->each(fn ($u) => app(EscanearUnidadInventarioFisico::class)->ejecutar($ronda, $u->public_token, $this->admin));

    $archivo = 'inventario-fisico-'.Str::slug($ronda->folio).'-'.Str::slug($this->empresa->nombre_comercial).'-'.now()->toDateString().'.xlsx';

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=xlsx&seccion=faltantes")->assertOk();
    Excel::assertDownloaded($archivo, function (ListadoExport $e): bool {
        expect($e->array())->toHaveCount(5)
            ->and(array_unique(array_column($e->array(), 0)))->toBe(['Faltante']);

        return true;
    });

    $this->actingAs($this->admin)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=xlsx&seccion=encontrados")->assertOk();
    Excel::assertDownloaded($archivo, function (ListadoExport $e): bool {
        expect($e->array())->toHaveCount(3)
            ->and(array_unique(array_column($e->array(), 0)))->toBe(['Encontrado']);

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| Exportación del LISTADO general de rondas
|--------------------------------------------------------------------------
*/

it('exporta el listado general de rondas en Excel y PDF', function () {
    crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->get('/inventarios-fisicos/exportar?formato=xlsx')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->actingAs($this->admin)->get('/inventarios-fisicos/exportar?formato=pdf');
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('la exportación general NO se limita a la página visible', function () {
    config()->set('uniformes.por_pagina', 2);
    Excel::fake();

    crearRonda($this->admin, $this->empresa);
    crearRonda($this->admin, $this->empresa);
    crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->get('/inventarios-fisicos/exportar?formato=xlsx')->assertOk();

    Excel::assertDownloaded('inventarios-fisicos-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $e): bool {
        expect($e->array())->toHaveCount(3);

        return true;
    });
});

it('la exportación general respeta el filtro de empresa y el alcance multiempresa', function () {
    Excel::fake();

    crearRonda($this->admin, $this->empresa);
    crearRonda($this->admin, $this->empresa);

    $otra = Empresa::factory()->create(['nombre_comercial' => 'OtraCo']);
    InventarioFisico::factory()->for($otra)->create();

    $multi = usuarioCon(RolSistema::Administrador->value, [$this->empresa, $otra]);

    $this->actingAs($multi)->get("/inventarios-fisicos/exportar?formato=xlsx&empresa_id={$this->empresa->id}")->assertOk();
    Excel::assertDownloaded('inventarios-fisicos-'.Str::slug($this->empresa->nombre_comercial).'-'.now()->toDateString().'.xlsx', function (ListadoExport $e): bool {
        expect($e->array())->toHaveCount(2)
            ->and(array_column($e->array(), 2))->each->toBe('DASTI');

        return true;
    });
});

it('la exportación general respeta el filtro de estado', function () {
    Excel::fake();

    crearRonda($this->admin, $this->empresa);
    $finalizada = crearRonda($this->admin, $this->empresa);
    app(FinalizarRondaInventarioFisico::class)->ejecutar($finalizada, $this->admin->id);

    $this->actingAs($this->admin)->get('/inventarios-fisicos/exportar?formato=xlsx&estado=finalizado')->assertOk();
    Excel::assertDownloaded('inventarios-fisicos-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $e) use ($finalizada): bool {
        expect($e->array())->toHaveCount(1)
            ->and($e->array()[0][0])->toBe($finalizada->folio)
            ->and($e->array()[0][6])->toBe('Finalizado');

        return true;
    });
});

it('la exportación general respeta la búsqueda por folio / nombre', function () {
    Excel::fake();

    $r1 = crearRonda($this->admin, $this->empresa);
    crearRonda($this->admin, $this->empresa);

    $this->actingAs($this->admin)->get('/inventarios-fisicos/exportar?formato=xlsx&buscar='.$r1->folio)->assertOk();
    Excel::assertDownloaded('inventarios-fisicos-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $e) use ($r1): bool {
        expect($e->array())->toHaveCount(1)->and($e->array()[0][0])->toBe($r1->folio);

        return true;
    });
});

it('un usuario no puede exportar el listado de rondas de una empresa fuera de su alcance', function () {
    crearRonda($this->admin, $this->empresa);
    $forastero = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);

    // El filtro de empresa ajena se ignora y el resultado queda acotado a SU
    // alcance (0 rondas): nunca ve las de otra empresa.
    Excel::fake();
    $this->actingAs($forastero)->get("/inventarios-fisicos/exportar?formato=xlsx&empresa_id={$this->empresa->id}")->assertOk();
    Excel::assertDownloaded('inventarios-fisicos-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $e): bool {
        expect($e->array())->toHaveCount(0);

        return true;
    });
});

it('sin permiso de ver, la exportación general responde 403', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($sinPermiso)->get('/inventarios-fisicos/exportar?formato=xlsx')->assertForbidden();
});

/**
 * Helper: crea una ronda vía la acción real (snapshot incluido).
 */
function crearRonda(User $usuario, Empresa $empresa, ?int $almacenId = null): InventarioFisico
{
    return app(CrearRondaInventarioFisico::class)->ejecutar(
        $empresa,
        'Ronda de prueba',
        $almacenId !== null ? Almacen::query()->find($almacenId) : null,
        null,
        $usuario->id,
    );
}
