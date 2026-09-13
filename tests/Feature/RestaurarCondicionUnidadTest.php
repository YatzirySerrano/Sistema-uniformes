<?php

use App\Acciones\RestaurarCondicionUnidadActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;

/**
 * F: una unidad "En reparación" / "Inservible" deja de ser entregable pero
 * sigue existiendo como la MISMA unidad física (mismo código, mismo
 * public_token, mismo historial) — nunca se crea una nueva ni se manda
 * automáticamente a Baja. `RestaurarCondicionUnidadActivo` es el único
 * camino explícito y auditado para que vuelva a una condición operativa.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('restaura una unidad "En reparación" a Funcionando conservando codigo, public_token e historial (nunca crea otra unidad)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create();
    $codigoOriginal = $unidad->codigo;
    $tokenOriginal = $unidad->public_token;
    $idOriginal = $unidad->id;

    app(RestaurarCondicionUnidadActivo::class)->ejecutar($unidad, CondicionUnidadActivo::Funcionando, 'Reparado por proveedor X', $this->admin->id);

    $unidad->refresh();
    expect($unidad->id)->toBe($idOriginal)
        ->and($unidad->codigo)->toBe($codigoOriginal)
        ->and($unidad->public_token)->toBe($tokenOriginal)
        ->and($unidad->condicion)->toBe(CondicionUnidadActivo::Funcionando)
        ->and($unidad->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($unidad->esEntregable())->toBeTrue()
        ->and(UnidadActivo::query()->where('activo_id', $this->activo->id)->count())->toBe(1);

    expect(DB::table('bitacora_auditoria')->where('accion', 'unidad_restaurar_condicion')->where('entidad_id', $unidad->id)->exists())->toBeTrue();
    // No es un movimiento de stock (la unidad nunca salió del almacén): no
    // genera `MovimientoInventario`, sólo bitácora.
    expect(MovimientoInventario::query()->where('unidad_activo_id', $unidad->id)->exists())->toBeFalse();
});

it('restaura una unidad "Inservible" a Funcionando, y vuelve a ser seleccionable en una nueva entrega', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create();

    expect($unidad->esEntregable())->toBeFalse();

    app(RestaurarCondicionUnidadActivo::class)->ejecutar($unidad, CondicionUnidadActivo::Funcionando, null, $this->admin->id);

    expect($unidad->fresh()->esEntregable())->toBeTrue();
});

it('rechaza restaurar una unidad que ya está Funcionando (no hay nada que restaurar)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    expect(fn () => app(RestaurarCondicionUnidadActivo::class)->ejecutar($unidad, CondicionUnidadActivo::Funcionando, null, $this->admin->id))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('rechaza restaurar una unidad que no está en almacén (p. ej. dada de baja)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->baja()->create(['condicion' => CondicionUnidadActivo::Inservible]);

    expect(fn () => app(RestaurarCondicionUnidadActivo::class)->ejecutar($unidad, CondicionUnidadActivo::Funcionando, null, $this->admin->id))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('rechaza una condición resultante que sea pérdida/robo (eso es una incidencia, no una restauración)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create();

    expect(fn () => app(RestaurarCondicionUnidadActivo::class)->ejecutar($unidad, CondicionUnidadActivo::Perdido, null, $this->admin->id))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('restaura la condición vía HTTP con permiso, y el endpoint manipulado rechaza una condición resultante de incidencia', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::EnReparacion)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$unidad->public_token}/restaurar-condicion", [
            'condicion_resultante' => 'funcionando',
            'notas' => 'Listo tras mantenimiento',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($unidad->fresh()->condicion)->toBe(CondicionUnidadActivo::Funcionando);

    $otra = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create();

    $this->actingAs($this->admin)
        ->post("/activos/unidades/{$otra->public_token}/restaurar-condicion", [
            'condicion_resultante' => 'robado',
        ])
        ->assertSessionHasErrors('condicion_resultante');

    expect($otra->fresh()->condicion)->toBe(CondicionUnidadActivo::Inservible);
});

it('un usuario sin permiso unidades-activo.administrar no puede restaurar la condición (403)', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create();

    $sinPermiso = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($sinPermiso)
        ->post("/activos/unidades/{$unidad->public_token}/restaurar-condicion", ['condicion_resultante' => 'funcionando'])
        ->assertForbidden();

    expect($unidad->fresh()->condicion)->toBe(CondicionUnidadActivo::Inservible);
});

it('una unidad Inservible no puede seleccionarse en una nueva entrega aunque el endpoint reciba su id directamente', function () {
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($this->activo)->for($this->almacen)
        ->conCondicion(CondicionUnidadActivo::Inservible)->create();
    $colaborador = Colaborador::factory()->for($this->empresa)->create();

    $this->actingAs($this->admin)
        ->post('/entregas', [
            'colaborador_id' => $colaborador->id,
            'almacen_id' => $this->almacen->id,
            'fecha_entrega' => now()->toDateString(),
            'unidades' => [['unidad_activo_id' => $unidad->id]],
            'firma' => firmaDemoBase64(),
            'firma_operador' => firmaDemoBase64(),
            'aceptacion' => true,
        ])
        ->assertSessionHasErrors('unidades.0.unidad_activo_id');

    expect($unidad->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($unidad->fresh()->colaborador_id)->toBeNull();
});
