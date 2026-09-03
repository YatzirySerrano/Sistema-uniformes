<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\TipoActivo;
use App\Models\User;

beforeEach(function () {
    sembrarRolesPermisos();
});

/**
 * @return array{0: User, 1: array{empresa_id: int}}
 */
function adminEn(Empresa $empresa): array
{
    $usuario = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    return [$usuario, ['empresa_id' => $empresa->id]];
}

/*
|--------------------------------------------------------------------------
| Tipos de activo
|--------------------------------------------------------------------------
*/

it('crea un tipo de activo con código autogenerado y auditoría', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/tipos-activo', ['nombre' => 'Equipo de protección', ...$ctx])
        ->assertRedirect();

    $tipo = TipoActivo::query()->where('empresa_id', $empresa->id)->where('nombre', 'Equipo de protección')->first();
    expect($tipo)->not->toBeNull()
        ->and($tipo->codigo)->toStartWith('TAC-')
        ->and($tipo->activo)->toBeTrue();

    $this->assertDatabaseHas('bitacora_auditoria', [
        'modulo' => 'activos', 'accion' => 'tipo_crear', 'entidad_id' => $tipo->id, 'empresa_id' => $empresa->id,
    ]);
});

it('no permite dos tipos de activo con el mismo nombre en la empresa', function () {
    $empresa = Empresa::factory()->create();
    TipoActivo::factory()->for($empresa)->create(['nombre' => 'Accesorio']);
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/tipos-activo', ['nombre' => 'Accesorio', ...$ctx])
        ->assertSessionHasErrors('nombre');
});

it('el alta rápida devuelve el tipo creado para seleccionarlo en el acto', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Vehículo', ...$ctx])
        ->assertOk()
        ->assertJsonPath('tipo.nombre', 'Vehículo');
});

it('desactivar un tipo de activo no elimina la fila ni toca sus activos', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->for($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create(['tipo_activo_id' => $tipo->id]);
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post("/tipos-activo/{$tipo->id}/estado")
        ->assertRedirect();

    expect($tipo->fresh()->activo)->toBeFalse()
        ->and($activo->fresh()->tipo_activo_id)->toBe($tipo->id);
});

it('un rol restringido con el permiso no puede tocar tipos de activo de una empresa fuera de su alcance', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $tipoAjeno = TipoActivo::factory()->for($ajena)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);
    $supervisor->givePermissionTo('tipos-activo.administrar');

    $respuesta = $this->actingAs($supervisor)
        ->put("/tipos-activo/{$tipoAjeno->id}", ['nombre' => 'Hackeado']);

    expect($respuesta->status())->toBeIn([403, 404]);
});

it('un supervisor no puede administrar el catálogo de tipos de activo (403)', function () {
    $empresa = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)
        ->post('/tipos-activo', ['nombre' => 'Lo que sea', 'empresa_id' => $empresa->id])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Categorías de activo
|--------------------------------------------------------------------------
*/

it('crea una categoría opcionalmente ligada a un tipo de activo', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->for($empresa)->create(['nombre' => 'Equipo de cómputo']);
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/categorias-activo', ['nombre' => 'Laptop', 'tipo_activo_id' => $tipo->id, ...$ctx])
        ->assertRedirect();

    $categoria = CategoriaActivo::query()->where('empresa_id', $empresa->id)->where('nombre', 'Laptop')->first();
    expect($categoria)->not->toBeNull()
        ->and($categoria->tipo_activo_id)->toBe($tipo->id);
});

it('rechaza una categoría con tipo de activo de otra empresa', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    $tipoAjeno = TipoActivo::factory()->for($otra)->create();
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/categorias-activo', ['nombre' => 'X', 'tipo_activo_id' => $tipoAjeno->id, ...$ctx])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('el alta rápida de categoría devuelve la categoría creada', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->postJson('/categorias-activo/rapido', ['nombre' => 'Teléfono celular', ...$ctx])
        ->assertOk()
        ->assertJsonPath('categoria.nombre', 'Teléfono celular');
});

it('al guardar un activo con categoría del catálogo se sincroniza el espejo de texto', function () {
    $empresa = Empresa::factory()->create();
    $categoria = CategoriaActivo::factory()->for($empresa)->create(['nombre' => 'Camisola']);
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/activos', [
            'nombre' => 'Camisola manga larga azul',
            'tipo_control' => 'cantidad',
            'categoria_id' => $categoria->id,
            ...$ctx,
        ])
        ->assertRedirect();

    $activo = Activo::query()->where('nombre', 'Camisola manga larga azul')->first();
    expect($activo->categoria_id)->toBe($categoria->id)
        ->and($activo->categoria)->toBe('Camisola');
});
