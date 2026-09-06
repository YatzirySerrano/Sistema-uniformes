<?php

use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;

beforeEach(function () {
    sembrarRolesPermisos();
});

/**
 * Usuario con rol, empresas de `empresa_usuario` y (opcional) sucursales de
 * `sucursal_usuario`.
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
| Listado sin "empresa activa": todo lo autorizado, filtrable por empresa
|--------------------------------------------------------------------------
*/

it('un administrador ve todas las sucursales y puede filtrarlas por empresa', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Sucursal::factory()->count(2)->for($empresaA)->create();
    Sucursal::factory()->count(3)->for($empresaB)->create();

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/sucursales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sucursales/Index')
            ->where('sucursales.total', 5)
            ->where('permisos.crear', true)
        );

    $this->actingAs($admin)
        ->get('/sucursales?empresa_id='.$empresaA->id)
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 2));
});

/*
|--------------------------------------------------------------------------
| Crear / editar / cambiar estado
|--------------------------------------------------------------------------
*/

it('un administrador registra una sucursal en la empresa indicada', function () {
    $empresa = Empresa::factory()->create();

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/sucursales', ['nombre' => 'Matriz Centro', 'telefono' => '55-1234-5678', 'empresa_id' => $empresa->id])
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

it('rechaza registrar una sucursal en una empresa fuera del alcance del usuario', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);
    $supervisor->givePermissionTo('sucursales.crear');

    $this->actingAs($supervisor)
        ->from('/sucursales')
        ->post('/sucursales', ['nombre' => 'Bodega', 'empresa_id' => $ajena->id])
        ->assertSessionHasErrors('empresa_id');

    expect(Sucursal::query()->where('nombre', 'Bodega')->exists())->toBeFalse();
});

it('un administrador puede editar una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['nombre' => 'Antes']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
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

    $this->actingAs($admin)
        ->post("/sucursales/{$sucursal->id}/estado")->assertSessionHas('toast');
    expect($sucursal->fresh()->activa)->toBeFalse();
    expect(Colaborador::query()->where('sucursal_id', $sucursal->id)->count())->toBe(2);

    $this->actingAs($admin)->post("/sucursales/{$sucursal->id}/estado");
    expect($sucursal->fresh()->activa)->toBeTrue();
});

it('una sucursal recién creada aparece de inmediato en el listado', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/sucursales', ['nombre' => 'Nueva Terminal', 'empresa_id' => $empresa->id]);

    $this->actingAs($admin)
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

it('un supervisor sólo ve las sucursales de su alcance', function () {
    $empresa = Empresa::factory()->create();
    $suyas = Sucursal::factory()->count(2)->for($empresa)->create();
    Sucursal::factory()->count(3)->for($empresa)->create();

    $supervisor = usuarioSucursal(RolSistema::Supervisor->value, [$empresa], $suyas->all());

    $this->actingAs($supervisor)
        ->get('/sucursales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 2));
});

it('un supervisor no puede ver el detalle de una sucursal de otra empresa (IDOR)', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaA]);

    $respuesta = $this->actingAs($supervisor)->get("/sucursales/{$sucursalB->id}");
    expect($respuesta->status())->toBeIn([403, 404]);
});

it('un supervisor no puede crear ni cambiar el estado de una sucursal', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    $this->actingAs($supervisor)
        ->post('/sucursales', ['nombre' => 'Intento', 'empresa_id' => $empresa->id])->assertForbidden();

    $this->actingAs($supervisor)
        ->post("/sucursales/{$sucursal->id}/estado")->assertForbidden();

    expect(Sucursal::query()->where('nombre', 'Intento')->exists())->toBeFalse();
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

    $this->actingAs($admin)
        ->get('/sucursales?empresa_id='.$empresa->id)
        ->assertInertia(fn ($page) => $page->where('sucursales.data.0.colaboradores_activos', 3));

    $this->actingAs($admin)
        ->get("/sucursales/{$sucursal->id}")
        ->assertInertia(fn ($page) => $page
            ->where('sucursal.colaboradores_activos', 3)
            ->where('sucursal.colaboradores_total', 5)
        );
});

/*
|--------------------------------------------------------------------------
| Flash / toast: consumo único
|--------------------------------------------------------------------------
*/

it('el toast de cambio de estado se muestra una sola vez y no reaparece en la siguiente carga', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['activa' => true]);
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post("/sucursales/{$sucursal->id}/estado")
        ->assertSessionHas('toast');

    $this->actingAs($admin)
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('flash.toast.message', 'Sucursal eliminada correctamente.'));

    $this->actingAs($admin)
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('flash.toast', null));
});

it('una recarga parcial de filtros siempre incluye el flash, evitando un toast obsoleto en el cliente', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
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

    $this->actingAs($admin)
        ->get('/sucursales?estado=inactivas')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 1)->where('sucursales.data.0.nombre', 'Apagada'));

    $this->actingAs($admin)
        ->get('/sucursales?estado=activas')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 1)->where('sucursales.data.0.nombre', 'Viva'));
});

it('un rol sin permiso de eliminar sucursales nunca ve las eliminadas, ni forzando el filtro por URL', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Viva', 'activa' => true]);
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Apagada', 'activa' => false]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    // Sin filtro: sólo ve la activa (no "todas" como vería un admin).
    $this->actingAs($supervisor)
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page
            ->where('sucursales.total', 1)
            ->where('sucursales.data.0.nombre', 'Viva')
            ->where('permisos.verEliminadas', false),
        );

    // Forzando ?estado=inactivas por URL: el backend IGNORA el filtro (no lo
    // rechaza con error) y sigue mostrando sólo lo que el usuario puede ver
    // normalmente — nunca la eliminada.
    $this->actingAs($supervisor)
        ->get('/sucursales?estado=inactivas')
        ->assertInertia(fn ($page) => $page
            ->where('sucursales.total', 1)
            ->where('sucursales.data.0.nombre', 'Viva'),
        );
});

it('un administrador (con permiso de eliminar) sí ve la opción de eliminadas', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['activa' => false]);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/sucursales')
        ->assertInertia(fn ($page) => $page->where('permisos.verEliminadas', true));
});

it('busca sucursales por nombre y ordena de forma descendente', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Terminal Norte']);
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Terminal Sur']);
    Sucursal::factory()->for($empresa)->create(['nombre' => 'Bodega Central']);

    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/sucursales?buscar=Terminal')
        ->assertInertia(fn ($page) => $page->where('sucursales.total', 2));

    $this->actingAs($admin)
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
        ->from('/sucursales')
        ->post('/sucursales', ['nombre' => 'Otra', 'codigo' => 'MATRIZ', 'empresa_id' => $empresa->id])
        ->assertRedirect('/sucursales')
        ->assertSessionHasErrors('codigo');
});

it('permite el mismo código de sucursal en empresas distintas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    Sucursal::factory()->for($empresaA)->create(['codigo' => 'MATRIZ']);

    $this->actingAs(usuarioCon(RolSistema::Administrador->value))
        ->post('/sucursales', ['nombre' => 'Matriz B', 'codigo' => 'MATRIZ', 'empresa_id' => $empresaB->id])
        ->assertSessionHasNoErrors();
});

it('valida el teléfono a 10 dígitos con mensaje en español y no genera un 500 con tipos raros', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)->from('/sucursales')
        ->post('/sucursales', ['nombre' => 'Con Teléfono', 'telefono' => '123', 'empresa_id' => $empresa->id])
        ->assertRedirect('/sucursales')
        ->assertSessionHasErrors(['telefono' => 'El teléfono debe contener 10 dígitos.']);

    $this->actingAs($admin)->from('/sucursales')
        ->post('/sucursales', ['nombre' => ['array', 'no', 'string'], 'empresa_id' => $empresa->id])
        ->assertRedirect('/sucursales')
        ->assertSessionHasErrors('nombre');
});

it('la búsqueda de sucursales sin empresa_id busca en todas las autorizadas; con empresa_id acota y respeta el alcance', function () {
    $miEmpresa = Empresa::factory()->create();
    $ajena = Empresa::factory()->create();
    $miSucursal = Sucursal::factory()->for($miEmpresa)->create(['nombre' => 'Sucursal Visible']);
    Sucursal::factory()->for($ajena)->create(['nombre' => 'Sucursal Ajena']);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$miEmpresa]);

    // Sin empresa_id: universo autorizado completo (aquí, sólo $miEmpresa) —
    // nunca vacío sólo porque no se eligió una empresa concreta (p. ej. el
    // Dashboard en modo "todas las empresas").
    $this->actingAs($supervisor)
        ->get('/sucursales/buscar')
        ->assertJsonFragment(['nombre' => 'Sucursal Visible'])
        ->assertJsonMissing(['nombre' => 'Sucursal Ajena']);

    $this->actingAs($supervisor)
        ->get("/sucursales/buscar?empresa_id={$miEmpresa->id}")
        ->assertJsonFragment(['nombre' => 'Sucursal Visible'])
        ->assertJsonMissing(['nombre' => 'Sucursal Ajena']);

    $this->actingAs($supervisor)
        ->get("/sucursales/buscar?empresa_id={$ajena->id}")
        ->assertJson(['sucursales' => []]);
});
