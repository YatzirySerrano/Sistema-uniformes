<?php

use App\Enums\RolSistema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    sembrarRolesPermisos();
});

it('permite el acceso a Superadministrador y Administrador', function (string $rol): void {
    $usuario = usuarioCon($rol);

    $this->actingAs($usuario)->get('/datos/importar-maestro')->assertOk();
})->with([RolSistema::Superadministrador->value, RolSistema::Administrador->value]);

it('rechaza a Supervisor, Encargado y Colaborador con 403 en las 4 rutas', function (string $rol): void {
    $usuario = usuarioCon($rol);
    $archivo = construirWorkbookMaestro([]);

    $this->actingAs($usuario)->get('/datos/importar-maestro')->assertForbidden();
    $this->actingAs($usuario)->post('/datos/importar-maestro/prevalidar', ['archivo' => $archivo])->assertForbidden();
    $this->actingAs($usuario)->post('/datos/importar-maestro/confirmar', ['token' => 'x'])->assertForbidden();
    $this->actingAs($usuario)->postJson('/datos/importar-maestro/errores', ['errores' => [
        ['hoja' => 'EMPRESAS', 'fila' => 2, 'campo' => 'rfc', 'error' => 'x'],
    ]])->assertForbidden();
})->with([RolSistema::Supervisor->value, RolSistema::Encargado->value, RolSistema::Colaborador->value]);

it('rechaza el acceso sin autenticar', function (): void {
    $this->get('/datos/importar-maestro')->assertRedirect('/login');
});
