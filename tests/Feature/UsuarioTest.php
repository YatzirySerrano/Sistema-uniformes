<?php

use App\Enums\RolSistema;
use App\Models\Empresa;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('sólo quien puede desactivar usuarios ve los eliminados, ni forzando el filtro por URL', function () {
    $vivo = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $vivo->update(['name' => 'Vivo']);
    $apagado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $apagado->update(['name' => 'Apagado', 'activo' => false]);

    // Rol personalizado con permiso para ver el listado pero no para
    // eliminar/restaurar usuarios (los roles restringidos base ni siquiera
    // tienen "usuarios.ver").
    Role::findOrCreate('visor_de_usuarios', 'web')->syncPermissions(['usuarios.ver']);
    $visor = User::factory()->create();
    $visor->assignRole('visor_de_usuarios');
    $visor->empresas()->sync([$this->empresa->id]);

    $this->actingAs($this->admin)
        ->get('/usuarios')
        ->assertInertia(fn ($page) => $page
            ->where('puedeVerEliminados', true)
        );

    $this->actingAs($visor)
        ->get('/usuarios')
        ->assertInertia(fn ($page) => $page
            ->where('puedeVerEliminados', false)
        );

    $respuesta = $this->actingAs($visor)->get('/usuarios');
    $nombres = collect($respuesta->viewData('page')['props']['usuarios']['data'])->pluck('name');
    expect($nombres)->toContain('Vivo')->not->toContain('Apagado');
});

it('un usuario no puede desactivarse a sí mismo ni a un superadministrador', function () {
    $superadmin = usuarioCon(RolSistema::Superadministrador->value, [$this->empresa]);

    $this->actingAs($this->admin)
        ->post("/usuarios/{$this->admin->id}/estado")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post("/usuarios/{$superadmin->id}/estado")
        ->assertForbidden();
});

it('cambia el estado de un usuario y renombra los mensajes a eliminar/restaurar', function () {
    $objetivo = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($this->admin)
        ->post("/usuarios/{$objetivo->id}/estado")
        ->assertRedirect()
        ->assertSessionHas('toast.message', 'Usuario eliminado.');

    expect($objetivo->fresh()->activo)->toBeFalse();

    $this->actingAs($this->admin)
        ->post("/usuarios/{$objetivo->id}/estado")
        ->assertRedirect()
        ->assertSessionHas('toast.message', 'Usuario restaurado.');

    expect($objetivo->fresh()->activo)->toBeTrue();
});

it('marca puedeCambiarEstado en falso para el propio usuario y para un superadministrador', function () {
    $superadmin = usuarioCon(RolSistema::Superadministrador->value, [$this->empresa]);
    usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $respuesta = $this->actingAs($this->admin)->get('/usuarios');
    $filas = collect($respuesta->viewData('page')['props']['usuarios']['data'])->keyBy('id');

    expect($filas[$this->admin->id]['puedeCambiarEstado'])->toBeFalse()
        ->and($filas[$superadmin->id]['puedeCambiarEstado'])->toBeFalse();
});
