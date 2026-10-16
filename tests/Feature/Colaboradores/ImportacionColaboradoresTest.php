<?php

use App\Enums\RolSistema;
use App\Exports\PlantillaColaboradoresExport;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\SecuenciaCodigo;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Reingeniería del importador de colaboradores: `numero_empleado` ya no se
 * captura en el Excel (lo genera `App\Soporte\GeneradorNumeroEmpleado`, misma
 * fuente que el alta manual — ver [[NumeroEmpleadoTest]] y
 * [[ColaboradorCurpTest]]), la CURP es obligatoria y reutiliza
 * `GuardarColaboradorRequest::REGEX_CURP`, y la importación es todo-o-nada
 * (mismo patrón dry-run/confirmación que `ServicioImportacionMaestra`).
 *
 * Área, sucursal y servicio se resuelven SÓLO por código y dentro de la
 * empresa destino (`area_codigo`, `sucursal_codigo`, `servicio_codigo`):
 * nunca por nombre, nunca globalmente, nunca se crean.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);

    $this->areaA = Area::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Operaciones', 'codigo' => 'ARE-0001']);
    $this->contratoA = Contrato::factory()->create(['empresa_id' => $this->datos['empresaA']->id]);
    $this->servicioA = Servicio::factory()->create([
        'contrato_id' => $this->contratoA->id,
        'sucursal_id' => $this->datos['sucursalA']->id,
        'codigo' => 'SER-1001',
    ]);

    $this->areaB = Area::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Operaciones B', 'codigo' => 'ARE-0777']);
    $this->servicioB = Servicio::factory()->create([
        'contrato_id' => Contrato::factory()->create(['empresa_id' => $this->datos['empresaB']->id])->id,
        'sucursal_id' => $this->datos['sucursalB']->id,
        'codigo' => 'SER-2002',
    ]);

    // Códigos válidos de la empresa A para completar cada fila de prueba.
    $this->base = [
        'area_codigo' => $this->areaA->codigo,
        'sucursal_codigo' => $this->datos['sucursalA']->codigo,
        'servicio_codigo' => $this->servicioA->codigo,
    ];

    $this->confirmar = fn (User $usuario, string $token, ?int $empresaId = null) => $this->actingAs($usuario)
        ->post('/colaboradores/importar/confirmar', [
            'empresa_id' => $empresaId ?? $this->datos['empresaA']->id,
            'token' => $token,
        ]);
});

/*
|--------------------------------------------------------------------------
| Plantilla
|--------------------------------------------------------------------------
*/

it('descarga la plantilla como un XLSX real con exactamente las columnas nuevas', function (): void {
    $respuesta = $this->actingAs($this->admin)
        ->get('/colaboradores/importar/plantilla?empresa_id='.$this->datos['empresaA']->id);

    $respuesta->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertDownload('plantilla-colaboradores.xlsx');

    $archivo = $respuesta->baseResponse->getFile()->getPathname();
    expect(file_get_contents($archivo, length: 4))->toBe("PK\x03\x04");

    $hoja = IOFactory::load($archivo)->getActiveSheet();
    $encabezados = $hoja->rangeToArray('A1:'.$hoja->getHighestColumn().'1')[0];

    expect($encabezados)->toBe(['nombre_completo', 'curp', 'puesto', 'area_codigo', 'correo', 'sucursal_codigo', 'servicio_codigo'])
        ->and($encabezados)->not->toContain('area')
        ->and($encabezados)->not->toContain('numero_empleado');

    // Ejemplos con los códigos reales de la empresa elegida.
    expect($hoja->getCell('D2')->getValue())->toBe('ARE-0001')
        ->and($hoja->getCell('F2')->getValue())->toBe($this->datos['sucursalA']->codigo)
        ->and($hoja->getCell('G2')->getValue())->toBe('SER-1001');
});

it('la plantilla de una empresa sin catálogos usa códigos ficticios con el formato del sistema', function (): void {
    Excel::fake();
    $empresaVacia = Empresa::factory()->create();

    $this->actingAs($this->admin)
        ->get('/colaboradores/importar/plantilla?empresa_id='.$empresaVacia->id)
        ->assertOk();

    Excel::assertDownloaded('plantilla-colaboradores.xlsx', function (PlantillaColaboradoresExport $export): bool {
        expect($export->array()[0])->toBe([
            'Juan Pérez López', 'PELJ850101HDFRZN08', 'Operador', 'ARE-0001', 'juan.perez@example.test', 'MATRIZ', 'SER-0001',
        ]);

        return true;
    });
});

it('la plantilla de una empresa fuera del alcance del usuario se rechaza', function (): void {
    $restringido = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $restringido->givePermissionTo('colaboradores.importar');

    $this->actingAs($restringido)
        ->get('/colaboradores/importar/plantilla?empresa_id='.$this->datos['empresaB']->id)
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Validación de columnas (encabezado)
|--------------------------------------------------------------------------
*/

it('bloquea el archivo si falta una columna de la plantilla', function (): void {
    $encabezados = array_values(array_diff(encabezadosImportacionColaboradores(), ['puesto']));
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Juan Perez', 'curp' => curpDeQaValida(), ...$this->base],
    ], $encabezados);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['faltantes'])->toBe(['puesto'])
        ->and($analisis['columnas']['desactualizada'])->toBeFalse()
        ->and($analisis['total'])->toBe(0)
        ->and($analisis['listos'])->toBe(0);

    expect(Colaborador::query()->where('nombre_completo', 'Juan Perez')->exists())->toBeFalse();
});

it('un archivo de la plantilla vieja (con area y sin area_codigo) se marca desactualizado y nunca resuelve el área por nombre', function (): void {
    $encabezadosViejos = ['nombre_completo', 'curp', 'puesto', 'area', 'correo', 'sucursal_codigo'];
    $archivo = construirExcelColaboradores([
        [
            'nombre_completo' => 'Plantilla Vieja', 'curp' => curpDeQaValida(),
            'area' => $this->areaA->nombre, 'sucursal_codigo' => $this->datos['sucursalA']->codigo,
        ],
    ], $encabezadosViejos);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['desactualizada'])->toBeTrue()
        ->and($analisis['columnas']['faltantes'])->toBe(['area_codigo', 'servicio_codigo'])
        ->and($analisis['columnas']['adicionales'])->toBe(['area'])
        ->and($analisis['listos'])->toBe(0);

    ($this->confirmar)($this->admin, $analisis['token'])->assertStatus(422);

    expect(Colaborador::query()->where('nombre_completo', 'Plantilla Vieja')->exists())->toBeFalse();
});

it('un archivo sin la columna servicio_codigo se rechaza como plantilla desactualizada', function (): void {
    $encabezados = array_values(array_diff(encabezadosImportacionColaboradores(), ['servicio_codigo']));
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sin Columna Servicio', 'curp' => curpDeQaValida(), ...$this->base],
    ], $encabezados);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['faltantes'])->toBe(['servicio_codigo'])
        ->and($analisis['columnas']['desactualizada'])->toBeTrue()
        ->and($analisis['listos'])->toBe(0);
});

it('una columna area adicional junto a area_codigo se ignora: el área sale del código', function (): void {
    $otraArea = Area::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Compras', 'codigo' => 'ARE-0002']);
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Columna Extra', 'curp' => curpDeQaValida(), 'area' => 'Compras', ...$this->base],
    ], array_merge(encabezadosImportacionColaboradores(), ['area']));

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['columnas']['adicionales'])->toBe(['area']);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

    expect(Colaborador::query()->where('nombre_completo', 'Columna Extra')->value('area_id'))
        ->toBe($this->areaA->id)
        ->not->toBe($otraArea->id);
});

it('reporta numero_empleado de una plantilla vieja como columna adicional y no lo usa', function (): void {
    $archivo = construirExcelColaboradores([
        ['numero_empleado' => 'XX-9999', 'nombre_completo' => 'Con Numero Viejo', 'curp' => curpDeQaValida(), ...$this->base],
    ], array_merge(['numero_empleado'], encabezadosImportacionColaboradores()));

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['adicionales'])->toBe(['numero_empleado'])
        ->and($analisis['columnas']['faltantes'])->toBe([])
        ->and($analisis['listos'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Validación de filas: CURP, correo
|--------------------------------------------------------------------------
*/

it('reporta la fila sin CURP como error', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Sin Curp', 'curp' => null, ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe('curp')
        ->and($analisis['errores'][0]['error'])->toBe('La CURP es obligatoria.')
        ->and($analisis['listos'])->toBe(0);
});

it('reporta una CURP con formato inválido como error, indicando fila/campo/valor', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Curp Invalida', 'curp' => 'NOVALIDA', ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1);
    expect($analisis['errores'][0])->toMatchArray([
        'fila' => 2,
        'campo' => 'curp',
        'valor' => 'NOVALIDA',
    ]);
});

it('importa CURP de Querétaro con QT y con QO', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Queretano QT', 'curp' => 'MASG901214HQTRRL04', ...$this->base],
        ['nombre_completo' => 'Queretano QO', 'curp' => 'HEGG560427MQORRL04', ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['errores'])->toBe([])
        ->and($analisis['listos'])->toBe(2);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect('/colaboradores');

    expect(Colaborador::query()->whereIn('curp', ['MASG901214HQTRRL04', 'HEGG560427MQORRL04'])->count())->toBe(2);
});

it('detecta una CURP repetida dentro del mismo archivo', function (): void {
    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Repetido Uno', 'curp' => $curp, ...$this->base],
        ['nombre_completo' => 'Repetido Dos', 'curp' => $curp, ...$this->base],
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
        ['nombre_completo' => 'Intento Reusar', 'curp' => $curpReservada, ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['duplicados'])->toHaveCount(1)
        ->and($analisis['duplicados'][0]['error'])->toBe('La CURP ya existe en el sistema.');
});

it('reporta un correo con formato inválido como error', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Correo Malo', 'curp' => curpDeQaValida(), 'correo' => 'no-es-un-correo', ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe('correo');
});

/*
|--------------------------------------------------------------------------
| Área, sucursal y servicio por código, acotados a la empresa destino
|--------------------------------------------------------------------------
*/

it('reporta celdas vacías de area_codigo, sucursal_codigo y servicio_codigo en la fila correcta', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Fila Valida', 'curp' => curpDeQaValida(), ...$this->base],
        ['nombre_completo' => 'Sin Codigos', 'curp' => curpDeQaValida(), 'area_codigo' => null, 'sucursal_codigo' => null, 'servicio_codigo' => null],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['columnas']['faltantes'])->toBe([])
        ->and($analisis['listos'])->toBe(1)
        ->and(collect($analisis['errores'])->pluck('fila')->unique()->all())->toBe([3])
        ->and(collect($analisis['errores'])->pluck('error', 'campo')->all())->toBe([
            'area_codigo' => 'El código de área es obligatorio.',
            'sucursal_codigo' => 'La sucursal es obligatoria.',
            'servicio_codigo' => 'El código de servicio es obligatorio.',
        ]);
});

it('rechaza códigos inexistentes o de otra empresa con un mensaje claro por campo', function (string $campo, Closure $valor, string $mensaje): void {
    $codigo = $valor->call($this);
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Codigo Malo', 'curp' => curpDeQaValida(), ...$this->base, $campo => $codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0])->toMatchArray([
            'fila' => 2,
            'campo' => $campo,
            'valor' => $codigo,
            'error' => str_replace(':codigo', $codigo, $mensaje),
        ])
        ->and($analisis['listos'])->toBe(0);
})->with([
    'área inexistente' => ['area_codigo', fn () => 'ARE-9999', 'Área con código «:codigo» no encontrada en la empresa seleccionada.'],
    'área de otra empresa' => ['area_codigo', fn () => $this->areaB->codigo, 'Área con código «:codigo» no encontrada en la empresa seleccionada.'],
    'sucursal inexistente' => ['sucursal_codigo', fn () => 'SUC-9999', 'Sucursal con código «:codigo» no encontrada en la empresa seleccionada.'],
    'sucursal de otra empresa' => ['sucursal_codigo', fn () => $this->datos['sucursalB']->codigo, 'Sucursal con código «:codigo» no encontrada en la empresa seleccionada.'],
    'servicio inexistente' => ['servicio_codigo', fn () => 'SER-9999', 'Servicio con código «:codigo» no encontrado en la empresa seleccionada.'],
    'servicio de otra empresa' => ['servicio_codigo', fn () => $this->servicioB->codigo, 'El servicio «:codigo» pertenece a otra empresa.'],
]);

it('el código de área de otra empresa no se toma aunque coincida con uno de la empresa destino', function (): void {
    // Mismo código en ambas empresas (es único por empresa, no global).
    $areaMismoCodigoB = Area::factory()->for($this->datos['empresaB'])->create(['codigo' => $this->areaA->codigo]);
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Codigo Compartido', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

    expect(Colaborador::query()->where('nombre_completo', 'Codigo Compartido')->value('area_id'))
        ->toBe($this->areaA->id)
        ->not->toBe($areaMismoCodigoB->id);
});

it('rechaza área, sucursal o servicio inactivos', function (string $campo, Closure $desactivar, string $mensaje): void {
    $desactivar->call($this);
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Inactivo', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toHaveCount(1)
        ->and($analisis['errores'][0]['campo'])->toBe($campo)
        ->and($analisis['errores'][0]['error'])->toContain($mensaje);
})->with([
    'área' => ['area_codigo', fn () => $this->areaA->update(['activa' => false]), 'está inactiva'],
    'sucursal' => ['sucursal_codigo', fn () => $this->datos['sucursalA']->update(['activa' => false]), 'está inactiva'],
    'servicio' => ['servicio_codigo', fn () => $this->servicioA->update(['activo' => false]), 'está inactivo'],
    'contrato del servicio' => ['servicio_codigo', fn () => $this->contratoA->update(['activo' => false]), 'El contrato del servicio'],
]);

it('resuelve los códigos sin distinguir mayúsculas ni espacios alrededor', function (): void {
    $archivo = construirExcelColaboradores([
        [
            'nombre_completo' => 'Codigos En Minuscula', 'curp' => curpDeQaValida(),
            'area_codigo' => ' '.strtolower($this->areaA->codigo).' ',
            'sucursal_codigo' => strtolower($this->datos['sucursalA']->codigo),
            'servicio_codigo' => strtolower($this->servicioA->codigo),
        ],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toBe([])
        ->and($analisis['listos'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Flujo completo: analizar no escribe, confirmar crea con las relaciones
|--------------------------------------------------------------------------
*/

it('analiza y confirma un colaborador con empresa, área, sucursal y servicio correctos', function (): void {
    $archivo = construirExcelColaboradores([
        [
            'nombre_completo' => 'Persona Ejemplo', 'curp' => 'MASG901214HQTRRL04', 'puesto' => 'Operador',
            'correo' => 'ejemplo@example.test', ...$this->base,
        ],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toBe([])
        ->and($analisis['duplicados'])->toBe([])
        ->and($analisis['listos'])->toBe(1)
        ->and($analisis['areas']['existentes'])->toBe(['Operaciones']);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect('/colaboradores');

    $colaborador = Colaborador::query()->where('curp', 'MASG901214HQTRRL04')->firstOrFail();

    expect($colaborador->empresa_id)->toBe($this->datos['empresaA']->id)
        ->and($colaborador->area_id)->toBe($this->areaA->id)
        ->and($colaborador->area)->toBe('Operaciones')
        ->and($colaborador->sucursal_id)->toBe($this->datos['sucursalA']->id)
        ->and($colaborador->servicio_actual_id)->toBe($this->servicioA->id)
        ->and($colaborador->servicioActual->is($this->servicioA))->toBeTrue()
        ->and($colaborador->puesto)->toBe('Operador')
        ->and($colaborador->correo)->toBe('ejemplo@example.test');
});

it('la sucursal del colaborador puede ser distinta de la responsable del servicio (mismo criterio que el flujo manual) y se respeta tal cual', function (): void {
    $otraSucursalA = Sucursal::factory()->for($this->datos['empresaA'])->create();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Otra Sucursal', 'curp' => curpDeQaValida(), ...$this->base, 'sucursal_codigo' => $otraSucursalA->codigo],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['errores'])->toBe([]);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

    $colaborador = Colaborador::query()->where('nombre_completo', 'Otra Sucursal')->firstOrFail();

    expect($colaborador->sucursal_id)->toBe($otraSucursalA->id)
        ->and($colaborador->servicio_actual_id)->toBe($this->servicioA->id)
        ->and($this->servicioA->fresh()->sucursal_id)->toBe($this->datos['sucursalA']->id);
});

it('analizar (prevalidación) no escribe nada: ni colaboradores, ni consecutivo, ni áreas', function (): void {
    $areasAntes = Area::query()->count();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Solo Analizado', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect(SecuenciaCodigo::query()->where('empresa_id', $this->datos['empresaA']->id)->where('ambito', 'colaborador')->exists())
        ->toBeFalse()
        ->and(Colaborador::query()->where('nombre_completo', 'Solo Analizado')->exists())->toBeFalse()
        ->and(Colaborador::query()->whereNotNull('servicio_actual_id')->exists())->toBeFalse()
        ->and(Area::query()->count())->toBe($areasAntes);
});

it('la confirmación revalida en servidor: si el servicio se desactiva tras analizar, no importa nada', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Datos Obsoletos', 'curp' => curpDeQaValida(), ...$this->base],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['listos'])->toBe(1);

    $this->servicioA->update(['activo' => false]);

    ($this->confirmar)($this->admin, $analisis['token'])->assertStatus(422);

    expect(Colaborador::query()->where('nombre_completo', 'Datos Obsoletos')->exists())->toBeFalse();
});

it('la confirmación revalida en servidor: si el área desaparece o la CURP se registra tras analizar, no importa nada', function (Closure $cambio): void {
    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Cambio Intermedio', 'curp' => $curp, ...$this->base],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['listos'])->toBe(1);

    $cambio->call($this, $curp);

    ($this->confirmar)($this->admin, $analisis['token'])->assertStatus(422);

    expect(Colaborador::query()->where('nombre_completo', 'Cambio Intermedio')->exists())->toBeFalse();
})->with([
    'área eliminada' => [fn () => $this->areaA->delete()],
    'CURP registrada por otra captura' => [fn (string $curp) => Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create(['curp' => $curp])],
]);

/*
|--------------------------------------------------------------------------
| Generación del número de empleado
|--------------------------------------------------------------------------
*/

it('confirmar genera el numero_empleado con GeneradorNumeroEmpleado, nunca con el del Excel', function (): void {
    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        ['numero_empleado' => 'INVENTADO-1', 'nombre_completo' => 'Juan Perez', 'curp' => $curp, ...$this->base],
    ], array_merge(['numero_empleado'], encabezadosImportacionColaboradores()));

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

    $colaborador = Colaborador::query()->where('curp', $curp)->firstOrFail();

    expect($colaborador->numero_empleado)
        ->not->toBe('INVENTADO-1')
        ->toMatch('/^JP\d{4,}$/');
});

it('dos colaboradores importados en el mismo archivo reciben numero_empleado distintos', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Ana Lopez', 'curp' => curpDeQaValida(), ...$this->base],
        ['nombre_completo' => 'Beto Gomez', 'curp' => curpDeQaValida(), ...$this->base],
        ['nombre_completo' => 'Ana Lopez', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

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
        ['nombre_completo' => 'Alta Por Excel', 'curp' => $curp, ...$this->base],
    ]);
    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

    $numeroImportado = Colaborador::query()->where('curp', $curp)->value('numero_empleado');

    expect($numeroImportado)->not->toBe($numeroManual);
});

/*
|--------------------------------------------------------------------------
| Todo o nada
|--------------------------------------------------------------------------
*/

it('una importación con filas mixtas (válidas y con error) no crea ningún colaborador', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Valido', 'curp' => curpDeQaValida(), ...$this->base],
        ['nombre_completo' => 'Curp Mala', 'curp' => 'NOVALIDA', ...$this->base],
        ['nombre_completo' => 'Servicio Malo', 'curp' => curpDeQaValida(), ...$this->base, 'servicio_codigo' => 'SER-9999'],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['listos'])->toBe(1)
        ->and(collect($analisis['errores'])->pluck('fila')->all())->toBe([3, 4]);

    ($this->confirmar)($this->admin, $analisis['token'])->assertStatus(422);

    expect(Colaborador::query()->where('nombre_completo', 'Valido')->exists())->toBeFalse();
});

it('confirmar con un archivo totalmente válido crea todos los colaboradores', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Uno Valido', 'curp' => curpDeQaValida(), 'puesto' => 'Operador', ...$this->base],
        ['nombre_completo' => 'Dos Valido', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);
    expect($analisis['listos'])->toBe(2);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect('/colaboradores');

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
        ['nombre_completo' => 'De Empresa A', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    ($this->confirmar)($this->admin, $analisis['token'], $this->datos['empresaB']->id)->assertStatus(422);
});

it('confirmar sin haber analizado antes (token inexistente) responde con un mensaje claro', function (): void {
    ($this->confirmar)($this->admin, 'token-que-no-existe.xlsx')->assertStatus(422);
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
        ['nombre_completo' => 'Auditado', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    ($this->confirmar)($this->admin, $analisis['token'])->assertRedirect();

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
        ['nombre_completo' => 'Sin Permiso', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $this->actingAs($encargado)
        ->post('/colaboradores/importar/analizar', [
            'empresa_id' => $this->datos['empresaA']->id,
            'archivo' => $archivo,
        ])
        ->assertForbidden();
});

it('un rol personalizado con colaboradores.importar importa en su empresa, pero no en una fuera de su alcance', function (): void {
    $rol = Role::create(['name' => 'capturista-rh', 'guard_name' => 'web']);
    $rol->givePermissionTo(['colaboradores.ver', 'colaboradores.importar']);
    $capturista = usuarioCon('capturista-rh', [$this->datos['empresaA']]);

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Por Rol Personalizado', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $capturista, $this->datos['empresaA'], $archivo);
    ($this->confirmar)($capturista, $analisis['token'])->assertRedirect('/colaboradores');

    expect(Colaborador::query()->where('nombre_completo', 'Por Rol Personalizado')->value('servicio_actual_id'))
        ->toBe($this->servicioA->id);

    $this->actingAs($capturista)
        ->post('/colaboradores/importar/analizar', [
            'empresa_id' => $this->datos['empresaB']->id,
            'archivo' => construirExcelColaboradores([
                ['nombre_completo' => 'Fuera De Alcance', 'curp' => curpDeQaValida(), ...$this->base],
            ]),
        ])
        ->assertForbidden();

    ($this->confirmar)($capturista, 'cualquier-token.xlsx', $this->datos['empresaB']->id)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Navegación: analizar es POST y nunca deja su URL en el historial
|--------------------------------------------------------------------------
| Regresión: `analizar()` respondía `Inertia::render()` directo al POST, así
| que la URL de la página quedaba en `/colaboradores/importar/analizar`; al
| recargar (F5, recarga completa de Vite, atrás/adelante) el navegador hacía
| GET a esa ruta → 405. Ahora es Post/Redirect/Get hacia la pantalla de
| importación.
*/

it('analizar por POST redirige a la pantalla de importación con el análisis, nunca deja la URL POST en el historial', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Prg Ejemplo', 'curp' => curpDeQaValida(), ...$this->base],
    ]);

    $this->actingAs($this->admin)
        ->post('/colaboradores/importar/analizar', ['empresa_id' => $this->datos['empresaA']->id, 'archivo' => $archivo])
        ->assertRedirect('/colaboradores/importar');

    $this->actingAs($this->admin)
        ->get('/colaboradores/importar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Importar')
            ->url('/colaboradores/importar')
            ->where('empresaSeleccionadaId', $this->datos['empresaA']->id)
            ->where('analisis.listos', 1));
});

it('la ruta de análisis sólo acepta POST', function (): void {
    $this->actingAs($this->admin)
        ->get('/colaboradores/importar/analizar')
        ->assertMethodNotAllowed();
});

/*
|--------------------------------------------------------------------------
| Errores acumulados por fila
|--------------------------------------------------------------------------
| Regresión: un error de cualquier otro campo hacía `continue` antes de
| revisar la CURP, así que una CURP ya registrada no se reportaba si la fila
| también tenía, p. ej., un servicio inexistente.
*/

it('una CURP ya existente se reporta aunque la fila tenga otro código inválido', function (string $campo, string $valor, string $mensaje): void {
    $existente = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Doble Problema', 'curp' => $existente->curp, ...$this->base, $campo => $valor],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['errores'])->toBe([
        ['fila' => 2, 'campo' => $campo, 'valor' => $valor, 'error' => $mensaje],
    ])->and($analisis['duplicados'])->toBe([
        ['fila' => 2, 'campo' => 'curp', 'valor' => $existente->curp, 'error' => 'La CURP ya existe en el sistema.'],
    ])->and($analisis['listos'])->toBe(0);
})->with([
    'servicio inexistente' => ['servicio_codigo', 'SER-9999', 'Servicio con código «SER-9999» no encontrado en la empresa seleccionada.'],
    'área inexistente' => ['area_codigo', 'ARE-9999', 'Área con código «ARE-9999» no encontrada en la empresa seleccionada.'],
]);

it('acumula errores independientes de la misma fila (correo y servicio) sin que uno oculte al otro', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Varios Errores', 'curp' => curpDeQaValida(), 'correo' => 'no-es-correo', ...$this->base, 'servicio_codigo' => 'SER-9999'],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect(collect($analisis['errores'])->pluck('campo')->sort()->values()->all())->toBe(['correo', 'servicio_codigo'])
        ->and($analisis['duplicados'])->toBe([]);
});

it('una CURP repetida en el archivo se detecta aunque su primera aparición tenga otro error', function (): void {
    $curp = curpDeQaValida();
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Primera Con Error', 'curp' => $curp, ...$this->base, 'servicio_codigo' => 'SER-9999'],
        ['nombre_completo' => 'Segunda Valida', 'curp' => $curp, ...$this->base],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect($analisis['duplicados'])->toHaveCount(1)
        ->and($analisis['duplicados'][0]['fila'])->toBe(3)
        ->and($analisis['duplicados'][0]['error'])->toContain('se repite en la fila 2')
        ->and($analisis['listos'])->toBe(0);
});

it('una CURP nueva con servicio inválido sólo reporta el servicio', function (): void {
    $archivo = construirExcelColaboradores([
        ['nombre_completo' => 'Solo Servicio', 'curp' => curpDeQaValida(), ...$this->base, 'servicio_codigo' => 'SER-9999'],
    ]);

    $analisis = analizarImportacionColaboradores($this, $this->admin, $this->datos['empresaA'], $archivo);

    expect(collect($analisis['errores'])->pluck('campo')->all())->toBe(['servicio_codigo'])
        ->and($analisis['duplicados'])->toBe([]);
});
