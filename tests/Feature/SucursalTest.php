<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
});

/**
 * Usuario con rol, empresas de `empresa_usuario` y (opcional) sucursales de
 * `sucursal_usuario`, dejando la empresa activa fijada en sesión.
 *
 * @param  array<int, Empresa>  $empresas
 * @param  array<int, Sucursal>  $sucursales
 */
function usuarioSucursal(string $rol, array $empresas = [], array $sucursales = []): User
{
    $usuario = usuarioCon($rol, $empresas);
    $usuario->sucursales()->sync(collect($sucursales)->pluck('id'));

    return $usuario;
}

/*
|--------------------------------------------------------------------------
| Alcance: Superadministrador y Administrador ven todas las sucursales
|--------------------------------------------------------------------------
*/

it('un superadministrador ve las sucursales de la empresa activa', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->count(3)->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sucursales/Index')
            ->where('sucursales.total', 3)
            ->where('empresa.id', $empresa->id)
        );
});

it('un administrador ve todas las sucursales de la empresa activa sin asignación', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->count(4)->for($empresa)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sucursales.total', 4)
            ->where('permisos.crear', true)
        );
});

/*
|--------------------------------------------------------------------------
| Crear / editar / cambiar estado
|--------------------------------------------------------------------------
*/

it('un administrador puede registrar una sucursal en la empresa activa', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/sucursales', ['nombre' => 'Matriz Centro', 'telefono' => '55-1234-5678'])
        ->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    $sucursal = Sucursal::query()->where('nombre', 'Matriz Centro')->first();
    expect($sucursal)->not->toBeNull();
    expect($sucursal->empresa_id)->toBe($empresa->id);
    expect($sucursal->telefono)->toBe('5512345678');
    expect($sucursal->codigo)->toStartWith('SUC-');
    expect($sucursal->activa)->toBeTrue();
});

it('la sucursal se crea siempre en la empresa activa, ignorando el empresa_id del formulario', function () {
    $activa = Empresa::factory()->create();
    $otra = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $activa->id])
        ->post('/sucursales', ['nombre' => 'Bodega', 'empresa_id' => $otra->id]);

    $sucursal = Sucursal::query()->where('nombre', 'Bodega')->first();
    expect($sucursal->empresa_id)->toBe($activa->id);
});

it('un administrador puede editar una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['nombre' => 'Antes']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->put("/sucursales/{$sucursal->id}", ['nombre' => 'Después', 'codigo' => $sucursal->codigo])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($sucursal->fresh()->nombre)->toBe('Después');
});

it('desactivar y reactivar una sucursal no elimina la sucursal ni sus colaboradores', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['activa' => true]);
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/sucursales/{$sucursal->id}/estado")->assertSessionHas('toast');
    expect($sucursal->fresh()->activa)->toBeFalse();
    expect(Sucursal::query()->whereKey($sucursal->id)->exists())->toBeTrue();
    expect(Colaborador::query()->where('sucursal_id', $sucursal->id)->count())->toBe(2);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/sucursales/{$sucursal->id}/estado");
    expect($sucursal->fresh()->activa)->toBeTrue();
});

it('una sucursal recién creada aparece de inmediato en el listado', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/sucursales', ['nombre' => 'Nueva Terminal']);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page
            ->where('sucursales.total', 1)
            ->where('sucursales.data.0.nombre', 'Nueva Terminal')
        );
});

/*
|--------------------------------------------------------------------------
| Alcance restringido: Supervisor
|--------------------------------------------------------------------------
*/

it('un supervisor sólo ve las sucursales de su alcance dentro de la empresa activa', function () {
    $empresa = Empresa::factory()->create();
    $suyas = Sucursal::factory()->count(2)->for($empresa)->create();
    Sucursal::factory()->count(3)->for($empresa)->create(); // no asignadas

    $supervisor = usuarioSucursal(RolSistema::Supervisor->value, [$empresa], $suyas->all());

    $this->actingAs($supervisor)
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 2));
});

it('un supervisor no puede ver el detalle de una sucursal de otra empresa (IDOR)', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaA]);

    $respuesta = $this->actingAs($supervisor)
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/sucursales/{$sucursalB->id}");

    expect($respuesta->status())->toBeIn([403, 404]);
});

it('un supervisor no puede crear ni cambiar el estado de una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/sucursales', ['nombre' => 'Intento'])->assertForbidden();

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/sucursales/{$sucursal->id}/estado")->assertForbidden();

    expect(Sucursal::query()->where('nombre', 'Intento')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Empresa activa determina el contexto
|--------------------------------------------------------------------------
*/

it('la empresa activa determina qué sucursales se listan', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Sucursal::factory()->count(2)->for($empresaA)->create();
    Sucursal::factory()->count(3)->for($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 2)->where('empresa.id', $empresaA->id));

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresaB->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 3)->where('empresa.id', $empresaB->id));
});

it('no permite ver una sucursal que no pertenece a la empresa activa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/sucursales/{$sucursalB->id}")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Contador de colaboradores activos
|--------------------------------------------------------------------------
*/

it('el contador de colaboradores de la sucursal excluye a los inactivos', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->count(3)->for($empresa)->for($sucursal)->create();
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->inactivo()->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('sucursales.data.0.colaboradores_activos', 3));

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get("/sucursales/{$sucursal->id}")
        ->assertInertia(fn ($page) => $page
            ->where('sucursal.colaboradores_activos', 3)
            ->where('sucursal.colaboradores_total', 5)
        );
});

/*
|--------------------------------------------------------------------------
| Flash / toast: consumo único (no debe repetirse en cargas posteriores)
|--------------------------------------------------------------------------
*/

it('el toast de cambio de estado se muestra una sola vez y no reaparece en la siguiente carga', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['activa' => true]);
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post("/sucursales/{$sucursal->id}/estado")
        ->assertSessionHas('toast');

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('flash.toast.message', 'Sucursal desactivada correctamente.'));

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('flash.toast', null));
});

it('una recarga parcial de filtros siempre incluye el flash, evitando un toast obsoleto en el cliente', function () {
    // Causa real del bug: `flash` es un prop compartido normal, así que una
    // recarga parcial de Inertia (`only: ['sucursales', 'filtros']` al
    // cambiar orden/búsqueda) no lo incluía en la respuesta. El cliente
    // conservaba entonces el `flash.toast` de la navegación anterior en
    // memoria y el listener global lo volvía a mostrar en cada filtro. La
    // corrección envuelve `flash` en `Inertia::always()` (siempre viaja,
    // incluso en parciales) y usa `session()->pull()` en vez de `get()` para
    // que el mensaje se consuma una sola vez y las siguientes respuestas
    // sobrescriban el prop del cliente con `null`.
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page
            ->component('Sucursales/Index')
            ->reloadOnly(['sucursales', 'filtros'], fn ($reload) => $reload
                ->has('flash')
                ->where('flash.toast', null)
            )
        );
});

/*
|--------------------------------------------------------------------------
| Búsqueda y filtros
|--------------------------------------------------------------------------
*/

it('filtra por estado activas / inactivas', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Viva', 'activa' => true]);
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Apagada', 'activa' => false]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales?estado=inactivas')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 1)->where('sucursales.data.0.nombre', 'Apagada'));

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales?estado=activas')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 1)->where('sucursales.data.0.nombre', 'Viva'));
});

it('busca sucursales por nombre y ordena de forma descendente', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Terminal Norte']);
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Terminal Sur']);
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Bodega Central']);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales?buscar=Terminal')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 2));

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->get('/sucursales?orden=za')
        ->assertInertia(fn ($page) => $page->where('sucursales.data.0.nombre', 'Terminal Sur'));
});

/*
|--------------------------------------------------------------------------
| Código y validaciones
|--------------------------------------------------------------------------
*/

it('rechaza un código de sucursal duplicado dentro de la misma empresa', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['codigo' => 'MATRIZ']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->from('/sucursales')
        ->post('/sucursales', ['nombre' => 'Otra', 'codigo' => 'MATRIZ'])
        ->assertRedirect('/sucursales')
        ->assertSessionHasErrors('codigo');
});

it('permite el mismo código de sucursal en empresas distintas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Sucursal::factory()->for($empresaA)->create(['codigo' => 'MATRIZ']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaB->id])
        ->post('/sucursales', ['nombre' => 'Matriz B', 'codigo' => 'MATRIZ'])
        ->assertSessionHasNoErrors();
});

it('valida el teléfono a 10 dígitos con mensaje en español y no genera un 500 con tipos raros', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])->from('/sucursales')
        ->post('/sucursales', ['nombre' => 'Con Teléfono', 'telefono' => '123'])
        ->assertRedirect('/sucursales')
        ->assertSessionHasErrors(['telefono' => 'El teléfono debe contener 10 dígitos.']);

    $this->actingAs($admin)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])->from('/sucursales')
        ->post('/sucursales', ['nombre' => ['array', 'no', 'string']])
        ->assertRedirect('/sucursales')
        ->assertSessionHasErrors('nombre');
});
