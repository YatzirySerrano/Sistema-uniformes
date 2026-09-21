<?php

use App\Enums\RolSistema;
use App\Exports\PlantillaColaboradoresExport;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\SecuenciaCodigo;
use App\Servicios\ServicioImportacionColaboradores;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reingeniería del importador de colaboradores: `numero_empleado` ya no se
 * captura en el Excel (lo genera `App\Soporte\GeneradorNumeroEmpleado`, misma
 * fuente que el alta manual — ver [[NumeroEmpleadoTest]] y
 * [[ColaboradorCurpTest]]), la CURP es obligatoria y reutiliza
 * `GuardarColaboradorRequest::REGEX_CURP`, y la importación es todo-o-nada
 * (mismo patrón dry-run/confirmación que `ServicioImportacionMaestra`).
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
});

/*
|--------------------------------------------------------------------------
| Plantilla
|--------------------------------------------------------------------------
*/

it('la plantilla ya no incluye numero_empleado y sí incluye curp', function (): void {
    Excel::fake();

    $this->actingAs($this->admin)
        ->get('/colaboradores/importar/plantilla?empresa_id='.$this->datos['empresaA']->id)
        ->assertOk();

    Excel::assertDownloaded('plantilla-colaboradores.xlsx', function (PlantillaColaboradoresExport $export): bool {
        $encabezados = $export->headings();

        expect($encabezados)->toBe(['nombre_completo', 'curp', 'puesto', 'area', 'correo', 'sucursal_codigo'])
            ->and($encabezados)->not->toContain('numero_empleado')
            ->and($encabezados)->toContain('curp');

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| Validación de columnas (encabezado)
|--------------------------------------------------------------------------
*/

it('bloquea el archivo si falta una columna obligatoria de estructura', function (): void {
    $encabezados = array_values(array_diff(encabezadosImportacionColaboradores(), ['area']));
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Juan Perez', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ], $encabezados);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['faltantes'])->toBe(['area'])
        ->and($analisis['total'])->toBe(0)
        ->and($analisis['listos'])->toBe(0);

    expect(Colaborador::query()->where('nombre_completo', 'Juan Perez')->exists())->toBeFalse();
});

it('reporta numero_empleado de una plantilla vieja como columna adicional y no lo usa', function (): void {
    $encabezadosViejos = array_merge(['numero_empleado'], encabezadosImportacionColaboradores());
    $archivo = construirExcelColaboradores([
        [
            'numero_empleado' => 'XX-9999',
            'nombre_completo' => 'Con Numero Viejo',
            'curp' => curpDeQaValida(),
            'sucursal_codigo' => $this->datos['sucursalA']->codigo,
        ],
    ], $encabezadosViejos);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['adicionales'])->toBe(['numero_empleado'])
        ->and($analisis['columnas']['faltantes'])->toBe([])
        ->and($analisis['listos'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Validación de filas: CURP, correo, sucursal
|--------------------------------------------------------------------------
*/

it('reporta la fila sin CURP como error', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sin Curp', 'curp' => null, 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe('curp')
        ->and($analisis['errores'][0]['error'])->toBe('La CURP es obligatoria.')
        ->and($analisis['listos'])->toBe(0);
});

it('reporta una CURP con formato inválido como error, indicando fila/campo/valor', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Curp Invalida', 'curp' => 'NOVALIDA', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1);
    expect($analisis['errores'][0])->toMatchArray([
        'fila' => 2,
        'campo' => 'curp',
        'valor' => 'NOVALIDA',
    ]);
});

it('detecta una CURP repetida dentro del mismo archivo', function (): void {
    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Repetido Uno', 'curp' => $curp, 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Repetido Dos', 'curp' => $curp, 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['duplicados'])->toHaveCount(1)
        ->and($analisis['duplicados'][0]['fila'])->toBe(3)
        ->and($analisis['duplicados'][0]['error'])->toContain('se repite en la fila 2')
        ->and($analisis['listos'])->toBe(1);
});

it('detecta una CURP ya existente en el sistema, incluyendo colaboradores soft-deleted', function (): void {
    $eliminado = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();
    $curpReservada = $eliminado->curp;
    $eliminado->delete();

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Intento Reusar', 'curp' => $curpReservada, 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['duplicados'])->toHaveCount(1)
        ->and($analisis['duplicados'][0]['error'])->toBe('La CURP ya existe en el sistema.');
});

it('reporta una sucursal inexistente como error', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sucursal Mala', 'curp' => curpDeQaValida(), 'sucursal_codigo' => 'SUC-9999'],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe('sucursal_codigo')
        ->and($analisis['errores'][0]['error'])->toBe('La sucursal SUC-9999 no existe en la empresa seleccionada.');
});

it('rechaza el código de una sucursal que pertenece a otra empresa', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sucursal Ajena', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalB']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe('sucursal_codigo');
});

it('reporta un correo con formato inválido como error', function (): void {
    $archivo = construirExcelColaboradores([
        [
            'nombre_completo' => 'Correo Malo', 'curp' => curpDeQaValida(),
            'correo' => 'no-es-un-correo', 'sucursal_codigo' => $this->datos['sucursalA']->codigo,
        ],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe('correo');
});

/*
|--------------------------------------------------------------------------
| Generación del número de empleado
|--------------------------------------------------------------------------
*/

it('confirmar genera el numero_empleado con GeneradorNumeroEmpleado, nunca con el del Excel', function (): void {
    $encabezadosViejos = array_merge(['numero_empleado'], encabezadosImportacionColaboradores());
    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        [
            'numero_empleado' => 'INVENTADO-1',
            'nombre_completo' => 'Juan Perez',
            'curp' => $curp,
            'sucursal_codigo' => $this->datos['sucursalA']->codigo,
        ],
    ], $encabezadosViejos);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/confirmar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'token' => $analisis['token'],
        ])
        ->assertRedirect();

    $colaborador = Colaborador::query()->where('curp', $curp)->firstOrFail();

    expect($colaborador->numero_empleado)
        ->not->toBe('INVENTADO-1')
        ->toMatch('/^JP\d{4,}$/');
});

it('dos colaboradores importados en el mismo archivo reciben numero_empleado distintos', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Ana Lopez', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Beto Gomez', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Ana Lopez', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    $numeros = Colaborador::query()->where('empresa_id', $this->datos['empresaA']->id)
        ->whereIn('nombre_completo', ['Ana Lopez', 'Beto Gomez'])->pluck('numero_empleado');

    expect($numeros)->toHaveCount(3)
        ->and($numeros->unique())->toHaveCount(3);
});

it('la secuencia de numero_empleado continúa correctamente respecto a altas manuales previas', function (): void {
    $this->actingAs($this->admin)->post('/colaboradores', [
        'empresa_id' => $this->datos['empresaA']->id,
        'nombre_completo' => 'Alta Manual Previa',
        'curp' => curpDeQaValida(),
        'sucursal_id' => $this->datos['sucursalA']->id,
    ])->assertSessionHasNoErrors();
    $numeroManual = Colaborador::query()->where('nombre_completo', 'Alta Manual Previa')->value('numero_empleado');

    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Alta Por Excel', 'curp' => $curp, 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    $numeroImportado = Colaborador::query()->where('curp', $curp)->value('numero_empleado');

    expect($numeroImportado)->not->toBe($numeroManual);
});

it('analizar (prevalidación) NO consume el consecutivo de numero_empleado', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Solo Analizado', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect(SecuenciaCodigo::query()->where('empresa_id', $this->datos['empresaA']->id)->where('ambito', 'colaborador')->exists())
        ->toBeFalse();
    expect(Colaborador::query()->where('nombre_completo', 'Solo Analizado')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Todo o nada
|--------------------------------------------------------------------------
*/

it('una importación con errores no crea ningún colaborador', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Valido', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Curp Mala', 'curp' => 'NOVALIDA', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->not->toBeEmpty();

    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/confirmar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'token' => $analisis['token'],
        ])
        ->assertStatus(422);

    expect(Colaborador::query()->where('nombre_completo', 'Valido')->exists())->toBeFalse();
});

it('confirmar con un archivo totalmente válido crea todos los colaboradores', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Uno Valido', 'curp' => curpDeQaValida(), 'puesto' => 'Operador', 'area' => 'Producción', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Dos Valido', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['listos'])->toBe(2);

    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/confirmar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'token' => $analisis['token'],
        ])
        ->assertRedirect('/colaboradores');

    expect(Colaborador::query()->where('nombre_completo', 'Uno Valido')->exists())->toBeTrue()
        ->and(Colaborador::query()->where('nombre_completo', 'Dos Valido')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Token / archivo temporal
|--------------------------------------------------------------------------
*/

it('el token de una empresa no permite importar en otra empresa', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'De Empresa A', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/confirmar', [
            'empresa_id' => $this->datos['empresaB']->id,
            'token' => $analisis['token'],
        ])
        ->assertStatus(422);
});

it('confirmar sin haber analizado antes (token inexistente) responde con un mensaje claro', function (): void {
    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/confirmar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'token' => 'token-que-no-existe.xlsx',
        ])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Formato de archivo
|--------------------------------------------------------------------------
*/

it('rechaza un archivo que no está entre los formatos aceptados', function (): void {
    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/analizar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'archivo' => UploadedFile::fake()->create('colaboradores.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('archivo');
});

it('rechaza un archivo renombrado a .xlsx que no tiene firma ZIP real', function (): void {
    $ruta = tempnam(sys_get_temp_dir(), 'falso_').'.xlsx';
    file_put_contents($ruta, 'esto no es un xlsx real');

    $archivo = new UploadedFile(
        $ruta,
        'colaboradores.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/analizar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'archivo' => $archivo,
        ])
        ->assertSessionHasErrors('archivo');
});

/*
|--------------------------------------------------------------------------
| Auditoría y permisos
|--------------------------------------------------------------------------
*/

it('la importación sigue registrando auditoría', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Auditado', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    $this->assertDatabaseHas('bitacora_auditoria', [
        'modulo' => 'colaboradores',
        'accion' => 'importar',
        'empresa_id' => $this->datos['empresaA']->id,
    ]);
});

it('sin permiso de importar colaboradores, el análisis se rechaza', function (): void {
    // Encargado tiene `colaboradores.ver` pero NO `colaboradores.importar`.
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sin Permiso', 'curp' => curpDeQaValida(), 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    $this->actingAs($encargado)
        ->post('/colaboradores/importar/analizar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'archivo' => $archivo,
        ])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Áreas / departamentos: reutilizar existentes, crear nuevas al confirmar
|--------------------------------------------------------------------------
*/

it('reutiliza el area_id de un área ya existente en la empresa', function (): void {
    $area = Area::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Sistemas']);

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Con Area Existente', 'curp' => curpDeQaValida(), 'area' => 'Sistemas', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['areas']['existentes'])->toBe(['Sistemas'])
        ->and($analisis['areas']['nuevas'])->toBe([]);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->where('nombre', 'Sistemas')->count())->toBe(1);
    $colaborador = Colaborador::query()->where('nombre_completo', 'Con Area Existente')->firstOrFail();
    expect($colaborador->area_id)->toBe($area->id)
        ->and($colaborador->area)->toBe('Sistemas');
});

it('crea el área nueva al confirmar si no existe todavía en la empresa', function (): void {
    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->where('nombre', 'Recursos Humanos')->exists())->toBeFalse();

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Con Area Nueva', 'curp' => curpDeQaValida(), 'area' => 'Recursos Humanos', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['areas']['nuevas'])->toBe(['Recursos Humanos'])
        ->and($analisis['areas']['existentes'])->toBe([]);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    $area = Area::query()->where('empresa_id', $this->datos['empresaA']->id)->where('nombre', 'Recursos Humanos')->first();
    expect($area)->not->toBeNull();
    $colaborador = Colaborador::query()->where('nombre_completo', 'Con Area Nueva')->firstOrFail();
    expect($colaborador->area_id)->toBe($area->id);
});

it('analizar (prevalidación) NO crea ningún área nueva', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Solo Analizado', 'curp' => curpDeQaValida(), 'area' => 'Compras', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);

    analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->where('nombre', 'Compras')->exists())->toBeFalse();
});

it('la misma área nueva repetida en el archivo (con variantes de mayúsculas) crea una sola fila compartida', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Uno', 'curp' => curpDeQaValida(), 'area' => 'Compras', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Dos', 'curp' => curpDeQaValida(), 'area' => 'Compras', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Tres', 'curp' => curpDeQaValida(), 'area' => 'compras', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Cuatro', 'curp' => curpDeQaValida(), 'area' => 'COMPRAS', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['areas']['nuevas'])->toBe(['Compras']);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->count())->toBe(1);
    $area = Area::query()->where('empresa_id', $this->datos['empresaA']->id)->firstOrFail();

    $areaIds = Colaborador::query()->whereIn('nombre_completo', ['Uno', 'Dos', 'Tres', 'Cuatro'])->pluck('area_id');
    expect($areaIds->unique())->toHaveCount(1)
        ->and($areaIds->first())->toBe($area->id);
});

it('normaliza mayúsculas, minúsculas y espacios extra al resolver un área ya existente', function (): void {
    $area = Area::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Recursos Humanos']);

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Variante Uno', 'curp' => curpDeQaValida(), 'area' => '  recursos   humanos  ', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Variante Dos', 'curp' => curpDeQaValida(), 'area' => 'RECURSOS HUMANOS', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['areas']['existentes'])->toBe(['Recursos Humanos'])
        ->and($analisis['areas']['nuevas'])->toBe([]);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->count())->toBe(1);

    $areaIds = Colaborador::query()->whereIn('nombre_completo', ['Variante Uno', 'Variante Dos'])->pluck('area_id');
    expect($areaIds->unique())->toHaveCount(1)
        ->and($areaIds->first())->toBe($area->id);
});

it('la misma área en otra empresa NO se reutiliza al importar en una empresa distinta', function (): void {
    Area::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Compras']);

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'De Empresa A', 'curp' => curpDeQaValida(), 'area' => 'Compras', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['areas']['nuevas'])->toBe(['Compras']);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    expect(Area::query()->where('nombre', 'Compras')->count())->toBe(2)
        ->and(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->where('nombre', 'Compras')->exists())->toBeTrue()
        ->and(Area::query()->where('empresa_id', $this->datos['empresaB']->id)->where('nombre', 'Compras')->exists())->toBeTrue();
});

it('no crea ningún área cuando la columna area viene vacía', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sin Area', 'curp' => curpDeQaValida(), 'area' => null, 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['areas']['nuevas'])->toBe([])
        ->and($analisis['areas']['existentes'])->toBe([]);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->count())->toBe(0);
    $colaborador = Colaborador::query()->where('nombre_completo', 'Sin Area')->firstOrFail();
    expect($colaborador->area_id)->toBeNull()
        ->and($colaborador->area)->toBeNull();
});

it('si la importación falla por otros errores, no deja ningún área huérfana creada', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Con Area Valida', 'curp' => curpDeQaValida(), 'area' => 'Logística Nueva', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
        ['nombre_completo' => 'Curp Mala', 'curp' => 'NOVALIDA', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['errores'])->not->toBeEmpty();

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertStatus(422);

    expect(Area::query()->where('empresa_id', $this->datos['empresaA']->id)->where('nombre', 'Logística Nueva')->exists())->toBeFalse();
});

it('el campo espejo area queda coherente con el nombre canónico del catálogo, no con el texto crudo del Excel', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Espejo Test', 'curp' => curpDeQaValida(), 'area' => '  ventas   internas  ', 'sucursal_codigo' => $this->datos['sucursalA']->codigo],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    $this->actingAs($this->admin)->post('/colaboradores/importar/confirmar', [
        'empresa_id' => $this->datos['empresaA']->id,
        'token' => $analisis['token'],
    ])->assertRedirect();

    $area = Area::query()->where('empresa_id', $this->datos['empresaA']->id)->firstOrFail();
    $colaborador = Colaborador::query()->where('nombre_completo', 'Espejo Test')->firstOrFail();

    expect($area->nombre)->toBe('ventas internas')
        ->and($colaborador->area)->toBe($area->nombre)
        ->and($colaborador->area_id)->toBe($area->id);
});

it('ante una carrera de concurrencia (índice único chocando), reutiliza el área en vez de duplicar o fallar', function (): void {
    $empresa = $this->datos['empresaA'];

    // Simula que otra importación concurrente de la MISMA empresa ya
    // confirmó "VENTAS" (con otra capitalización) justo antes de que esta
    // llegara a insertar su propia fila para el mismo nombre normalizado.
    DB::table('areas')->insert([
        'empresa_id' => $empresa->id,
        'nombre' => 'VENTAS',
        'nombre_normalizado' => 'ventas',
        'codigo' => 'ARE-9001',
        'activa' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $servicio = app(ServicioImportacionColaboradores::class);
    $metodo = new ReflectionMethod($servicio, 'crearAreaSegura');
    $metodo->setAccessible(true);

    $area = $metodo->invoke($servicio, $empresa, 'Ventas', 'ventas');

    expect($area->nombre)->toBe('VENTAS')
        ->and(Area::query()->where('empresa_id', $empresa->id)->where('nombre_normalizado', 'ventas')->count())->toBe(1);
});
