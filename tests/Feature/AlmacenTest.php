<?php

use App\Enums\RolSistema;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un administrador ve los almacenes de la empresa activa y no los de otra empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Almacen::factory()->count(2)->for($empresaA)->create();
    Almacen::factory()->count(3)->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get('/almacenes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Almacenes/Index')
            ->where('almacenes.total', 2)
            ->where('empresa.id', $empresaA->id)
        );
});

it('un administrador crea un almacén con código autogenerado y varias sucursales abastecidas', function () {
    $empresa = Empresa::factory()->create();
    $sucursales = Sucursal::factory()->count(3)->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/almacenes', [
            'nombre' => 'Almacén Morelos',
            'telefono' => '7771234567',
            'sucursales' => $sucursales->take(2)->pluck('id')->all(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast');

    $almacen = Almacen::query()->where('nombre', 'Almacén Morelos')->first();
    expect($almacen)->not->toBeNull();
    expect($almacen->empresa_id)->toBe($empresa->id);
    expect($almacen->codigo)->toStartWith('ALM-');
    expect($almacen->telefono)->toBe('7771234567');
    expect($almacen->sucursales()->count())->toBe(2);
});

it('el almacén se crea siempre en la empresa activa, ignorando el empresa_id del formulario', function () {
    $activa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $activa->id])
        ->post('/almacenes', ['nombre' => 'Bodega', 'empresa_id' => $otra->id]);

    expect(Almacen::query()->where('nombre', 'Bodega')->first()->empresa_id)->toBe($activa->id);
});

it('rechaza un código de almacén duplicado dentro de la misma empresa y lo permite en otra', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Almacen::factory()->for($empresaA)->create(['codigo' => 'CENTRAL']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'Otro', 'codigo' => 'CENTRAL'])
        ->assertSessionHasErrors('codigo');

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaB->id])
        ->post('/almacenes', ['nombre' => 'Central B', 'codigo' => 'CENTRAL'])
        ->assertSessionHasNoErrors();
});

it('un administrador puede editar un almacén y desvincular una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $sucursales = Sucursal::factory()->count(2)->for($empresa)->create();
    $almacen = Almacen::factory()->for($empresa)->create(['nombre' => 'Antes']);
    $almacen->sucursales()->sync($sucursales->pluck('id'));

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->put("/almacenes/{$almacen->id}", [
            'nombre' => 'Después',
            'sucursales' => [$sucursales->first()->id],
        ])
        ->assertSessionHasNoErrors();

    expect($almacen->fresh()->nombre)->toBe('Después');
    expect($almacen->sucursales()->pluck('sucursales.id')->all())->toBe([$sucursales->first()->id]);
});

it('el endpoint de sucursales abastecidas sincroniza la relación', function () {
    $empresa = Empresa::factory()->create();
    $sucursales = Sucursal::factory()->count(3)->for($empresa)->create();
    $almacen = Almacen::factory()->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->put("/almacenes/{$almacen->id}/sucursales", ['sucursales' => $sucursales->pluck('id')->all()])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast');

    expect($almacen->sucursales()->count())->toBe(3);
});

it('no permite abastecer una sucursal de otra empresa', function () {
    $empresa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();
    $sucursalAjena = Sucursal::factory()->for($otra)->create();
    $almacen = Almacen::factory()->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/almacenes')
        ->put("/almacenes/{$almacen->id}/sucursales", ['sucursales' => [$sucursalAjena->id]])
        ->assertSessionHasErrors('sucursales.0');

    expect($almacen->sucursales()->count())->toBe(0);
});

it('acepta un responsable válido de la empresa activa y rechaza uno de otra empresa', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $responsable = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $otra = Empresa::factory()->create();
    $sucursalOtra = Sucursal::factory()->for($otra)->create();
    $ajeno = Colaborador::factory()->for($otra)->for($sucursalOtra)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/almacenes', ['nombre' => 'Con Responsable', 'responsable_colaborador_id' => $responsable->id])
        ->assertSessionHasNoErrors();
    expect(Almacen::query()->where('nombre', 'Con Responsable')->first()->responsable_colaborador_id)->toBe($responsable->id);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'Responsable Ajeno', 'responsable_colaborador_id' => $ajeno->id])
        ->assertSessionHasErrors('responsable_colaborador_id');
});

it('rechaza como responsable a un colaborador inactivo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $inactivo = Colaborador::factory()->for($empresa)->for($sucursal)->inactivo()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => 'X', 'responsable_colaborador_id' => $inactivo->id])
        ->assertSessionHasErrors('responsable_colaborador_id');
});

it('activar y desactivar un almacén no toca sus sucursales ni el catálogo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->for($empresa)->create(['activo' => true]);
    $almacen->sucursales()->sync([$sucursal->id]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/almacenes/{$almacen->id}/estado")->assertSessionHas('toast');
    expect($almacen->fresh()->activo)->toBeFalse();
    expect($almacen->sucursales()->count())->toBe(1);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/almacenes/{$almacen->id}/estado");
    expect($almacen->fresh()->activo)->toBeTrue();
});

it('un supervisor no puede crear ni cambiar el estado de un almacén', function () {
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->for($empresa)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/almacenes', ['nombre' => 'Intento'])->assertForbidden();

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/almacenes/{$almacen->id}/estado")->assertForbidden();

    expect(Almacen::query()->where('nombre', 'Intento')->exists())->toBeFalse();
});

it('un administrador no puede ver el detalle de un almacén de otra empresa (IDOR)', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $almacenB = Almacen::factory()->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/almacenes/{$almacenB->id}")
        ->assertNotFound();
});

it('valida sin generar un 500 cuando el nombre llega como arreglo', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/almacenes')
        ->post('/almacenes', ['nombre' => ['no', 'texto'], 'sucursales' => 'tampoco'])
        ->assertRedirect('/almacenes')
        ->assertSessionHasErrors('nombre');
});

it('la búsqueda de colaboradores para responsable sólo devuelve activos de la empresa activa', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->for($empresa)->for($sucursal)->create(['nombre_completo' => 'Ana Buscable']);
    Colaborador::factory()->for($empresa)->for($sucursal)->inactivo()->create(['nombre_completo' => 'Ana Inactiva']);

    $otra = Empresa::factory()->create();
    $sucursalOtra = Sucursal::factory()->for($otra)->create();
    Colaborador::factory()->for($otra)->for($sucursalOtra)->create(['nombre_completo' => 'Ana Ajena']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->getJson('/almacenes/colaboradores-buscar?q=Ana')
        ->assertOk()
        ->assertJsonPath('colaboradores.0.nombre_completo', 'Ana Buscable')
        ->assertJsonCount(1, 'colaboradores');
});
