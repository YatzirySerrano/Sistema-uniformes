<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\Empresa;
use App\Models\TipoActivo;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
});

function adminEn(Empresa $empresa): array
{
    $usuario = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    return [$usuario, [ContextoEmpresa::SESSION_KEY => $empresa->id]];
}

/*
|--------------------------------------------------------------------------
| Tipos de activo
|--------------------------------------------------------------------------
*/

it('crea un tipo de activo con código autogenerado y auditoría', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->post('/tipos-activo', ['nombre' => 'Equipo de protección'])
        ->assertRedirect();

    $tipo = TipoActivo::query()->where('empresa_id', $empresa->id)->where('nombre', 'Equipo de protección')->first();
    expect($tipo)->not->toBeNull()
        ->and($tipo->codigo)->toStartWith('TAC-')
        ->and($tipo->activo)->toBeTrue();

    $this->assertDatabaseHas('bitacora_auditoria', ['modulo' => 'activos', 'accion' => 'tipo_crear', 'entidad_id' => $tipo->id]);
});

it('no permite dos tipos de activo con el mismo nombre en la empresa', function () {
    $empresa = Empresa::factory()->create();
    TipoActivo::factory()->for($empresa)->create(['nombre' => 'Accesorio']);
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->post('/tipos-activo', ['nombre' => 'Accesorio'])
        ->assertSessionHasErrors('nombre');
});

it('el alta rápida devuelve el tipo creado para seleccionarlo en el acto', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->postJson('/tipos-activo/rapido', ['nombre' => 'Vehículo'])
        ->assertOk()
        ->assertJsonPath('tipo.nombre', 'Vehículo');
});

it('desactivar un tipo de activo no elimina la fila ni toca sus activos', function () {
    $empresa = Empresa::factory()->create();
    $tipo = TipoActivo::factory()->for($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create(['tipo_activo_id' => $tipo->id]);
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->post("/tipos-activo/{$tipo->id}/estado")
        ->assertRedirect();

    expect($tipo->fresh()->activo)->toBeFalse()
        ->and($activo->fresh()->tipo_activo_id)->toBe($tipo->id);
});

it('un usuario no puede tocar tipos de activo de otra empresa (404)', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    $tipoAjeno = TipoActivo::factory()->for($otra)->create();
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->put("/tipos-activo/{$tipoAjeno->id}", ['nombre' => 'Hackeado'])
        ->assertNotFound();
});

it('un supervisor no puede administrar el catálogo de tipos de activo (403)', function () {
    $empresa = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/tipos-activo', ['nombre' => 'Lo que sea'])
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
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->post('/categorias-activo', ['nombre' => 'Laptop', 'tipo_activo_id' => $tipo->id])
        ->assertRedirect();

    $categoria = CategoriaActivo::query()->where('empresa_id', $empresa->id)->where('nombre', 'Laptop')->first();
    expect($categoria)->not->toBeNull()
        ->and($categoria->tipo_activo_id)->toBe($tipo->id);
});

it('rechaza una categoría con tipo de activo de otra empresa', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    $tipoAjeno = TipoActivo::factory()->for($otra)->create();
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->post('/categorias-activo', ['nombre' => 'X', 'tipo_activo_id' => $tipoAjeno->id])
        ->assertSessionHasErrors('tipo_activo_id');
});

it('el alta rápida de categoría devuelve la categoría creada', function () {
    $empresa = Empresa::factory()->create();
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->postJson('/categorias-activo/rapido', ['nombre' => 'Teléfono celular'])
        ->assertOk()
        ->assertJsonPath('categoria.nombre', 'Teléfono celular');
});

it('al guardar un activo con categoría del catálogo se sincroniza el espejo de texto', function () {
    $empresa = Empresa::factory()->create();
    $categoria = CategoriaActivo::factory()->for($empresa)->create(['nombre' => 'Camisola']);
    [$admin, $sesion] = adminEn($empresa);

    $this->actingAs($admin)->withSession($sesion)
        ->post('/activos', [
            'nombre' => 'Camisola manga larga azul',
            'tipo_control' => 'cantidad',
            'categoria_id' => $categoria->id,
        ])
        ->assertRedirect();

    $activo = Activo::query()->where('nombre', 'Camisola manga larga azul')->first();
    expect($activo->categoria_id)->toBe($categoria->id)
        ->and($activo->categoria)->toBe('Camisola');
});
