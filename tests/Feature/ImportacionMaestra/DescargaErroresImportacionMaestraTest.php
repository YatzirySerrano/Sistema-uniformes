<?php

use App\Enums\RolSistema;
use App\Exports\ErroresImportacionExport;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function (): void {
    sembrarRolesPermisos();
    $this->superadmin = usuarioCon(RolSistema::Superadministrador->value);
});

it('descarga los errores de una prevalidación como xlsx con las 5 columnas esperadas', function (): void {
    Excel::fake();

    $errores = [
        ['hoja' => 'EMPRESAS', 'fila' => 3, 'campo' => 'rfc', 'valor' => 'MAL', 'error' => 'El RFC no tiene un formato válido.'],
        ['hoja' => 'COLABORADORES', 'fila' => 14, 'campo' => 'curp', 'valor' => null, 'error' => 'La CURP es obligatoria.'],
    ];

    $this->actingAs($this->superadmin)
        ->postJson('/datos/importar-maestro/errores', ['errores' => $errores])
        ->assertOk();

    Excel::assertDownloaded('errores-importacion-maestra.xlsx', function (ErroresImportacionExport $export): bool {
        $filas = $export->array();

        expect($filas)->toHaveCount(2);
        expect($export->headings())->toBe(['Hoja', 'Fila', 'Campo', 'Valor', 'Error']);
        expect($filas[0])->toBe(['EMPRESAS', 3, 'rfc', 'MAL', 'El RFC no tiene un formato válido.']);
        expect($filas[1])->toBe(['COLABORADORES', 14, 'curp', '', 'La CURP es obligatoria.']);

        return true;
    });
});

it('exige al menos un error para poder descargar', function (): void {
    $this->actingAs($this->superadmin)
        ->postJson('/datos/importar-maestro/errores', ['errores' => []])
        ->assertUnprocessable();
});

it('rechaza la descarga sin el permiso datos.importar_maestro', function (): void {
    $usuario = usuarioCon(RolSistema::Supervisor->value);

    $this->actingAs($usuario)
        ->postJson('/datos/importar-maestro/errores', ['errores' => [
            ['hoja' => 'EMPRESAS', 'fila' => 2, 'campo' => 'rfc', 'error' => 'x'],
        ]])
        ->assertForbidden();
});
