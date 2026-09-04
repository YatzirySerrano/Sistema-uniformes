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
 * Devuelve el admin y un contexto con `empresa_id` (alta de activo / rápido) y
 * `empresa_ids` (alta de catálogo compartido).
 *
 * @return array{0: User, 1: array{empresa_id: int, empresa_ids: array<int, int>}}
 */
function adminEn(Empresa $empresa): array
{
    $usuario = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    return [$usuario, ['empresa_id' => $empresa->id, 'empresa_ids' => [$empresa->id]]];
}

/*
|--------------------------------------------------------------------------
| Tipos de activo (catálogo compartido)
|--------------------------------------------------------------------------
*/

it('crea un tipo de activo compartido, lo habilita para la empresa y audita', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/tipos-activo', ['nombre' => 'Equipo de protección', ...$ctx])
        ->assertRedirect()->assertSessionHasNoErrors();

    $tipo = TipoActivo::query()->where('nombre', 'Equipo de protección')->first();
    expect($tipo)->not->toBeNull()
        ->and($tipo->codigo)->toStartWith('TAC-')
        ->and($tipo->activo)->toBeTrue()
        ->and($tipo->empresas()->whereKey($empresa->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('bitacora_auditoria', [
        'modulo' => 'activos', 'accion' => 'tipo_crear', 'entidad_id' => $tipo->id, 'empresa_id' => $empresa->id,
    ]);
});

it('no permite dos tipos de activo con el mismo nombre a nivel plataforma', function () {
    $empresa = Empresa::factory()->create();
    TipoActivo::factory()->paraEmpresa($empresa)->create(['nombre' => 'Accesorio']);
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/tipos-activo', ['nombre' => ' accesorio ', ...$ctx])
        ->assertSessionHasErrors('nombre');
});

it('el alta rápida devuelve el tipo creado y lo habilita sólo para la empresa del formulario', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Vehículo', 'empresa_id' => $empresa->id])
        ->assertOk()
        ->assertJsonPath('tipo.nombre', 'Vehículo');

    $tipo = TipoActivo::query()->where('nombre', 'Vehículo')->first();
    expect($tipo->empresas()->pluck('empresas.id')->all())->toBe([$empresa->id])
        ->and($tipo->empresas()->whereKey($otra->id)->exists())->toBeFalse();
});

it('desactivar un tipo de activo no elimina la fila ni toca sus activos', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create(['tipo_activo_id' => $tipo->id]);
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post("/tipos-activo/{$tipo->id}/estado")
        ->assertRedirect();

    expect($tipo->fresh()->activo)->toBeFalse()
        ->and($activo->fresh()->tipo_activo_id)->toBe($tipo->id);
});

it('un rol restringido con el permiso no puede administrar un tipo no habilitado para ninguna de sus empresas', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $tipoAjeno = TipoActivo::factory()->paraEmpresa($ajena)->create();

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
        ->post('/tipos-activo', ['nombre' => 'Lo que sea', 'empresa_ids' => [$empresa->id]])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Categorías de activo (catálogo compartido)
|--------------------------------------------------------------------------
*/

it('crea una categoría compartida opcionalmente ligada a un tipo de activo', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->paraEmpresa($empresa)->create(['nombre' => 'Equipo de cómputo']);
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/categorias-activo', ['nombre' => 'Laptop', 'tipo_activo_id' => $tipo->id, ...$ctx])
        ->assertRedirect()->assertSessionHasNoErrors();

    $categoria = CategoriaActivo::query()->where('nombre', 'Laptop')->first();
    expect($categoria)->not->toBeNull()
        ->and($categoria->tipo_activo_id)->toBe($tipo->id)
        ->and($categoria->empresas()->whereKey($empresa->id)->exists())->toBeTrue();
});

it('rechaza una categoría con un tipo de activo inexistente', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $ctx] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/categorias-activo', ['nombre' => 'X', 'tipo_activo_id' => 999999, ...$ctx])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('el alta rápida de categoría devuelve la categoría creada', function () {
    $empresa = Empresa::factory()->create();
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->postJson('/categorias-activo/rapido', ['nombre' => 'Teléfono celular', 'empresa_id' => $empresa->id])
        ->assertOk()
        ->assertJsonPath('categoria.nombre', 'Teléfono celular');
});

it('al guardar un activo con categoría del catálogo se sincroniza el espejo de texto', function () {
    $empresa = Empresa::factory()->create();
    $categoria = CategoriaActivo::factory()->paraEmpresa($empresa)->create(['nombre' => 'Camisola']);
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/activos', [
            'nombre' => 'Camisola manga larga azul',
            'tipo_control' => 'cantidad',
            'categoria_id' => $categoria->id,
            'empresa_id' => $empresa->id,
        ])
        ->assertRedirect();

    $activo = Activo::query()->where('nombre', 'Camisola manga larga azul')->first();
    expect($activo->categoria_id)->toBe($categoria->id)
        ->and($activo->categoria)->toBe('Camisola');
});
