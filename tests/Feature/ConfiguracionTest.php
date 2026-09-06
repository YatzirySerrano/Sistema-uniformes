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

/**
 * Regla del proyecto (post-Fase 10): Superadmin y Administrador tienen el
 * MISMO acceso funcional a los módulos administrativos, Configuración
 * incluida. `Permisos::porRol()` ya le da a Administrador `self::todos()`
 * (documentado ahí mismo); el backend (`ConfiguracionSistemaPolicy`) y el
 * sidebar (`AppSidebar.vue`, `puede(['configuracion.ver', ...])`) ya
 * autorizan por permiso, nunca por rol hardcodeado. Si esto falla en un
 * entorno real (dev/producción) sin fallar aquí, la causa NO es de código:
 * es que `php artisan db:seed --class=RolesPermisosSeeder` no se ha vuelto a
 * correr desde que se agregó el grupo de permisos `configuracion` — el
 * seeder es estructural e idempotente (`Permission::findOrCreate` +
 * `Role::syncPermissions()`), así que resincroniza sin tocar roles
 * personalizados ni borrar nada.
 */
it('un administrador tiene el mismo acceso a Configuración que un superadministrador', function () {
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->get('/configuracion')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Configuracion/Index')
            ->where('puedeEditar', true)
        );

    expect($admin->getAllPermissions()->pluck('name')->all())
        ->toContain('configuracion.ver', 'configuracion.administrar');
});

it('el permiso de Configuración llega al frontend (auth.user.permisos) para Superadmin y Administrador, y no para un rol sin acceso', function () {
    $superadmin = usuarioCon(RolSistema::Superadministrador->value);
    $admin = usuarioCon(RolSistema::Administrador->value);
    $encargado = usuarioCon(RolSistema::Encargado->value);

    $this->actingAs($superadmin)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.permisos', fn ($p) => collect($p)->contains('configuracion.ver')));

    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.permisos', fn ($p) => collect($p)->contains('configuracion.ver')));

    $this->actingAs($encargado)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.permisos', fn ($p) => collect($p)->intersect(['configuracion.ver', 'configuracion.administrar'])->isEmpty()));
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
