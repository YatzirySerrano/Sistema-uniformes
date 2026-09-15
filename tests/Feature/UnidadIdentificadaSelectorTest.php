<?php

use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\UnidadActivo;

/**
 * Selector de "Unidades identificadas" (Entregas/Crear.vue, Traspasos y
 * cualquier otro consumidor de `UnidadActivoController::buscar()`): el código
 * sigue siendo el identificador principal, pero el endpoint YA devolvía
 * marca/modelo/IMEI enmascarado/teléfono (`especificacion` eager-cargada, sin
 * N+1) — este archivo cubre exactamente esos contratos para que el frontend
 * pueda enriquecer el desplegable sin backend nuevo.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    $this->activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Celular']);
});

it('el endpoint conserva el código como identificador y siempre lo devuelve', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect($resultado[0]['codigo'])->toBe($unidad->codigo);
});

it('devuelve marca_modelo cuando la unidad tiene especificación técnica', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();
    $unidad->especificacion()->create(['marca' => 'Dell', 'modelo' => 'Latitude 5440']);

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect($resultado[0]['marca_modelo'])->toBe('Dell Latitude 5440');
});

it('devuelve el IMEI ENMASCARADO, nunca el IMEI completo', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();
    $unidad->especificacion()->create(['marca' => 'Samsung', 'modelo' => 'Galaxy Z Flip 6', 'imei' => '353883568904821']);

    $json = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk();

    $resultado = $json->json('unidades');
    expect($resultado[0]['imei_mascara'])->toBe('••••4821');

    // El IMEI completo nunca debe viajar en la respuesta JSON de este endpoint.
    expect($json->getContent())->not->toContain('353883568904821');
});

it('una unidad sin ninguna especificación técnica sigue funcionando (campos null, sin error)', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect($resultado[0]['codigo'])->toBe($unidad->codigo)
        ->and($resultado[0]['marca_modelo'])->toBeNull()
        ->and($resultado[0]['imei_mascara'])->toBeNull()
        ->and($resultado[0]['numero_telefonico'])->toBeNull();
});

it('la búsqueda por código sigue funcionando', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}&q={$unidad->codigo}")
        ->assertOk()->json('unidades');

    expect(collect($resultado)->pluck('id')->all())->toBe([$unidad->id]);
});

it('la búsqueda también encuentra por marca, modelo, IMEI y número telefónico', function () {
    $dell = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();
    $dell->especificacion()->create(['marca' => 'Dell', 'modelo' => 'Latitude 5440']);
    $samsung = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();
    $samsung->especificacion()->create(['marca' => 'Samsung', 'modelo' => 'Galaxy Z Flip 6', 'imei' => '353883568904821', 'numero_telefonico' => '7771234567']);

    $porMarca = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&q=Dell")
        ->json('unidades');
    expect(collect($porMarca)->pluck('id')->all())->toBe([$dell->id]);

    $porModelo = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&q=Flip")
        ->json('unidades');
    expect(collect($porModelo)->pluck('id')->all())->toBe([$samsung->id]);

    $porImei = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&q=353883568904821")
        ->json('unidades');
    expect(collect($porImei)->pluck('id')->all())->toBe([$samsung->id]);

    $porTelefono = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&q=7771234567")
        ->json('unidades');
    expect(collect($porTelefono)->pluck('id')->all())->toBe([$samsung->id]);
});

it('el scope de empresa se respeta: un activo de otra empresa no expone sus unidades', function () {
    $activoAjeno = Activo::factory()->for($this->datos['empresaB'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->datos['empresaB'])->for($activoAjeno)->for($this->datos['almacenB'])->create();

    $ajeno = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);

    $resultado = $this->actingAs($ajeno)
        ->getJson("/activos/unidades/buscar?activo_id={$activoAjeno->id}")
        ->assertOk()->json('unidades');

    expect($resultado)->toBe([]);
});

it('el almacén se respeta: no mezcla unidades de otro almacén del mismo activo', function () {
    $enA = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($otroAlmacen)->create();

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect(collect($resultado)->pluck('id')->all())->toBe([$enA->id]);
});

it('el activo seleccionado se respeta: no mezcla unidades de otro activo', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create();
    $otroActivo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($otroActivo)->for($this->datos['almacenA'])->create();

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect(collect($resultado)->pluck('id')->all())->toBe([$unidad->id]);
});

it('una unidad no entregable se identifica correctamente con su motivo', function () {
    UnidadActivo::factory()->for($this->datos['empresaA'])->for($this->activo)->for($this->datos['almacenA'])->create(['estado' => 'asignada']);

    $resultado = $this->actingAs($this->admin)
        ->getJson("/activos/unidades/buscar?activo_id={$this->activo->id}&almacen_id={$this->datos['almacenA']->id}")
        ->assertOk()->json('unidades');

    expect($resultado[0]['entregable'])->toBeFalse()
        ->and($resultado[0]['motivo_no_entregable'])->not->toBeNull();
});

it('no puede entregarse una unidad ajena/manipulada aunque se envíe directamente en el request de crear entrega', function () {
    $unidadAjena = UnidadActivo::factory()->for($this->datos['empresaB'])->for(
        Activo::factory()->for($this->datos['empresaB'])->seguimientoIndividual()
    )->for($this->datos['almacenB'])->create();

    $this->datos['colaboradorA']->update(['correo' => 'colaborador@empresa.test']);

    $respuesta = $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'activos' => [],
        'unidades' => [
            ['unidad_activo_id' => $unidadAjena->id],
        ],
        'conjuntos' => [],
        'notas' => '',
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
    ]);

    $respuesta->assertSessionHasErrors('unidades.0.unidad_activo_id');
    expect($unidadAjena->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen);
});
