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
 * Devuelve el admin y un contexto con `empresa_id` (alta de activo / rápido).
 * Los catálogos (tipos, categorías, variantes) son globales de plataforma: no
 * llevan `empresa_ids` ni habilitación por empresa.
 *
 * @return array{0: User, 1: array{empresa_id: int}}
 */
function adminEn(Empresa $empresa): array
{
    $usuario = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    return [$usuario, ['empresa_id' => $empresa->id]];
}

/*
|--------------------------------------------------------------------------
| Tipos de activo (catálogo GLOBAL de plataforma)
|--------------------------------------------------------------------------
*/

it('crea un tipo de activo global y audita', function () {
    $empresa = Empresa::factory()->create();
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/tipos-activo', ['nombre' => 'Equipo de protección'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $tipo = TipoActivo::query()->where('nombre', 'Equipo de protección')->first();
    expect($tipo)->not->toBeNull()
        ->and($tipo->codigo)->toStartWith('TAC-')
        ->and($tipo->activo)->toBeTrue();

    $this->assertDatabaseHas('bitacora_auditoria', [
        'modulo' => 'activos', 'accion' => 'tipo_crear', 'entidad_id' => $tipo->id,
    ]);
});

it('no permite dos tipos de activo con el mismo nombre a nivel plataforma', function () {
    $empresa = Empresa::factory()->create();
    TipoActivo::factory()->create(['nombre' => 'Accesorio']);
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/tipos-activo', ['nombre' => ' accesorio '])
        ->assertSessionHasErrors('nombre');
});

it('el alta rápida de tipo devuelve el tipo creado, visible para todas las empresas', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Vehiculo de carga'])
        ->assertOk()
        ->assertJsonPath('tipo.nombre', 'Vehiculo de carga');

    $tipo = TipoActivo::query()->where('nombre', 'Vehiculo de carga')->first();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value, [$otra]))
        ->getJson('/tipos-activo/buscar?q=carga')
        ->assertOk()
        ->assertJsonFragment(['id' => $tipo->id]);
});

it('desactivar un tipo de activo no elimina la fila ni toca sus activos', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->create();
    $activo = Activo::factory()->for($empresa)->create(['tipo_activo_id' => $tipo->id]);
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post("/tipos-activo/{$tipo->id}/estado")
        ->assertRedirect();

    expect($tipo->fresh()->activo)->toBeFalse()
        ->and($activo->fresh()->tipo_activo_id)->toBe($tipo->id);
});

it('un rol restringido con el permiso administra el catálogo global aunque el tipo no tenga relación con su empresa', function () {
    $miEmpresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);
    $supervisor->givePermissionTo('tipos-activo.administrar');

    $this->actingAs($supervisor)
        ->put("/tipos-activo/{$tipo->id}", ['nombre' => 'Renombrado'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($tipo->fresh()->nombre)->toBe('Renombrado');
});

it('un supervisor sin el permiso no puede administrar el catálogo de tipos de activo (403)', function () {
    $empresa = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)
        ->post('/tipos-activo', ['nombre' => 'Lo que sea'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Categorías de activo (catálogo GLOBAL de plataforma)
|--------------------------------------------------------------------------
*/

it('crea una categoría global opcionalmente ligada a un tipo de activo', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->create(['nombre' => 'Equipo de cómputo']);
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/categorias-activo', ['nombre' => 'Laptop', 'tipo_activo_id' => $tipo->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $categoria = CategoriaActivo::query()->where('nombre', 'Laptop')->first();
    expect($categoria)->not->toBeNull()
        ->and($categoria->tipo_activo_id)->toBe($tipo->id);
});

it('rechaza una categoría con un tipo de activo inexistente', function () {
    $empresa = Empresa::factory()->create();
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->post('/categorias-activo', ['nombre' => 'X', 'tipo_activo_id' => 999999])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('el alta rápida de categoría devuelve la categoría creada', function () {
    $empresa = Empresa::factory()->create();
    [$admin] = adminEn($empresa);

    $this->actingAs($admin)
        ->postJson('/categorias-activo/rapido', ['nombre' => 'Teléfono celular'])
        ->assertOk()
        ->assertJsonPath('categoria.nombre', 'Teléfono celular');
});

it('al guardar un activo con categoría del catálogo se sincroniza el espejo de texto', function () {
    $empresa = Empresa::factory()->create();
    $categoria = CategoriaActivo::factory()->create(['nombre' => 'Camisola']);
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
