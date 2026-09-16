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
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Invariantes OOXML del .xlsx REAL (abierto como ZIP, no sólo lo que
 * `PhpSpreadsheet` reporta en memoria) para la nueva arquitectura de
 * gráficas-como-imagen:
 *
 * Apple Numbers 14.4 cierra la app al ENTRAR a cualquier hoja con un
 * `<c:chart>` OOXML nativo de PhpSpreadsheet (crash en `TSCharts`/
 * `TSCHSeriesStyleForSeriesIndex`), sin importar si los caches de punto
 * están completos o si hay una sola gráfica por hoja — el objeto chart en sí
 * es el disparador. La solución fue dejar de agregar `Chart` nativos por
 * completo y sustituirlos por una imagen PNG normal (`ServicioGraficaImagen`
 * + `Drawing`, ver `DecoraConContexto::pintarGraficaExcel()`), inmune a ese
 * parser. Este archivo valida, directamente sobre el paquete OOXML:
 *
 * 1) El paquete NUNCA contiene `xl/charts/chart*.xml` (cero charts nativos).
 * 2) Cada hoja de gráfica referencia, vía sus relaciones reales
 *    (`xl/worksheets/_rels/sheetN.xml.rels` → drawing →
 *    `xl/drawings/_rels/drawingN.xml.rels` → imagen), EXACTAMENTE una imagen
 *    real (`xl/media/*.png`), con bytes PNG válidos — nunca dos imágenes
 *    en la misma hoja ni una referencia rota.
 * 3) Existen las hojas "Resumen" y "Datos".
 * 4) Los nombres de hoja son válidos (≤ 31 caracteres, sin
 *    `: \ / ? * [ ]`) y únicos.
 */
function extraerImagenesDeHoja(string $rutaXlsx, string $nombreArchivoHoja): array
{
    $zip = new ZipArchive;
    expect($zip->open($rutaXlsx))->toBe(true);

    $relsHoja = $zip->getFromName("xl/worksheets/_rels/{$nombreArchivoHoja}.rels");
    if ($relsHoja === false) {
        $zip->close();

        return [];
    }

    preg_match_all('/Target="\.\.\/drawings\/(drawing\d+\.xml)"/', $relsHoja, $drawings);

    $imagenes = [];
    foreach ($drawings[1] as $drawing) {
        $relsDrawing = $zip->getFromName("xl/drawings/_rels/{$drawing}.rels");
        if ($relsDrawing === false) {
            continue;
        }

        preg_match_all('/Target="\.\.\/media\/([^"]+)"/', $relsDrawing, $medias);
        foreach ($medias[1] as $media) {
            $bytes = $zip->getFromName("xl/media/{$media}");
            expect($bytes)->not->toBeFalse("xl/media/{$media} referenciado pero ausente del paquete.");
            $imagenes[] = $bytes;
        }
    }

    $zip->close();

    return $imagenes;
}

/**
 * @return array<int, string> nombre de archivo interno (sheetN.xml) por índice de hoja, en el orden del libro.
 */
function nombresArchivoDeHojas(string $rutaXlsx): array
{
    $zip = new ZipArchive;
    expect($zip->open($rutaXlsx))->toBe(true);

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $zip->close();

    preg_match_all('/<sheet[^>]*r:id="(rId\d+)"/', $workbookXml, $sheetRefs);
    preg_match_all('/Id="(rId\d+)"[^>]*Target="(worksheets\/sheet\d+\.xml)"/', $relsXml, $relPairs);
    $mapaRelaciones = array_combine($relPairs[1], $relPairs[2]);

    return array_map(
        fn (string $rId): string => basename($mapaRelaciones[$rId]),
        $sheetRefs[1],
    );
}

function assertSinChartsNativosNiXml(string $rutaXlsx): void
{
    $zip = new ZipArchive;
    expect($zip->open($rutaXlsx))->toBe(true);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombre = $zip->getNameIndex($i);
        expect($nombre === false || ! preg_match('#^xl/charts/chart\d+\.xml$#', $nombre))->toBeTrue(
            "{$nombre}: el paquete no debe contener charts OOXML nativos — Numbers 14.4 crashea con esto.",
        );
    }

    $zip->close();
}

/**
 * Cada imagen encontrada debe ser un PNG real (firma mágica `\x89PNG`) y
 * cada hoja de gráfica debe tener EXACTAMENTE una.
 */
function assertImagenesDeGraficaValidas(string $rutaXlsx, int $esperadas): void
{
    $libro = IOFactory::createReader('Xlsx')->load($rutaXlsx);
    $nombresHoja = $libro->getSheetNames();
    $archivosHoja = nombresArchivoDeHojas($rutaXlsx);

    $totalImagenes = 0;
    foreach ($nombresHoja as $indice => $nombre) {
        if (in_array($nombre, ['Resumen', 'Datos'], true)) {
            continue;
        }

        $imagenes = extraerImagenesDeHoja($rutaXlsx, $archivosHoja[$indice]);
        expect($imagenes)->toHaveCount(1, "la hoja «{$nombre}» debería tener exactamente 1 imagen de gráfica.");
        expect(substr($imagenes[0], 0, 8))->toBe("\x89PNG\r\n\x1a\n", "la imagen de «{$nombre}» no es un PNG válido.");
        $totalImagenes++;
    }

    expect($totalImagenes)->toBe($esperadas);
}

function assertHojasResumenYDatos(string $rutaXlsx): void
{
    $libro = IOFactory::createReader('Xlsx')->load($rutaXlsx);

    expect($libro->getSheetByName('Resumen'))->not->toBeNull('falta la hoja «Resumen».');
    expect($libro->getSheetByName('Datos'))->not->toBeNull('falta la hoja «Datos».');
}

function assertNombresDeHojaValidosYUnicos(string $rutaXlsx): void
{
    $libro = IOFactory::createReader('Xlsx')->load($rutaXlsx);

    $nombres = [];
    foreach ($libro->getAllSheets() as $hoja) {
        $nombre = $hoja->getTitle();

        expect(strlen($nombre))->toBeLessThanOrEqual(31, "«{$nombre}» excede 31 caracteres.");
        expect($nombre)->not->toMatch('/[:\\\\\/\?\*\[\]]/', "«{$nombre}» trae un carácter inválido para nombre de hoja.");
        expect(in_array($nombre, $nombres, true))->toBeFalse("el nombre de hoja «{$nombre}» está duplicado.");

        $nombres[] = $nombre;
    }
}

it('el XLSX de Entregas: sin charts nativos, imágenes PNG válidas una por hoja, Resumen/Datos y nombres válidos', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $activoA = Activo::factory()->for($empresa)->create();
    $activoB = Activo::factory()->for($empresa)->create();

    $entrega1 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaborador)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($entrega1, 'entrega')->for($activoA)->create(['cantidad' => 3, 'activo_nombre_snapshot' => 'Casco']);
    $entrega2 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaborador)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($entrega2, 'entrega')->for($activoB)->create(['cantidad' => 2, 'activo_nombre_snapshot' => 'Guantes']);

    $devolucion = Devolucion::factory()->for($empresa)->for($sucursal)->for($colaborador)->create();
    DetalleDevolucion::factory()->for($devolucion, 'devolucion')->for($activoA)->create(['cantidad' => 1]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();

    // Comparativa entregas/devoluciones + top activos + por sucursal (se
    // muestra aunque sea una sola sucursal, mientras haya entregas reales).
    assertSinChartsNativosNiXml($ruta);
    assertImagenesDeGraficaValidas($ruta, esperadas: 3);
    assertHojasResumenYDatos($ruta);
    assertNombresDeHojaValidosYUnicos($ruta);
});

it('el XLSX de Inventario: sin charts nativos, imágenes PNG válidas, Resumen/Datos y nombres válidos', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresa)->create();
    $almacenB = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->for($empresa)->for($almacenA)->for($activo)->create(['cantidad' => 0, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacenB)->for($activo)->create(['cantidad' => 40, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();

    // Disponible por almacén + riesgo de desabasto (sin unidades identificadas en este escenario).
    assertSinChartsNativosNiXml($ruta);
    assertImagenesDeGraficaValidas($ruta, esperadas: 2);
    assertHojasResumenYDatos($ruta);
    assertNombresDeHojaValidosYUnicos($ruta);
});
