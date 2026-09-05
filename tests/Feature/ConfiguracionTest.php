<?php

use App\Enums\RolSistema;
use App\Models\BitacoraAuditoria;
use App\Models\ConfiguracionSistema;

beforeEach(function () {
    sembrarRolesPermisos();
});

it('un superadministrador puede ver la configuración global', function () {
    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->get('/configuracion')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Configuracion/Index'));
});

it('un usuario sin permisos de configuración NO puede ver ni editar la configuración global', function () {
    $usuario = usuarioCon(RolSistema::Encargado->value);

    $this->actingAs($usuario)->get('/configuracion')->assertForbidden();

    $this->actingAs($usuario)->post('/configuracion', [
        'color_principal' => '#111111',
        'color_hover_principal' => '#222222',
        'color_texto_boton_principal' => '#ffffff',
        'fondo_general' => '#ffffff',
        'fondo_tarjetas' => '#ffffff',
        'fondo_sidebar' => '#fafafa',
    ])->assertForbidden();
});

it('guarda la personalización con colores HEX válidos y queda GLOBAL (sin empresa_id)', function () {
    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->post('/configuracion', [
            'color_principal' => '#123456',
            'color_hover_principal' => '#0f2a44',
            'color_texto_boton_principal' => '#ffffff',
            'fondo_general' => '#f5f5f5',
            'fondo_tarjetas' => '#ffffff',
            'fondo_sidebar' => '#eeeeee',
        ])
        ->assertRedirect();

    $configuracion = ConfiguracionSistema::actual();

    expect($configuracion->color_principal)->toBe('#123456')
        ->and($configuracion->fondo_general)->toBe('#f5f5f5')
        ->and($configuracion->getAttributes())->not->toHaveKey('empresa_id');
});

it('rechaza un color HEX inválido', function () {
    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->post('/configuracion', [
            'color_principal' => 'no-es-un-color',
            'color_hover_principal' => '#222222',
            'color_texto_boton_principal' => '#ffffff',
            'fondo_general' => '#ffffff',
            'fondo_tarjetas' => '#ffffff',
            'fondo_sidebar' => '#fafafa',
        ])
        ->assertSessionHasErrors('color_principal');
});

it('rechaza texto de botón sin contraste suficiente contra el color principal', function () {
    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->post('/configuracion', [
            'color_principal' => '#ffffff',
            'color_hover_principal' => '#eeeeee',
            'color_texto_boton_principal' => '#fefefe',
            'fondo_general' => '#ffffff',
            'fondo_tarjetas' => '#ffffff',
            'fondo_sidebar' => '#fafafa',
        ])
        ->assertSessionHasErrors('color_texto_boton_principal');
});

it('la configuración es una fila única que persiste entre lecturas', function () {
    $primera = ConfiguracionSistema::actual();
    $segunda = ConfiguracionSistema::actual();

    expect($segunda->id)->toBe($primera->id)
        ->and(ConfiguracionSistema::query()->count())->toBe(1);
});

it('registra en la bitácora de auditoría al actualizar la configuración', function () {
    $this->actingAs(usuarioCon(RolSistema::Superadministrador->value))
        ->post('/configuracion', [
            'color_principal' => '#654321',
            'color_hover_principal' => '#111111',
            'color_texto_boton_principal' => '#ffffff',
            'fondo_general' => '#ffffff',
            'fondo_tarjetas' => '#ffffff',
            'fondo_sidebar' => '#fafafa',
        ]);

    expect(BitacoraAuditoria::query()
        ->where('modulo', 'configuracion')
        ->where('accion', 'actualizar')
        ->exists())->toBeTrue();
});
