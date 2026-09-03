<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\User;
use App\Soporte\ContextoEmpresa;
use App\Soporte\Permisos;
use Database\Seeders\RolesPermisosSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    sembrarRolesPermisos();
});

/*
|--------------------------------------------------------------------------
| Herencia de permisos por el rol Administrador (regla de negocio)
|--------------------------------------------------------------------------
| Un usuario nuevo al que sólo se le asigna el rol `administrador` debe
| heredar automáticamente TODO el catálogo de permisos de negocio, sin
| permisos directos. Así funcionará igual en producción.
*/

it('un usuario nuevo con sólo el rol administrador hereda todos los permisos del catálogo sin permisos directos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole(RolSistema::Administrador->value);

    // No se le asigna NINGÚN permiso directo.
    expect($usuario->getDirectPermissions())->toHaveCount(0);

    // Hereda del rol el catálogo completo.
    $heredados = $usuario->getPermissionsViaRoles()->pluck('name')->sort()->values()->all();
    expect($heredados)->toEqual(collect(Permisos::todos())->sort()->values()->all());

    // En particular, los módulos nuevos.
    foreach ([
        'almacenes.ver', 'almacenes.crear', 'almacenes.editar', 'almacenes.administrar',
        'areas.ver', 'areas.crear', 'areas.editar', 'areas.desactivar',
        'activos.ver', 'activos.crear', 'activos.editar', 'activos.administrar',
        'tallas.administrar',
        'tipos-activo.administrar', 'categorias-activo.administrar',
        'inventario.entrada', 'inventario.ajustar', 'inventario.minimos', 'inventario.migrar',
    ] as $permiso) {
        expect($usuario->can($permiso))->toBeTrue("El administrador debería poder «{$permiso}»");
    }
});

it('un supervisor no gana los permisos administrativos de catálogos ni la migración de inventario', function () {
    $empresa = Empresa::factory()->create();
    $usuario = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    expect($usuario->getDirectPermissions())->toHaveCount(0);

    foreach ([
        'tipos-activo.administrar', 'categorias-activo.administrar',
        'inventario.migrar', 'inventario.ajustar', 'activos.administrar',
    ] as $permiso) {
        expect($usuario->can($permiso))->toBeFalse("El supervisor NO debería poder «{$permiso}»");
    }
});

it('el rol administrador y el rol superadministrador tienen exactamente el catálogo completo', function () {
    foreach (['administrador', 'superadministrador'] as $rol) {
        $permisos = Role::findByName($rol, 'web')->permissions->pluck('name')->sort()->values()->all();
        expect($permisos)->toEqual(collect(Permisos::todos())->sort()->values()->all());
    }
});

it('el RolesPermisosSeeder es idempotente y seguro de reejecutar', function () {
    $antes = Role::findByName('administrador', 'web')->permissions->pluck('name')->sort()->values()->all();

    (new RolesPermisosSeeder)->run();
    (new RolesPermisosSeeder)->run();

    $despues = Role::findByName('administrador', 'web')->fresh()->permissions->pluck('name')->sort()->values()->all();

    expect($despues)->toEqual($antes);
    expect($despues)->toEqual(collect(Permisos::todos())->sort()->values()->all());
});

/*
|--------------------------------------------------------------------------
| Administrador opera los tres módulos completos (backend autoriza)
|--------------------------------------------------------------------------
*/

it('un administrador puede ver, crear, editar, cambiar estado y gestionar sucursales de un almacén', function () {
    $empresa = Empresa::factory()->create();
    $sucursales = Sucursal::factory()->count(2)->for($empresa)->create();
    $admin = usuarioCon(RolSistema::Administrador->value);
    $sesion = [ContextoEmpresa::SESSION_KEY => $empresa->id];

    $this->actingAs($admin)->withSession($sesion)->get('/almacenes')->assertOk();

    $this->actingAs($admin)->withSession($sesion)
        ->post('/almacenes', ['nombre' => 'Almacén Admin', 'sucursales' => [$sucursales->first()->id]])
        ->assertRedirect()->assertSessionHasNoErrors();
    $almacen = Almacen::query()->where('nombre', 'Almacén Admin')->firstOrFail();

    $this->actingAs($admin)->withSession($sesion)->get("/almacenes/{$almacen->id}")->assertOk();

    $this->actingAs($admin)->withSession($sesion)
        ->put("/almacenes/{$almacen->id}", ['nombre' => 'Almacén Admin 2'])
        ->assertSessionHasNoErrors();
    expect($almacen->fresh()->nombre)->toBe('Almacén Admin 2');

    $this->actingAs($admin)->withSession($sesion)
        ->put("/almacenes/{$almacen->id}/sucursales", ['sucursales' => $sucursales->pluck('id')->all()])
        ->assertSessionHasNoErrors();
    expect($almacen->sucursales()->count())->toBe(2);

    $this->actingAs($admin)->withSession($sesion)
        ->post("/almacenes/{$almacen->id}/estado")->assertSessionHas('toast');
    expect($almacen->fresh()->activo)->toBeFalse();
});

it('un administrador puede ver, crear, editar y cambiar estado de un área', function () {
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value);
    $sesion = [ContextoEmpresa::SESSION_KEY => $empresa->id];

    $this->actingAs($admin)->withSession($sesion)->get('/areas')->assertOk();

    $this->actingAs($admin)->withSession($sesion)
        ->post('/areas', ['nombre' => 'Seguridad Patrimonial'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $area = Area::query()->where('nombre', 'Seguridad Patrimonial')->firstOrFail();

    $this->actingAs($admin)->withSession($sesion)
        ->put("/areas/{$area->id}", ['nombre' => 'Seguridad'])
        ->assertSessionHasNoErrors();
    expect($area->fresh()->nombre)->toBe('Seguridad');

    $this->actingAs($admin)->withSession($sesion)
        ->post("/areas/{$area->id}/estado")->assertSessionHas('toast');
    expect($area->fresh()->activa)->toBeFalse();
});

it('un administrador puede ver, crear, editar, cambiar estado de un activo y administrar tallas', function () {
    $empresa = Empresa::factory()->create();
    $talla = Talla::factory()->for($empresa)->create(['valor' => 'M']);
    $admin = usuarioCon(RolSistema::Administrador->value);
    $sesion = [ContextoEmpresa::SESSION_KEY => $empresa->id];

    $this->actingAs($admin)->withSession($sesion)->get('/activos')->assertOk();

    $this->actingAs($admin)->withSession($sesion)
        ->post('/activos', ['nombre' => 'Chaleco', 'tipo_control' => 'cantidad', 'tallas' => [$talla->id]])
        ->assertRedirect('/activos')->assertSessionHasNoErrors();
    $activo = Activo::query()->where('nombre', 'Chaleco')->firstOrFail();

    $this->actingAs($admin)->withSession($sesion)
        ->post("/activos/{$activo->id}", ['_method' => 'POST', 'nombre' => 'Chaleco Reflejante', 'tipo_control' => 'cantidad'])
        ->assertSessionHasNoErrors();
    expect($activo->fresh()->nombre)->toBe('Chaleco Reflejante');

    $this->actingAs($admin)->withSession($sesion)
        ->post("/activos/{$activo->id}/estado")->assertSessionHas('toast');
    expect($activo->fresh()->activo)->toBeFalse();

    // Administración de variantes / tallas (permiso tallas.administrar).
    $this->actingAs($admin)->withSession($sesion)
        ->post('/tallas', ['valor' => 'XL', 'orden' => 9])
        ->assertSessionHasNoErrors();
    expect(Talla::query()->where('empresa_id', $empresa->id)->where('valor', 'XL')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Tests negativos: no se sacrifica seguridad para arreglar Administrador
|--------------------------------------------------------------------------
*/

it('un supervisor sin permiso de crear almacén recibe 403', function () {
    $empresa = Empresa::factory()->create();
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresa]);

    expect($supervisor->can('almacenes.crear'))->toBeFalse();

    $this->actingAs($supervisor)->withSession([ContextoEmpresa::SESSION_KEY => $empresa->id])
        ->post('/almacenes', ['nombre' => 'Intento supervisor'])
        ->assertForbidden();

    expect(Almacen::query()->where('nombre', 'Intento supervisor')->exists())->toBeFalse();
});

it('un encargado sin permiso de editar activo ni crear área recibe 403', function () {
    $empresa = Empresa::factory()->create();
    $activo = Activo::factory()->for($empresa)->create();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$empresa]);
    $sesion = [ContextoEmpresa::SESSION_KEY => $empresa->id];

    expect($encargado->can('activos.editar'))->toBeFalse();
    expect($encargado->can('areas.crear'))->toBeFalse();

    $this->actingAs($encargado)->withSession($sesion)
        ->post("/activos/{$activo->id}", ['_method' => 'POST', 'nombre' => 'Editado', 'tipo_control' => 'cantidad'])
        ->assertForbidden();

    $this->actingAs($encargado)->withSession($sesion)
        ->post('/areas', ['nombre' => 'Intento encargado'])
        ->assertForbidden();
});

it('un usuario restringido de otra empresa no puede acceder a un almacén ajeno (IDOR)', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $almacenB = Almacen::factory()->for($empresaB)->create();

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaA]);

    $respuesta = $this->actingAs($supervisor)
        ->withSession([ContextoEmpresa::SESSION_KEY => $empresaA->id])
        ->get("/almacenes/{$almacenB->id}");

    expect($respuesta->status())->toBeIn([403, 404]);
});

it('los roles restringidos NO ganaron permisos administrativos nuevos', function () {
    $supervisor = Role::findByName('supervisor', 'web')->permissions->pluck('name');
    $encargado = Role::findByName('encargado', 'web')->permissions->pluck('name');

    // Ven pero no administran.
    expect($supervisor->contains('almacenes.crear'))->toBeFalse();
    expect($supervisor->contains('almacenes.editar'))->toBeFalse();
    expect($supervisor->contains('almacenes.administrar'))->toBeFalse();
    expect($supervisor->contains('activos.crear'))->toBeFalse();
    expect($supervisor->contains('activos.administrar'))->toBeFalse();
    expect($supervisor->contains('areas.desactivar'))->toBeFalse();

    expect($encargado->contains('almacenes.crear'))->toBeFalse();
    expect($encargado->contains('areas.crear'))->toBeFalse();
    expect($encargado->contains('activos.editar'))->toBeFalse();
    expect($encargado->contains('tallas.administrar'))->toBeFalse();
});
