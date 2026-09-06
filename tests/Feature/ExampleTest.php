<?php

use App\Models\User;

test('la raíz redirige a login cuando no hay sesión', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('la raíz redirige al panel cuando hay sesión activa', function () {
    $usuario = User::factory()->create();

    $response = $this->actingAs($usuario)->get(route('home'));

    $response->assertRedirect(route('dashboard', absolute: false));
});
