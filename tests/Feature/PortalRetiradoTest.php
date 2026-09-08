<?php

use App\Enums\RolSistema;
use Illuminate\Support\Facades\Route;

/**
 * Sección 14/15 del QA: el módulo "Mis entregas" (portal del colaborador) se
 * retiró por completo — ruta, controlador, página y enlace de navegación —
 * sin tocar el flujo de doble firma de Entregas/Devoluciones.
 */
it('la ruta del portal "mis entregas" ya no existe', function () {
    sembrarRolesPermisos();

    expect(Route::has('portal.mis-entregas'))->toBeFalse();

    $usuario = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($usuario)->get('/portal/mis-entregas')->assertNotFound();
});

it('ya no queda ningún artefacto del portal en el código', function () {
    expect(file_exists(app_path('Http/Controllers/Portal/PortalController.php')))->toBeFalse()
        ->and(file_exists(resource_path('js/pages/Portal/MisEntregas.vue')))->toBeFalse()
        ->and(is_dir(resource_path('js/actions/App/Http/Controllers/Portal')))->toBeFalse()
        ->and(is_dir(resource_path('js/routes/portal')))->toBeFalse();

    expect(str_contains(file_get_contents(resource_path('js/components/AppSidebar.vue')), 'portal/mis-entregas'))->toBeFalse();
});

it('el flujo de firma de entregas sigue registrado y accesible', function () {
    // Las rutas críticas de la doble firma no dependían del portal.
    expect(Route::has('acuses.firmar'))->toBeTrue()
        ->and(Route::has('acuses.confirmar'))->toBeTrue()
        ->and(Route::has('devoluciones.firmar'))->toBeTrue()
        ->and(Route::has('devoluciones.confirmar'))->toBeTrue();
});
