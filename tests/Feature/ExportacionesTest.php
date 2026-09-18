<?php

use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\Conjunto;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\ServicioAuditoria;
use App\Soporte\ContextoExportacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormatos;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
 * Empresas/Inventario ya tenían su propio export previo (`ReporteController`)
 * y no se tocan aquí. Entregas SÍ se probó aquí junto con el resto: además
 * del export agregado de Reportes (fuera de alcance), el listado de
 * Entregas ahora tiene su propio `[Exportar]` con los filtros propios de esa
 * pantalla (búsqueda/sucursal/almacén/estado/servicio/fecha), no los de
 * Reportes.
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

    // Sin `empresa_id` en la URL el reporte queda acotado a "Todas las
    // empresas" (nunca a la primera empresa autorizada): el nombre de
    // archivo incluye ese segmento (ver `ContextoExportacion::nombreArchivo()`).
    $archivo = Str::slug($titulo).'-todas-las-empresas-'.now()->toDateString().'.xlsx';
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

it('exporta Activos a Excel respetando el alcance del usuario', function () {
    Activo::factory()->for($this->datos['empresaB'])->create(['nombre' => 'Laptop Oculta B']);

    assertFilasExportadas($this, '/activos/exportar', $this->supervisorA, 'Activos', function (array $filas): void {
        $nombres = array_column($filas, 0);
        expect($nombres)->toContain($this->datos['activoA']->nombre)
            ->not->toContain('Laptop Oculta B');
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

it('exporta Entregas a Excel respetando el alcance del usuario y los filtros de la pantalla', function () {
    $this->supervisorA->givePermissionTo('entregas.ver');

    EntregaUniforme::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->datos['colaboradorA'])->create([
        'folio' => 'ENT-VISIBLE-A',
        'estado' => EstadoEntrega::Firmada,
    ]);
    EntregaUniforme::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->datos['colaboradorA'])->create([
        'folio' => 'ENT-FILTRADA-A',
        'estado' => EstadoEntrega::PendienteFirma,
    ]);
    EntregaUniforme::factory()->for($this->datos['empresaB'])->create(['folio' => 'ENT-OCULTA-B']);

    // El export debe reflejar EXACTAMENTE el filtro activo en pantalla
    // (estado=firmada), no todo el alcance del usuario.
    Excel::fake();
    $this->actingAs($this->supervisorA)->get('/entregas/exportar?estado=firmada')->assertOk();

    Excel::assertDownloaded('entregas-todas-las-empresas-'.now()->toDateString().'.xlsx', function (ListadoExport $export): bool {
        $folios = array_column($export->array(), 0);
        expect($folios)->toContain('ENT-VISIBLE-A')
            ->not->toContain('ENT-FILTRADA-A')
            ->not->toContain('ENT-OCULTA-B');

        return true;
    });
});

it('exporta Entregas a PDF', function () {
    $this->supervisorA->givePermissionTo('entregas.ver');
    EntregaUniforme::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->for($this->datos['colaboradorA'])->create();

    $respuesta = $this->actingAs($this->supervisorA)->get('/entregas/exportar?formato=pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($respuesta->getContent())->toStartWith('%PDF-');
});

it('un usuario sin permiso de Entregas no puede exportarlas', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)->get('/entregas/exportar')->assertForbidden();
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

/*
|--------------------------------------------------------------------------
| Encabezado corporativo real (logo, empresa, filtros, estilos) — QA final
|--------------------------------------------------------------------------
| Excel::fake() (arriba) nunca ejecuta el AfterSheet que arma la metadata y
| el logo: aquí se genera el .xlsx REAL una sola vez (con logo y sin logo)
| para confirmar que ese código corre sin fallar y produce lo esperado.
*/

it('el Excel real de un reporte SIN KPIs usa una sola hoja "Datos" (sin "Resumen" redundante) con metadata y logo', function () {
    Storage::fake('public');
    $empresa = Empresa::factory()->create(['nombre_comercial' => 'Con Logo Real']);
    $ruta = UploadedFile::fake()->image('logo.png', 100, 100)->store("empresas/{$empresa->id}", 'public');
    $empresa->update(['logo_ruta' => $ruta]);

    $contexto = new ContextoExportacion('Sucursales', $empresa, ['Estado' => 'Activas'], 2, generadoPor: 'Ana Prueba');
    $export = new ListadoExport([[1, 'Uno'], [2, 'Dos']], ['Código', 'Nombre'], $contexto);

    $temporal = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($temporal, Excel::raw($export, ExcelFormatos::XLSX));

    $libro = IOFactory::load($temporal);

    // Sin KPIs ni gráficas, la hoja "Resumen" repetiría exactamente la misma
    // metadata que ya trae "Datos" — una sola hoja es la mejora esperada.
    expect($libro->getSheetNames())->toBe(['Datos']);

    $hoja = $libro->getSheetByName('Datos');
    expect($hoja->getCell('A1')->getValue())->toBe('Reporte:');
    expect($hoja->getCell('B1')->getValue())->toBe('Sucursales');
    expect($hoja->getCell('A2')->getValue())->toBe('Empresa:');
    expect($hoja->getCell('B2')->getValue())->toBe('Con Logo Real');
    expect($hoja->getCell('A3')->getValue())->toBe('Estado:');
    expect($hoja->getCell('B3')->getValue())->toBe('Activas');
    expect($hoja->getCell('A8')->getValue())->toBe('Código');
    expect((int) $hoja->getCell('A9')->getValue())->toBe(1);
    // El logo se dibuja en la única hoja que existe.
    expect(count($hoja->getDrawingCollection()))->toBe(1);

    @unlink($temporal);
});

it('el Excel real sin empresa ni filtros no falla y omite el logo', function () {
    $contexto = new ContextoExportacion('Empresas', null, [], 0);
    $export = new ListadoExport([], ['Código', 'Nombre'], $contexto);

    $temporal = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($temporal, Excel::raw($export, ExcelFormatos::XLSX));

    $libro = IOFactory::load($temporal);
    expect($libro->getSheetNames())->toBe(['Datos']);
    $hoja = $libro->getSheetByName('Datos');

    expect($hoja->getCell('B2')->getValue())->toBe('Todas las empresas');
    expect(count($hoja->getDrawingCollection()))->toBe(0);

    @unlink($temporal);
});

it('el Excel real de un reporte CON KPIs sigue generando la hoja "Resumen" aparte con las tarjetas', function () {
    $empresa = Empresa::factory()->create(['nombre_comercial' => 'Con KPIs']);
    $contexto = new ContextoExportacion(
        'Colaboradores', $empresa, [], 3, generadoPor: 'Ana Prueba',
        kpis: ['Colaboradores activos' => 3, 'Con expediente completo' => 1],
    );
    $export = new ListadoExport([[1, 'Uno'], [2, 'Dos'], [3, 'Tres']], ['Código', 'Nombre'], $contexto);

    $temporal = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($temporal, Excel::raw($export, ExcelFormatos::XLSX));

    $libro = IOFactory::load($temporal);
    expect($libro->getSheetNames())->toBe(['Resumen', 'Datos']);

    $resumen = $libro->getSheetByName('Resumen');
    expect($resumen->getCell('A1')->getValue())->toBe('Colaboradores');
    // Tarjetas KPI: etiqueta en la fila de la tarjeta, valor en la siguiente.
    $valores = [];
    foreach (range(1, 20) as $fila) {
        foreach (['A', 'B', 'C', 'D'] as $columna) {
            $valores[] = $resumen->getCell("{$columna}{$fila}")->getValue();
        }
    }
    expect($valores)->toContain('Colaboradores activos')->toContain(3);

    @unlink($temporal);
});

/*
|--------------------------------------------------------------------------
| Rediseño visual del Excel administrativo — QA con archivos REALES
|--------------------------------------------------------------------------
| `DecoraConContexto::estiloAdministrativo()` (true por defecto, false en
| `EntregasExport`/`InventarioExport` de Reportes) separa el estilo nuevo
| del de Reportes sin duplicar la infraestructura. Aquí se generan 3 .xlsx
| REALES (Usuarios, Activos, Entregas — mismos encabezados que sus
| controladores) y se inspeccionan con PhpSpreadsheet: estilos esenciales,
| freeze pane, autofilter, configuración de impresión, ancho/wrap de
| columna larga y presencia/ausencia correcta de la hoja "Resumen". No se
| verifica cada color exacto — sólo lo que demuestra que el diseño
| realmente se aplicó.
*/

it('Excel real de Usuarios (sin KPIs): una sola hoja, con el nuevo diseño de encabezado de tabla', function () {
    $contexto = new ContextoExportacion('Usuarios', null, ['Estado' => 'Activos'], 2, generadoPor: 'Ana Prueba');
    $export = new ListadoExport(
        [
            ['Ana Ramírez', 'ana@empresa.test', 'Administrador', 'Empresa A', 'Central', 'Activo', 'Sí'],
            ['Beto López', 'beto@empresa.test', 'Encargado', 'Empresa A', 'Norte', 'Activo', 'No'],
        ],
        ['Nombre', 'Correo', 'Roles', 'Empresas', 'Sucursales', 'Estado', 'Correo verificado'],
        $contexto,
    );

    $temporal = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($temporal, Excel::raw($export, ExcelFormatos::XLSX));
    $libro = IOFactory::load($temporal);

    expect($libro->getSheetNames())->toBe(['Datos']);
    $hoja = $libro->getSheetByName('Datos');

    // Banda de metadata: título con acento + fondo suave sobre todo el
    // bloque (no líneas de texto sueltas).
    expect($hoja->getStyle('B1')->getFont()->getBold())->toBeTrue();
    expect((int) $hoja->getStyle('B1')->getFont()->getSize())->toBeGreaterThanOrEqual(14);
    expect($hoja->getStyle('A1')->getFill()->getFillType())->toBe(Fill::FILL_SOLID);

    // Encabezado de la TABLA (fila 8: 6 metadatos + Generado/Registros +
    // fila en blanco = 7, encabezado en la 8): fondo sólido oscuro, texto
    // blanco en negrita, centrado.
    $filaEncabezado = 8;
    expect($hoja->getCell("A{$filaEncabezado}")->getValue())->toBe('Nombre');
    $estiloEncabezado = $hoja->getStyle("A{$filaEncabezado}");
    expect($estiloEncabezado->getFont()->getBold())->toBeTrue();
    expect($estiloEncabezado->getFont()->getColor()->getRGB())->toBe('FFFFFF');
    expect($estiloEncabezado->getFill()->getFillType())->toBe(Fill::FILL_SOLID);
    expect($estiloEncabezado->getFill()->getStartColor()->getRGB())->toBe('1E293B');
    expect($estiloEncabezado->getAlignment()->getHorizontal())->toBe('center');

    // Freeze pane justo debajo del encabezado + autofiltro sobre la tabla.
    expect($hoja->getFreezePane())->toBe('A9');
    expect($hoja->getAutoFilter()->getRange())->toBe('A8:G10');

    // Impresión: horizontal, ajustada al ancho, encabezado repetido, pie
    // de página con nombre del sistema y número de página.
    $pageSetup = $hoja->getPageSetup();
    expect($pageSetup->getOrientation())->toBe(PageSetup::ORIENTATION_LANDSCAPE);
    expect($pageSetup->getFitToWidth())->toBe(1);
    expect($pageSetup->getFitToHeight())->toBe(0);
    expect($pageSetup->getRowsToRepeatAtTop())->toBe([(string) $filaEncabezado, (string) $filaEncabezado]);
    expect($hoja->getHeaderFooter()->getOddFooter())->toContain('&P')->toContain('&N');

    @unlink($temporal);
});

it('Excel real de Activos (sin KPIs): columna larga (Tipo) queda con ancho máximo y wrap, fechas/números alineados', function () {
    $descripcionLarga = 'Equipo de cómputo portátil para uso administrativo en oficinas centrales y sucursales foráneas';
    $contexto = new ContextoExportacion('Activos', null, [], 1, generadoPor: 'Ana Prueba');
    $export = new ListadoExport(
        [['Laptop Dell', 'ACT-0001', $descripcionLarga, 'Cómputo', 'Empresa A', 'Individual', 5, 0, 'Activo']],
        ['Nombre', 'Código', 'Tipo', 'Categoría', 'Empresa', 'Control', 'Existencias', 'Bajo mínimo', 'Estado'],
        $contexto,
    );

    $temporal = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($temporal, Excel::raw($export, ExcelFormatos::XLSX));
    $libro = IOFactory::load($temporal);

    $hoja = $libro->getSheetByName('Datos');
    $filaEncabezado = 7; // Reporte/Empresa/Generado por/Generado/Registros (5) + blanco = 6, encabezado en 7.
    $filaDato = $filaEncabezado + 1;

    // La columna "Tipo" (C) es la más larga: ancho topado + wrap text.
    expect($hoja->getColumnDimension('C')->getAutoSize())->toBeFalse();
    expect((float) $hoja->getColumnDimension('C')->getWidth())->toBe(45.0);
    expect($hoja->getStyle("C{$filaDato}")->getAlignment()->getWrapText())->toBeTrue();

    // "Existencias" (G) es numérica en toda la columna → alineada a la
    // derecha automáticamente (nunca se tocan los valores, sólo el estilo).
    expect($hoja->getStyle("G{$filaDato}")->getAlignment()->getHorizontal())->toBe('right');

    @unlink($temporal);
});

it('Excel real de Entregas (CON KPIs): sigue generando la hoja "Resumen" con tarjetas y franja de acento', function () {
    $contexto = new ContextoExportacion(
        'Entregas', null, ['Estado' => 'Firmada'], 1, generadoPor: 'Ana Prueba',
        kpis: ['Entregas' => 1, 'Renglones' => 2, 'Firmadas' => 1, 'Pendientes de firma' => 0],
    );
    $export = new ListadoExport(
        [['ENT-000001', 'Juan Pérez', '0001', 'Empresa A', 'Central', 'Aseo', '15/09/2026', 'Firmada', 2, 'Ana Encargada']],
        ['Folio', 'Colaborador', 'N.º empleado', 'Empresa', 'Sucursal', 'Servicio', 'Fecha', 'Estado', 'Renglones', 'Responsable'],
        $contexto,
    );

    $temporal = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($temporal, Excel::raw($export, ExcelFormatos::XLSX));
    $libro = IOFactory::load($temporal);

    expect($libro->getSheetNames())->toBe(['Resumen', 'Datos']);

    $resumen = $libro->getSheetByName('Resumen');
    expect($resumen->getCell('A1')->getValue())->toBe('Entregas');
    // Franja superior de acento en la primera tarjeta KPI (look de "tile").
    // Tarjetas KPI arrancan en la fila 7 (`construirHojaResumen()`:
    // A1 título, A2 empresa, A3 generado por, A4 filtros, A5 total de
    // registros, fila 6 de aire, tarjetas desde la 7).
    expect($resumen->getStyle('A7')->getBorders()->getTop()->getBorderStyle())->toBe(Border::BORDER_MEDIUM);

    $hoja = $libro->getSheetByName('Datos');
    $filaEncabezado = 8; // Reporte/Empresa/Estado/Generado por/Generado/Registros + blanco = 7.
    $filaDato = $filaEncabezado + 1;
    // "Fecha" (columna G) es 15/09/2026 en toda la columna → centrada.
    expect($hoja->getStyle("G{$filaDato}")->getAlignment()->getHorizontal())->toBe('center');

    @unlink($temporal);
});

it('un usuario sin el permiso del módulo no puede exportarlo', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($sinPermiso)->get('/empresas/exportar')->assertForbidden();
    $this->actingAs($sinPermiso)->get('/auditoria/exportar')->assertForbidden();
});
