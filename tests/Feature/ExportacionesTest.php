<?php

use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Conjunto;
use App\Models\Devolucion;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Fase 8 — Excel/PDF por módulo, misma consulta filtrada que el `index()` de
 * cada uno (nunca una aparte). El Excel y el PDF de cada módulo comparten las
 * MISMAS filas (`ListadoExport`/`listado-generico.blade.php`), así que basta
 * verificar el contenido/aislamiento una vez por módulo vía Excel (con
 * `Excel::fake()`: barato, inspecciona `$export->array()` sin generar el
 * .xlsx real) — el pipeline de PDF en sí (dompdf, cabeceras, `%PDF-`) se
 * confirma aparte, sólo un par de veces, para no acumular el costo de
 * renderizar 11 PDFs reales en la misma corrida de la suite completa.
 * Empresas/Inventario (Entregas) ya tenían su propio export previo
 * (`ReporteController`) y no se tocan aquí.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
    // Alcance restringido a la empresa A únicamente, para probar aislamiento.
    $this->supervisorA = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
});

/**
 * Verifica, vía `Excel::fake()`, que el listado exportado (mismas filas que
 * el PDF) cumpla `$verificarFilas`. No genera ningún archivo real.
 */
function assertFilasExportadas(TestCase $test, string $url, User $usuario, string $titulo, Closure $verificarFilas): void
{
    Excel::fake();

    $test->actingAs($usuario)->get($url)->assertOk();

    $archivo = Str::slug($titulo).'-'.now()->toDateString().'.xlsx';
    Excel::assertDownloaded($archivo, function (ListadoExport $export) use ($verificarFilas): bool {
        $verificarFilas($export->array());

        return true;
    });
}

it('exporta Empresas a Excel y PDF, respetando el alcance del usuario (aislamiento entre empresas)', function () {
    assertFilasExportadas($this, '/empresas/exportar', $this->supervisorA, 'Empresas', function (array $filas): void {
        $nombres = array_column($filas, 1);
        expect($nombres)->toContain('Empresa A')->not->toContain('Empresa B');
    });

    // Pipeline de PDF (dompdf/cabeceras) verificado una sola vez aquí.
    $respuesta = $this->actingAs($this->supervisorA)->get('/empresas/exportar?formato=pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($respuesta->getContent())->toStartWith('%PDF-');
});

it('exporta Sucursales a Excel respetando el alcance del usuario', function () {
    assertFilasExportadas($this, '/sucursales/exportar', $this->supervisorA, 'Sucursales', function (array $filas): void {
        $nombres = array_column($filas, 1);
        expect($nombres)->toContain($this->datos['sucursalA']->nombre)
            ->not->toContain($this->datos['sucursalB']->nombre);
    });
});

it('exporta Áreas a Excel respetando el alcance del usuario', function () {
    $areaA = Area::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Recursos Humanos']);
    $areaB = Area::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Sistemas B']);

    assertFilasExportadas($this, '/areas/exportar', $this->supervisorA, 'Áreas', function (array $filas) use ($areaA, $areaB): void {
        $nombres = array_column($filas, 0);
        expect($nombres)->toContain($areaA->nombre)->not->toContain($areaB->nombre);
    });
});

it('exporta Almacenes a Excel respetando el alcance del usuario', function () {
    $almacenA = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacen Norte']);
    $almacenB = Almacen::factory()->paraEmpresa($this->datos['empresaB'])->create(['nombre' => 'Almacen Sur']);

    assertFilasExportadas($this, '/almacenes/exportar', $this->supervisorA, 'Almacenes', function (array $filas) use ($almacenA, $almacenB): void {
        $nombres = array_column($filas, 0);
        expect($nombres)->toContain($almacenA->nombre)->not->toContain($almacenB->nombre);
    });
});

it('exporta Conjuntos a Excel respetando el alcance del usuario', function () {
    $conjuntoA = Conjunto::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Uniforme Completo A']);
    $conjuntoB = Conjunto::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Uniforme Completo B']);

    assertFilasExportadas($this, '/conjuntos/exportar', $this->supervisorA, 'Conjuntos', function (array $filas) use ($conjuntoA, $conjuntoB): void {
        $nombres = array_column($filas, 0);
        expect($nombres)->toContain($conjuntoA->nombre)->not->toContain($conjuntoB->nombre);
    });
});

it('exporta Movimientos de inventario a Excel y PDF, respetando el alcance del usuario', function () {
    $this->supervisorA->givePermissionTo('inventario.ver');
    MovimientoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => $this->datos['tallaA']->id,
        'motivo' => 'Movimiento visible para A',
    ]);
    MovimientoInventario::factory()->create([
        'empresa_id' => $this->datos['empresaB']->id,
        'almacen_id' => $this->datos['almacenB']->id,
        'motivo' => 'Movimiento oculto de B',
    ]);

    assertFilasExportadas($this, '/inventario/movimientos/exportar', $this->supervisorA, 'Movimientos de inventario', function (array $filas): void {
        $motivos = array_column($filas, 11);
        expect($motivos)->toContain('Movimiento visible para A')
            ->not->toContain('Movimiento oculto de B');
    });

    // Pipeline de PDF verificado una segunda vez con un dataset distinto
    // (varias columnas, orientación landscape).
    $respuesta = $this->actingAs($this->supervisorA)->get('/inventario/movimientos/exportar?formato=pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($respuesta->getContent())->toStartWith('%PDF-');
});

it('exporta Devoluciones a Excel respetando el alcance del usuario', function () {
    Devolucion::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->datos['colaboradorA'])->create(['folio' => 'DEV-VISIBLE-A']);
    Devolucion::factory()->for($this->datos['empresaB'])->create(['folio' => 'DEV-OCULTA-B']);

    $this->supervisorA->givePermissionTo('devoluciones.ver');
    assertFilasExportadas($this, '/devoluciones/exportar', $this->supervisorA, 'Devoluciones', function (array $filas): void {
        $folios = array_column($filas, 0);
        expect($folios)->toContain('DEV-VISIBLE-A')->not->toContain('DEV-OCULTA-B');
    });
});

it('exporta Unidades identificadas a Excel respetando el alcance del usuario', function () {
    $this->supervisorA->givePermissionTo('unidades-activo.ver');
    $activoIndividualA = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $activoIndividualB = Activo::factory()->for($this->datos['empresaB'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividualA)->for($this->datos['almacenA'])->create(['codigo' => 'UNIDAD-VISIBLE-A']);
    UnidadActivo::factory()->for($this->datos['empresaB'], 'empresa')->for($activoIndividualB)->for($this->datos['almacenB'])->create(['codigo' => 'UNIDAD-OCULTA-B']);

    assertFilasExportadas($this, '/activos/unidades/exportar', $this->supervisorA, 'Unidades identificadas', function (array $filas): void {
        $codigos = array_column($filas, 0);
        expect($codigos)->toContain('UNIDAD-VISIBLE-A')->not->toContain('UNIDAD-OCULTA-B');
    });
});

it('exporta Colaboradores a Excel respetando el alcance del usuario', function () {
    assertFilasExportadas($this, '/colaboradores/exportar', $this->supervisorA, 'Colaboradores', function (array $filas): void {
        $nombres = array_column($filas, 1);
        expect($nombres)->toContain($this->datos['colaboradorA']->nombre_completo);
    });
});

it('exporta la Auditoría a Excel y PDF, respetando el alcance del usuario', function () {
    $this->supervisorA->givePermissionTo('auditoria.ver');

    $this->actingAs($this->admin);
    app(ServicioAuditoria::class)->registrar('areas', 'crear', [
        'empresa_id' => $this->datos['empresaA']->id,
        'descripcion' => 'Alta de area visible para A',
    ]);
    app(ServicioAuditoria::class)->registrar('areas', 'crear', [
        'empresa_id' => $this->datos['empresaB']->id,
        'descripcion' => 'Alta de area oculta de B',
    ]);

    assertFilasExportadas($this, '/auditoria/exportar', $this->supervisorA, 'Auditoría', function (array $filas): void {
        $descripciones = array_column($filas, 4);
        expect($descripciones)->toContain('Alta de area visible para A')
            ->not->toContain('Alta de area oculta de B');
    });
});

it('un usuario sin el permiso del módulo no puede exportarlo', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)->get('/empresas/exportar')->assertForbidden();
    $this->actingAs($sinPermiso)->get('/auditoria/exportar')->assertForbidden();
});
