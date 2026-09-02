<?php

use App\Models\User;
use Laravel\Fortify\Features;

it('bloquea el acceso de un usuario desactivado y cierra su sesión', function () {
    sembrarRolesPermisos();

    $usuario = User::factory()->create(['activo' => false]);

    $this->actingAs($usuario)
        ->get('/dashboard')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('permite el acceso al panel de un usuario activo y verificado', function () {
    sembrarRolesPermisos();

    $usuario = User::factory()->create(['activo' => true]);

    $this->actingAs($usuario)->get('/dashboard')->assertOk();
});

it('exige autenticación en las rutas del sistema', function (string $ruta) {
    $this->get($ruta)->assertRedirect('/login');
})->with([
    '/dashboard',
    '/colaboradores',
    '/activos',
    '/inventario',
    '/entregas',
    '/devoluciones',
    '/reportes',
    '/usuarios',
    '/auditoria',
]);

it('no expone una ruta pública de registro', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

it('rechaza el inicio de sesión de un usuario desactivado con un mensaje en español', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $usuario = User::factory()->create([
        'email' => 'inactivo@example.test',
        'password' => Hash::make('secret-Password1'),
        'activo' => false,
    ]);

    $respuesta = $this->from('/login')->post('/login', [
        'email' => 'inactivo@example.test',
        'password' => 'secret-Password1',
    ]);

    $respuesta->assertRedirect('/login');
    $respuesta->assertSessionHasErrors('email');
    $this->assertGuest();
});
