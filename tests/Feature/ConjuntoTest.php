<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\UnidadActivo;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('sólo quien administra conjuntos ve los eliminados, ni forzando el filtro por URL', function () {
    Conjunto::factory()->for($this->empresa)->create(['nombre' => 'Vivo', 'activo' => true]);
    Conjunto::factory()->for($this->empresa)->create(['nombre' => 'Apagado', 'activo' => false]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($this->admin)
        ->get('/conjuntos?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->where('permisos.verEliminados', true)
            ->has('conjuntos', 1)
            ->where('conjuntos.0.nombre', 'Apagado'),
        );

    $this->actingAs($supervisor)
        ->get('/conjuntos')
        ->assertInertia(fn ($page) => $page
            ->where('permisos.verEliminados', false)
            ->has('conjuntos', 1)
            ->where('conjuntos.0.nombre', 'Vivo'),
        );

    $this->actingAs($supervisor)
        ->get('/conjuntos?estado=inactivos')
        ->assertInertia(fn ($page) => $page
            ->has('conjuntos', 1)
            ->where('conjuntos.0.nombre', 'Vivo'),
        );
});

it('crea un conjunto con componentes de la misma empresa', function () {
    $activo = Activo::factory()->for($this->empresa)->create();

    $this->actingAs($this->admin)
        ->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Uniforme completo',
            'componentes' => [
                ['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => false],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/conjuntos');

    $conjunto = Conjunto::query()->where('nombre', 'Uniforme completo')->firstOrFail();
    expect($conjunto->componentes)->toHaveCount(1)
        ->and($conjunto->componentes->first()->activo_id)->toBe($activo->id);
});

it('rechaza un componente de otra empresa', function () {
    $otra = Empresa::factory()->create();
    $activoAjeno = Activo::factory()->for($otra)->create();

    $this->actingAs($this->admin)
        ->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Kit inválido',
            'componentes' => [
                ['activo_id' => $activoAjeno->id, 'cantidad_requerida' => 1, 'talla_libre' => false],
            ],
        ])
        ->assertSessionHasErrors('componentes.0.activo_id');

    expect(Conjunto::query()->where('nombre', 'Kit inválido')->exists())->toBeFalse();
});

it('exige variante fija o libre cuando el activo usa variantes', function () {
    $talla = Talla::factory()->create();
    $activo = Activo::factory()->for($this->empresa)->create();
    $activo->tallas()->attach($talla);

    $this->actingAs($this->admin)
        ->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Kit sin variante',
            'componentes' => [
                ['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => false],
            ],
        ])
        ->assertSessionHasErrors('componentes.0.talla_id');

    $this->actingAs($this->admin)
        ->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Kit variante fija',
            'componentes' => [
                ['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_id' => $talla->id, 'talla_libre' => false],
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Kit variante libre',
            'componentes' => [
                ['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => true],
            ],
        ])
        ->assertSessionHasNoErrors();
});

it('rechaza fijar variante y marcar libre a la vez', function () {
    $talla = Talla::factory()->create();
    $activo = Activo::factory()->for($this->empresa)->create();
    $activo->tallas()->attach($talla);

    $this->actingAs($this->admin)
        ->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => 'Kit ambiguo',
            'componentes' => [
                ['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_id' => $talla->id, 'talla_libre' => true],
            ],
        ])
        ->assertSessionHasErrors('componentes.0.talla_id');
});

it('edita un conjunto reemplazando sus componentes', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create();
    $activoViejo = Activo::factory()->for($this->empresa)->create();
    ConjuntoComponente::factory()->for($conjunto)->for($activoViejo, 'activo')->create();

    $activoNuevo = Activo::factory()->for($this->empresa)->create();

    $this->actingAs($this->admin)
        ->put("/conjuntos/{$conjunto->id}", [
            'nombre' => $conjunto->nombre,
            'componentes' => [
                ['activo_id' => $activoNuevo->id, 'cantidad_requerida' => 2, 'talla_libre' => false],
            ],
        ])
        ->assertSessionHasNoErrors();

    $conjunto->refresh();
    expect($conjunto->componentes)->toHaveCount(1)
        ->and($conjunto->componentes->first()->activo_id)->toBe($activoNuevo->id)
        ->and($conjunto->componentes->first()->cantidad_requerida)->toBe(2);
});

it('activa y desactiva un conjunto', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create();

    $this->actingAs($this->admin)->post("/conjuntos/{$conjunto->id}/estado")->assertRedirect();
    expect($conjunto->fresh()->activo)->toBeFalse();

    $this->actingAs($this->admin)->post("/conjuntos/{$conjunto->id}/estado")->assertRedirect();
    expect($conjunto->fresh()->activo)->toBeTrue();
});

it('calcula la disponibilidad como el mínimo entre un componente por cantidad y uno de seguimiento individual', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create();

    $activoCantidad = Activo::factory()->for($this->empresa)->create();
    SaldoInventario::factory()->for($this->empresa)->for($this->almacen)->for($activoCantidad)->create(['talla_id' => null, 'cantidad' => 9]);
    ConjuntoComponente::factory()->for($conjunto)->for($activoCantidad, 'activo')->create(['cantidad_requerida' => 2, 'talla_id' => null, 'talla_libre' => false]);

    $activoIndividual = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->empresa)->for($activoIndividual)->for($this->almacen)->count(5)->create();
    ConjuntoComponente::factory()->for($conjunto)->for($activoIndividual, 'activo')->create(['cantidad_requerida' => 1]);

    // 9/2 = 4 (por cantidad); 5/1 = 5 (individual) -> el mínimo es 4.
    expect($conjunto->disponibilidad($this->almacen->id))->toBe(4);
});

it('la disponibilidad es 0 si algún componente no tiene existencia en ese almacén', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create();
    $activo = Activo::factory()->for($this->empresa)->create();
    ConjuntoComponente::factory()->for($conjunto)->for($activo, 'activo')->create(['cantidad_requerida' => 1, 'talla_id' => null, 'talla_libre' => false]);

    expect($conjunto->disponibilidad($this->almacen->id))->toBe(0);
});

it('muestra el detalle de un conjunto con disponibilidad por almacén', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create();
    $activo = Activo::factory()->for($this->empresa)->create();
    SaldoInventario::factory()->for($this->empresa)->for($this->almacen)->for($activo)->create(['talla_id' => null, 'cantidad' => 3]);
    ConjuntoComponente::factory()->for($conjunto)->for($activo, 'activo')->create(['cantidad_requerida' => 1, 'talla_id' => null, 'talla_libre' => false]);

    $this->actingAs($this->admin)
        ->get("/conjuntos/{$conjunto->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Conjuntos/Detalle')
            ->where('almacenes.0.disponibilidad', 3),
        );
});

it('un rol restringido no puede ver un conjunto de una empresa fuera de su alcance', function () {
    $ajena = Empresa::factory()->create();
    $conjuntoAjeno = Conjunto::factory()->for($ajena)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $respuesta = $this->actingAs($supervisor)->get("/conjuntos/{$conjuntoAjeno->id}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('asigna un código automático CON-0001 y lo va consecutivando por empresa', function () {
    $activo = Activo::factory()->for($this->empresa)->create();

    foreach (['Uno', 'Dos'] as $nombre) {
        $this->actingAs($this->admin)->post('/conjuntos', [
            'empresa_id' => $this->empresa->id,
            'nombre' => $nombre,
            'componentes' => [['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => false]],
        ])->assertSessionHasNoErrors();
    }

    expect(Conjunto::query()->where('nombre', 'Uno')->value('codigo'))->toBe('CON-0001')
        ->and(Conjunto::query()->where('nombre', 'Dos')->value('codigo'))->toBe('CON-0002');
});

it('la previsualización de código no reserva el consecutivo y avanza tras guardar', function () {
    $ruta = "/conjuntos/siguiente-codigo?empresa_id={$this->empresa->id}";

    $a = $this->actingAs($this->admin)->getJson($ruta)->json('codigo');
    $b = $this->actingAs($this->admin)->getJson($ruta)->json('codigo');

    expect($a)->toBe('CON-0001')->and($b)->toBe('CON-0001'); // no reserva

    $activo = Activo::factory()->for($this->empresa)->create();
    $this->actingAs($this->admin)->post('/conjuntos', [
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Real',
        'componentes' => [['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => false]],
    ])->assertSessionHasNoErrors();

    expect($this->actingAs($this->admin)->getJson($ruta)->json('codigo'))->toBe('CON-0002');
});

it('ignora un "codigo" enviado en un request manipulado, tanto al crear como al editar', function () {
    $activo = Activo::factory()->for($this->empresa)->create();

    $this->actingAs($this->admin)->post('/conjuntos', [
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Manipulado',
        'codigo' => 'HACKEADO',
        'componentes' => [['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => false]],
    ])->assertSessionHasNoErrors();

    $conjunto = Conjunto::query()->where('nombre', 'Manipulado')->firstOrFail();
    expect($conjunto->codigo)->toBe('CON-0001');

    $this->actingAs($this->admin)->put("/conjuntos/{$conjunto->id}", [
        'nombre' => 'Manipulado',
        'codigo' => 'OTRO-9999',
        'componentes' => [['activo_id' => $activo->id, 'cantidad_requerida' => 2, 'talla_libre' => false]],
    ])->assertSessionHasNoErrors();

    expect($conjunto->fresh()->codigo)->toBe('CON-0001');
});

it('reconcilia el contador contra códigos ya existentes, incluidos los eliminados', function () {
    Conjunto::factory()->for($this->empresa)->create(['codigo' => 'CON-0009'])->delete();

    $activo = Activo::factory()->for($this->empresa)->create();
    $this->actingAs($this->admin)->post('/conjuntos', [
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Tras hueco',
        'componentes' => [['activo_id' => $activo->id, 'cantidad_requerida' => 1, 'talla_libre' => false]],
    ])->assertSessionHasNoErrors();

    expect(Conjunto::query()->where('nombre', 'Tras hueco')->value('codigo'))->toBe('CON-0010');
});

it('la búsqueda de conjuntos se acota a la empresa indicada', function () {
    $conjunto = Conjunto::factory()->for($this->empresa)->create(['nombre' => 'Kit Oficina']);
    $otra = Empresa::factory()->create();
    Conjunto::factory()->for($otra)->create(['nombre' => 'Kit Ajeno']);

    $this->actingAs($this->admin)
        ->get("/conjuntos/buscar?empresa_id={$this->empresa->id}&q=Kit")
        ->assertOk()
        ->assertJson(['conjuntos' => [['id' => $conjunto->id, 'nombre' => 'Kit Oficina', 'codigo' => $conjunto->codigo]]]);
});
