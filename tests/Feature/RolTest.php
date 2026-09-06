<?php

use App\Enums\RolSistema;
use App\Soporte\Permisos;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un rol base del sistema no puede eliminarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rolBase = Role::where('name', RolSistema::Encargado->value)->firstOrFail();

    $this->actingAs($admin)
        ->delete("/roles/{$rolBase->id}")
        ->assertForbidden();

    expect(Role::query()->find($rolBase->id))->not->toBeNull();
});

it('un rol con usuarios asignados no puede eliminarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rol = Role::create(['name' => 'rol_con_usuarios', 'guard_name' => 'web']);
    usuarioCon('rol_con_usuarios');

    $this->actingAs($admin)
        ->delete("/roles/{$rol->id}")
        ->assertStatus(422);

    expect(Role::query()->find($rol->id))->not->toBeNull();
});

it('un rol personalizado sin usuarios sí puede eliminarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rol = Role::create(['name' => 'rol_temporal', 'guard_name' => 'web']);

    $this->actingAs($admin)
        ->delete("/roles/{$rol->id}")
        ->assertRedirect();

    expect(Role::query()->find($rol->id))->toBeNull();
});

it('el rol Superadministrador no puede modificarse', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $superadmin = Role::where('name', RolSistema::Superadministrador->value)->firstOrFail();

    $this->actingAs($admin)
        ->put("/roles/{$superadmin->id}", [
            'name' => RolSistema::Superadministrador->value,
            'permisos' => [],
        ])
        ->assertForbidden();
});

it('un rol base no puede renombrarse pero sí puede cambiar sus permisos', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);
    $rolBase = Role::where('name', RolSistema::Encargado->value)->firstOrFail();

    $this->actingAs($admin)
        ->put("/roles/{$rolBase->id}", [
            'name' => 'nombre_nuevo',
            'permisos' => ['activos.ver'],
        ])
        ->assertRedirect();

    $rolBase->refresh();

    expect($rolBase->name)->toBe(RolSistema::Encargado->value)
        ->and($rolBase->permissions->pluck('name')->all())->toBe(['activos.ver']);
});

it('crea un rol personalizado con los permisos seleccionados', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/roles', [
            'name' => 'auxiliar_reportes',
            'permisos' => ['reportes.ver', 'auditoria.ver'],
        ])
        ->assertRedirect();

    $rol = Role::where('name', 'auxiliar_reportes')->firstOrFail();
    expect($rol->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['auditoria.ver', 'reportes.ver']);
});

it('rechaza permisos que no existen en el catálogo', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->post('/roles', [
            'name' => 'rol_invalido',
            'permisos' => ['no.existe'],
        ])
        ->assertSessionHasErrors('permisos.0');

    expect(Role::where('name', 'rol_invalido')->exists())->toBeFalse();
});

it('un usuario sin permiso roles.ver no puede acceder al listado', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($sinPermiso)
        ->get('/roles')
        ->assertForbidden();
});

it('el listado de roles incluye los grupos de permisos del catálogo', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/roles')
        ->assertInertia(fn ($page) => $page
            ->component('Roles/Index')
            ->where('gruposPermisos', Permisos::GRUPOS)
        );
});
