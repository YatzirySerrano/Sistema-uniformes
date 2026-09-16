<?php

use App\Enums\RolSistema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->superadmin = usuarioCon(RolSistema::Superadministrador->value);
});

/**
 * @param  array<string, mixed>  $errores
 */
function erroresDeCampo(array $errores, string $hoja, string $campo): array
{
    return array_values(array_filter(
        $errores,
        fn (array $e): bool => $e['hoja'] === $hoja && $e['campo'] === $campo,
    ));
}

it('rechaza un archivo que no es xlsx', function (): void {
    $this->actingAs($this->superadmin)
        ->post('/datos/importar-maestro/prevalidar', [
            'archivo' => UploadedFile::fake()->create('maestro.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('archivo');
});

it('rechaza un archivo renombrado a .xlsx que no tiene firma ZIP real', function (): void {
    $ruta = tempnam(sys_get_temp_dir(), 'falso_').'.xlsx';
    file_put_contents($ruta, 'esto no es un xlsx real');

    $archivo = new UploadedFile(
        $ruta,
        'maestro.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $this->actingAs($this->superadmin)
        ->post('/datos/importar-maestro/prevalidar', ['archivo' => $archivo])
        ->assertSessionHasErrors('archivo');
});

it('reporta EMPRESAS: RFC obligatorio, formato inválido y duplicado en el archivo', function (): void {
    $rfcValido = rfcDeQaValido();

    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['EMPRESAS'] = [
        ['nombre_comercial' => 'Sin RFC', 'rfc' => null, 'activa' => true],
        ['nombre_comercial' => 'RFC Malo', 'rfc' => 'NO-VALIDO', 'activa' => true],
        ['nombre_comercial' => 'Repetida 1', 'rfc' => $rfcValido, 'activa' => true],
        ['nombre_comercial' => 'Repetida 2', 'rfc' => $rfcValido, 'activa' => true],
    ];
    // El resto de hojas depende de "INMAG"/"ESTRATEGIAS": al no existir esas
    // empresas, sus filas también fallan — es el comportamiento esperado
    // (cascada de errores visibles, no un bug).

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'EMPRESAS', 'rfc'))->toHaveCount(3);
});

it('COLABORADORES: CURP obligatoria, formato inválido y duplicada bloquean la fila', function (): void {
    $curpValida = curpDeQaValida();

    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['COLABORADORES'] = [
        ['empresa' => 'INMAG', 'sucursal' => 'MEX', 'nombre_completo' => 'Sin Curp', 'curp' => null, 'activo' => true],
        ['empresa' => 'INMAG', 'sucursal' => 'MEX', 'nombre_completo' => 'Curp Mala', 'curp' => 'NOVALIDA', 'activo' => true],
        ['empresa' => 'INMAG', 'sucursal' => 'MEX', 'nombre_completo' => 'Repetido 1', 'curp' => $curpValida, 'activo' => true],
        ['empresa' => 'INMAG', 'sucursal' => 'MEX', 'nombre_completo' => 'Repetido 2', 'curp' => $curpValida, 'activo' => true],
    ];
    // UNIDADES_ACTIVO del fixture referencia la CURP original del
    // colaborador base: al reemplazar COLABORADORES, esa fila también falla
    // (esperado — cascada visible, no oculta el problema real).

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'COLABORADORES', 'curp'))->toHaveCount(3);
});

it('reporta referencias no resueltas: sucursal, contrato y servicio inexistentes', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['SUCURSALES'] = [];
    $hojas['CONTRATOS'] = [];
    $hojas['SERVICIOS'] = [
        ['empresa' => 'INMAG', 'contrato' => 'NO EXISTE', 'sucursal' => 'NO EXISTE', 'nombre' => 'X', 'activo' => true],
    ];
    $hojas['COLABORADORES'][0]['sucursal'] = 'NO EXISTE';
    $hojas['COLABORADORES'][0]['servicio'] = 'NO EXISTE';

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'SERVICIOS', 'contrato'))->toHaveCount(1);
    expect(erroresDeCampo($analisis['errores'], 'COLABORADORES', 'sucursal'))->toHaveCount(1);
});

it('marca un servicio AMBIGUO cuando el mismo nombre existe en dos contratos de la misma empresa', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['CONTRATOS'][] = ['empresa' => 'INMAG', 'nombre' => 'OTRO CONTRATO', 'activo' => true];
    $hojas['SERVICIOS'][] = ['empresa' => 'INMAG', 'contrato' => 'OTRO CONTRATO', 'sucursal' => 'MEX', 'nombre' => 'SONIC PEDREGAL', 'activo' => true];
    $hojas['COLABORADORES'][0]['servicio'] = 'SONIC PEDREGAL';

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'COLABORADORES', 'servicio'))->toHaveCount(1);
    expect(erroresDeCampo($analisis['errores'], 'COLABORADORES', 'servicio')[0]['error'])->toContain('más de un servicio');
});

it('TALLAS: rechaza una variante repetida en el mismo archivo', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['TALLAS'] = [['valor' => 'Unica'], ['valor' => ' unica ']];
    $hojas['ACTIVO_TALLA'] = [];

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'TALLAS', 'valor'))->toHaveCount(1);
});

it('ACTIVOS: rechaza un tipo_control inválido', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['ACTIVOS'][0]['tipo_control'] = 'serializado';
    $hojas['ACTIVO_TALLA'] = [];

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'ACTIVOS', 'tipo_control'))->toHaveCount(1);
});

it('ACTIVO_TALLA: rechaza un activo o una talla que no existen', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['ACTIVO_TALLA'] = [
        ['empresa' => 'INMAG', 'activo' => 'No Existe', 'talla' => 'Unica'],
    ];

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'ACTIVO_TALLA', 'activo'))->toHaveCount(1);
});

it('ALMACENES: rechaza una empresa desconocida en empresas_abastecidas', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['ALMACENES'][0]['empresas_abastecidas'] = 'INMAG;NO EXISTE';

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'ALMACENES', 'empresas_abastecidas'))->toHaveCount(1);
});

it('UNIDADES_ACTIVO: rechaza un activo por cantidad (no admite seguimiento individual)', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['UNIDADES_ACTIVO'][0]['activo'] = 'Camisola';
    $hojas['UNIDADES_ACTIVO'][0]['colaborador_curp'] = null;

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect(erroresDeCampo($analisis['errores'], 'UNIDADES_ACTIVO', 'activo'))->toHaveCount(1);
});

it('UNIDADES_ACTIVO: nombre de colaborador ambiguo pide colaborador_curp', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['COLABORADORES'][] = [
        'empresa' => 'INMAG', 'sucursal' => 'MEX', 'nombre_completo' => 'Juan Perez Lopez',
        'curp' => curpDeQaValida(), 'activo' => true,
    ];
    $hojas['UNIDADES_ACTIVO'][0]['colaborador_curp'] = null;
    $hojas['UNIDADES_ACTIVO'][0]['colaborador'] = 'Juan Perez Lopez';

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    $errores = erroresDeCampo($analisis['errores'], 'UNIDADES_ACTIVO', 'colaborador');
    expect($errores)->toHaveCount(1);
    expect($errores[0]['error'])->toContain('colaborador_curp');
});

it('UNIDADES_ACTIVO: sin colaborador la unidad es válida y queda en almacén', function (): void {
    $hojas = workbookMaestroCompleto()['hojas'];
    $hojas['UNIDADES_ACTIVO'][0]['colaborador_curp'] = null;

    $analisis = prevalidarWorkbookMaestro($this, $this->superadmin, $hojas);

    expect($analisis['errores'])->toBe([]);
    expect($analisis['resumen']['UNIDADES_ACTIVO']['validos'])->toBe(1);
});
