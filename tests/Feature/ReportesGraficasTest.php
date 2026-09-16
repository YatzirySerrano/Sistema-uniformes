<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\TraspasoInventario;
use App\Models\UnidadActivo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Cierre del rediseño ejecutivo de Excel/PDF (KPIs + gráficas): confirma que
 * los módulos "Tier 1" (los que sí aportan una dimensión graficable) arman
 * `ContextoExportacion::$kpis`/`$graficas` desde LA MISMA colección que ya
 * traían para las filas (nunca una consulta aparte) y que eso realmente
 * llega a una hoja propia por gráfica.
 *
 * Las gráficas del Excel ya NO son `PhpOffice\PhpSpreadsheet\Chart\Chart`
 * nativos — Apple Numbers 14.4 cierra la app al ENTRAR a cualquier hoja con
 * un `<c:chart>` OOXML nativo, con o sin caches completos, con o sin una
 * sola gráfica por hoja. Ahora cada gráfica se incrusta como IMAGEN PNG
 * (`ServicioGraficaImagen`, ver `DecoraConContexto::pintarGraficaExcel()`),
 * inmune a ese parser por construcción. Se verifica contando
 * `Worksheet::getDrawingCollection()` (la imagen) en vez de
 * `getChartCount()`, y que el paquete .xlsx no contenga `xl/charts/*`.
 *
 * El PDF sigue usando ApexCharts vivo (Chromium lo renderiza sin problema),
 * verificado sobre el HTML renderizado vía `Pdf::fake()` — más barato que
 * lanzar Chromium en cada caso; el pipeline real Browsershot/Chromium ya se
 * confirma con PDFs reales en `ReporteTest`.
 */
function totalImagenesDeGraficaEnLibro(Spreadsheet $libro): int
{
    $total = 0;
    foreach ($libro->getAllSheets() as $hoja) {
        if (in_array($hoja->getTitle(), ['Resumen', 'Datos'], true)) {
            continue; // esas hojas pueden traer el logo, no son hojas de gráfica.
        }
        $total += count($hoja->getDrawingCollection());
    }

    return $total;
}

/**
 * Regresión Numbers: ninguna hoja de gráfica debe traer más de una imagen.
 */
function assertUnaImagenPorHojaDeGrafica(Spreadsheet $libro): void
{
    foreach ($libro->getAllSheets() as $hoja) {
        if (in_array($hoja->getTitle(), ['Resumen', 'Datos'], true)) {
            continue;
        }

        expect(count($hoja->getDrawingCollection()))->toBeLessThanOrEqual(
            1,
            "La hoja «{$hoja->getTitle()}» trae ".count($hoja->getDrawingCollection()).' imágenes de gráfica.',
        );
    }
}

/**
 * El paquete .xlsx no debe contener ningún `xl/charts/chart*.xml` — si
 * aparece uno, algún export volvió a usar `PhpOffice\PhpSpreadsheet\Chart\Chart`
 * nativo, el disparador real del crash en Apple Numbers 14.4.
 */
function assertSinChartsNativos(string $rutaXlsx): void
{
    $zip = new ZipArchive;
    expect($zip->open($rutaXlsx))->toBe(true);

    $tieneChartsNativos = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombre = $zip->getNameIndex($i);
        if ($nombre !== false && preg_match('#^xl/charts/chart\d+\.xml$#', $nombre)) {
            $tieneChartsNativos = true;
            break;
        }
    }
    $zip->close();

    expect($tieneChartsNativos)->toBeFalse('el .xlsx contiene un chart nativo de PhpSpreadsheet — Numbers 14.4 crashea con esto.');
}
it('el Excel de Unidades identificadas trae la hoja Resumen con KPIs y la dona de posesión', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->count(2)->create();
    UnidadActivo::factory()->for($empresa)->for($activo)->for($almacen)->asignada()->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/activos/unidades/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);
    $resumen = $libro->getSheetByName('Resumen');

    expect($resumen)->not->toBeNull();
    expect(totalImagenesDeGraficaEnLibro($libro))->toBeGreaterThan(0);
    assertUnaImagenPorHojaDeGrafica($libro);
    assertSinChartsNativos($ruta);

    $textoResumen = collect(range(1, 10))->map(fn (int $f) => (string) $resumen->getCell("A{$f}")->getValue())->implode(' ');
    expect($textoResumen)->toContain('Unidades');
});

it('el Excel de Devoluciones trae la dona de condición cuando hay renglones', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();

    $devolucion = Devolucion::factory()->for($empresa)->for($sucursal)->for($colaborador)->create();
    DetalleDevolucion::factory()->for($devolucion, 'devolucion')->create(['condicion' => 'reutilizable']);
    DetalleDevolucion::factory()->for($devolucion, 'devolucion')->create(['condicion' => 'danado']);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/devoluciones/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);
    expect(totalImagenesDeGraficaEnLibro($libro))->toBe(1);
    assertUnaImagenPorHojaDeGrafica($libro);
    assertSinChartsNativos($ruta);
});

it('el Excel de Colaboradores trae las tres gráficas (empresa, sucursal, activos/inactivos)', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    Colaborador::factory()->count(2)->for($empresa)->for($sucursal)->create();
    Colaborador::factory()->for($empresa)->for($sucursal)->inactivo()->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/colaboradores/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);
    expect(totalImagenesDeGraficaEnLibro($libro))->toBe(3);
    assertUnaImagenPorHojaDeGrafica($libro);
    assertSinChartsNativos($ruta);
});

it('el Excel de Traspasos trae KPIs y las tres gráficas (origen, destino, por mes)', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresa)->create();
    $almacenB = Almacen::factory()->paraEmpresa($empresa)->create();

    TraspasoInventario::factory()->create([
        'empresa_origen_id' => $empresa->id,
        'almacen_origen_id' => $almacenA->id,
        'empresa_destino_id' => $empresa->id,
        'almacen_destino_id' => $almacenB->id,
    ]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/inventario/traspasos/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);
    expect(totalImagenesDeGraficaEnLibro($libro))->toBe(3);
    assertUnaImagenPorHojaDeGrafica($libro);
    assertSinChartsNativos($ruta);
});

it('un módulo sin renglones no revienta y simplemente omite las gráficas', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/colaboradores/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);
    expect(totalImagenesDeGraficaEnLibro($libro))->toBe(0);
});

it('el PDF de Devoluciones incluye ApexCharts y el título de la gráfica cuando hay renglones', function () {
    sembrarRolesPermisos();
    Pdf::fake();

    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $devolucion = Devolucion::factory()->for($empresa)->for($sucursal)->for($colaborador)->create();
    DetalleDevolucion::factory()->for($devolucion, 'devolucion')->create(['condicion' => 'reutilizable']);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/devoluciones/exportar?formato=pdf')->assertOk();

    Pdf::assertSee(['Renglones por condición', 'new ApexCharts', 'Generado por', $admin->name]);
});

it('el PDF de un módulo sin gráficas no carga ApexCharts (evita ~900 KB de JS inline innecesarios)', function () {
    sembrarRolesPermisos();
    Pdf::fake();

    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/empresas/exportar?formato=pdf')->assertOk();

    Pdf::assertDontSee('new ApexCharts');
});

it('el PDF de Reportes > Entregas incluye el glosario de KPIs para que el usuario no tenga que adivinar', function () {
    sembrarRolesPermisos();
    Pdf::fake();

    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=pdf')->assertOk();
    Pdf::assertSee(['Colaboradores únicos con entrega', 'Colaboradores distintos que recibieron al menos una entrega']);
});

it('el PDF de Reportes > Inventario incluye el glosario de KPIs para que el usuario no tenga que adivinar', function () {
    sembrarRolesPermisos();
    Pdf::fake();

    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 3, 'minimo' => 1]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=pdf')->assertOk();
    Pdf::assertSee(['Variantes/tallas bajo mínimo', 'Combinaciones de activo y variante cuya existencia ya alcanzó su mínimo configurado']);
});

it('el Excel de Entregas trae comparativa entregas/devoluciones, top activos y Resumen/Datos — sin charts nativos', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $entrega = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaborador)->create([
        'almacen_id' => $almacen->id, 'estado' => 'firmada',
    ]);
    DetalleEntrega::factory()->for($entrega, 'entrega')->for($activo)->create(['cantidad' => 4, 'activo_nombre_snapshot' => 'Casco']);

    $devolucion = Devolucion::factory()->for($empresa)->for($sucursal)->for($colaborador)->create();
    DetalleDevolucion::factory()->for($devolucion, 'devolucion')->for($activo)->create(['cantidad' => 1]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);

    expect($libro->getSheetByName('Resumen'))->not->toBeNull();
    expect($libro->getSheetByName('Datos'))->not->toBeNull();
    // Comparativa + top activos + por sucursal — se muestra aunque sea una
    // sola sucursal, siempre que haya entregas reales (nunca se omite sólo
    // por no haber más con qué comparar).
    expect(totalImagenesDeGraficaEnLibro($libro))->toBe(3);
    assertUnaImagenPorHojaDeGrafica($libro);
    assertSinChartsNativos($ruta);
});

it('el Excel de Inventario trae unidades/almacén/desabasto como imagen, nunca celdas vacías donde va un 0', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 0, 'minimo' => 5]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 40, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);

    expect($libro->getSheetByName('Resumen'))->not->toBeNull();
    // Sin unidades identificadas en este escenario: sólo "Disponible por
    // almacén" + "Riesgo de desabasto".
    expect(totalImagenesDeGraficaEnLibro($libro))->toBe(2);
    assertUnaImagenPorHojaDeGrafica($libro);
    assertSinChartsNativos($ruta);

    $datos = $libro->getSheetByName('Datos');
    // La fila con cantidad=0 debe existir con el valor literal 0 (nunca
    // celda vacía) — se ubica bajo el bloque de metadata + encabezado.
    $valores = [];
    foreach (range(1, 20) as $fila) {
        $valores[] = $datos->getCell("E{$fila}")->getValue();
    }
    expect($valores)->toContain(0);
});
