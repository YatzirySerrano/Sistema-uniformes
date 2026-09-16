<?php

use App\Enums\RolSistema;
use App\Models\Empresa;

/**
 * Este sistema es una aplicación privada autenticada, nunca una página de
 * marketing: ninguna respuesta debe ser indexable. Cubre las tres capas
 * (meta tag en el shell Blade, header HTTP en todo el grupo `web`,
 * `robots.txt` estático) tanto en una ruta pública (login) como en una
 * autenticada (dashboard) — el `<meta>` vive en `app.blade.php`, fuera de
 * `<x-inertia::head>`, así que ningún `<Head>` de página lo puede pisar.
 */
it('la página de login no es indexable: meta robots + header X-Robots-Tag', function () {
    $respuesta = $this->get('/login');

    $respuesta->assertOk();
    $respuesta->assertSee('name="robots" content="noindex, nofollow, noarchive"', false);
    $respuesta->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('una página autenticada tampoco es indexable', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/dashboard');

    $respuesta->assertOk();
    $respuesta->assertSee('name="robots" content="noindex, nofollow, noarchive"', false);
    $respuesta->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('una descarga de reporte (no HTML) también lleva X-Robots-Tag', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/empresas/exportar?formato=xlsx')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('robots.txt bloquea el rastreo completo de la aplicación', function () {
    $contenido = file_get_contents(public_path('robots.txt'));

    expect(trim($contenido))->toBe("User-agent: *\nDisallow: /");
});

it('no existe un sitemap de páginas privadas', function () {
    expect(file_exists(public_path('sitemap.xml')))->toBeFalse();
});
