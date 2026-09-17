<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ronda de pulido visual/semántico del Excel de Reportes (Entregas e
 * Inventario): nombres de KPI claros ("Registros de artículos" en vez de
 * "Líneas de detalle entregadas", sin tocar el cálculo), descripción breve
 * por tarjeta KPI (`ContextoExportacion::kpisResueltos()`), y tablas de
 * respaldo de las hojas de gráfica con diseño corporativo (encabezado
 * oscuro, sin filas vacías "decoradas" más allá de los datos reales).
 *
 * Deliberadamente NO depende de coordenadas de celda fijas: localiza
 * encabezados/textos por CONTENIDO (`coordenadaConTexto()`) y lee el texto
 * completo de una hoja (`textoCompletoDeHoja()`) — así el layout puede
 * ajustarse sin que estas pruebas se vuelvan frágiles.
 */
function textoCompletoDeHoja(Worksheet $hoja): string
{
    $valores = [];
    foreach ($hoja->toArray(null, true, true, true) as $fila) {
        foreach ($fila as $valor) {
            if ($valor !== null && $valor !== '') {
                $valores[] = (string) $valor;
            }
        }
    }

    return implode(' | ', $valores);
}

/**
 * Coordenada (p. ej. "A7") de la PRIMERA celda cuyo valor sea exactamente
 * `$texto`, o `null` si no existe. Para localizar un encabezado sin asumir
 * en qué fila/columna cayó.
 */
function coordenadaConTexto(Worksheet $hoja, string $texto): ?string
{
    foreach ($hoja->getRowIterator() as $fila) {
        foreach ($fila->getCellIterator() as $celda) {
            if ((string) $celda->getValue() === $texto) {
                return $celda->getCoordinate();
            }
        }
    }

    return null;
}

/**
 * @return Worksheet primera hoja del libro que no sea "Resumen" ni "Datos"
 *                   (es decir, una hoja de gráfica).
 */
function primeraHojaDeGrafica(Spreadsheet $libro): Worksheet
{
    foreach ($libro->getAllSheets() as $hoja) {
        if (! in_array($hoja->getTitle(), ['Resumen', 'Datos'], true)) {
            return $hoja;
        }
    }

    throw new RuntimeException('El libro no trae ninguna hoja de gráfica.');
}

it('el Excel de Entregas usa nombres de KPI claros y ya NO dice "Líneas de detalle entregadas"', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $entrega = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaborador)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($entrega, 'entrega')->for($activo)->create(['cantidad' => 3]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $resumen = IOFactory::createReader('Xlsx')->load($ruta)->getSheetByName('Resumen');
    $texto = textoCompletoDeHoja($resumen);

    expect($texto)
        ->toContain('Entregas realizadas')
        ->toContain('Registros de artículos')
        ->toContain('Piezas entregadas')
        ->toContain('Colaboradores con entrega')
        ->toContain('Tipos de activos entregados')
        ->not->toContain('Líneas de detalle entregadas')
        ->not->toContain('Colaboradores únicos con entrega')
        ->not->toContain('Tipos de activos distintos entregados');
});

it('el Excel de Entregas trae la descripción breve de cada KPI en la tarjeta, no sólo etiqueta y número', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $resumen = IOFactory::createReader('Xlsx')->load($ruta)->getSheetByName('Resumen');
    $texto = textoCompletoDeHoja($resumen);

    expect($texto)
        ->toContain('Renglones de artículos incluidos en las entregas')
        ->toContain('un registro puede contener varias piezas')
        ->toContain('Número de entregas registradas en el periodo filtrado')
        ->toContain('Colaboradores distintos que recibieron al menos una entrega');
});

it('el Excel de Inventario describe "Variantes/tallas..." con un texto honesto (la variante puede no existir, no siempre es una talla real)', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 2, 'minimo' => 10]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $resumen = IOFactory::createReader('Xlsx')->load($ruta)->getSheetByName('Resumen');
    $texto = textoCompletoDeHoja($resumen);

    expect($texto)
        ->toContain('Variantes/tallas bajo mínimo')
        ->toContain('Posiciones de inventario')
        ->toContain('empresa + almacén + activo + variante')
        ->toContain('Unidades de seguimiento individual listas para entregar');
});

it('la tabla de respaldo de una hoja de gráfica tiene encabezado estilizado: fondo oscuro, texto blanco y negrita', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $entrega = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($entrega, 'entrega')->for($activo)->create(['cantidad' => 4, 'activo_nombre_snapshot' => 'Casco']);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);
    $hoja = primeraHojaDeGrafica($libro);

    $coordEncabezado = coordenadaConTexto($hoja, 'Categoría') ?? coordenadaConTexto($hoja, 'Periodo');
    expect($coordEncabezado)->not->toBeNull();

    $estilo = $hoja->getStyle($coordEncabezado);
    expect($estilo->getFont()->getBold())->toBeTrue();
    expect($estilo->getFont()->getColor()->getRGB())->toBe('FFFFFF');
    expect($estilo->getFill()->getStartColor()->getRGB())->toBe('1E293B');
});

it('la tabla de respaldo de una hoja de gráfica NO pinta filas vacías más allá de los datos reales', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();

    // 3 activos con existencia bajo mínimo = exactamente 3 filas reales en
    // "Riesgo de desabasto (faltante)".
    foreach (range(1, 3) as $i) {
        $activo = Activo::factory()->for($empresa)->create();
        SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 1, 'minimo' => 5]);
    }

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);

    $hojaDesabasto = collect($libro->getAllSheets())->first(fn ($h) => str_contains($h->getTitle(), 'Riesgo'));
    expect($hojaDesabasto)->not->toBeNull();

    $coordEncabezado = coordenadaConTexto($hojaDesabasto, 'Categoría');
    expect($coordEncabezado)->not->toBeNull();
    $filaEncabezado = (int) preg_replace('/\D/', '', $coordEncabezado);

    $filaUltimoDato = $filaEncabezado + 3;
    $filaDespuesDeDatos = $filaUltimoDato + 1;

    // La última fila CON dato real sí trae borde (parte de la tabla)...
    expect($hojaDesabasto->getStyle("A{$filaUltimoDato}")->getBorders()->getTop()->getBorderStyle())
        ->toBe(Border::BORDER_THIN);
    // ...pero una fila más abajo (sin dato) ya no — nunca "reserva" espacio decorado de más.
    expect($hojaDesabasto->getStyle("A{$filaDespuesDeDatos}")->getBorders()->getTop()->getBorderStyle())
        ->toBe(Border::BORDER_NONE);
});

it('el Excel conserva la variante/talla de "Top activos entregados" en la tabla de respaldo de la hoja de gráfica', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create(['nombre' => 'Calzado de Seguridad']);

    $entrega = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($entrega, 'entrega')->for($activo)->create([
        'cantidad' => 5, 'activo_nombre_snapshot' => 'Calzado de Seguridad', 'talla_valor_snapshot' => '32',
    ]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $libro = IOFactory::createReader('Xlsx')->load($ruta);

    $hojaTop = collect($libro->getAllSheets())->first(fn ($h) => str_contains($h->getTitle(), 'Top activos'));
    expect($hojaTop)->not->toBeNull();

    $texto = textoCompletoDeHoja($hojaTop);
    // Datos de la tabla y de la imagen de la gráfica vienen del MISMO
    // `SerieGraficaReporte->etiquetas` — comprobar la tabla ya confirma que
    // no se perdió la variante en ninguno de los dos.
    expect($texto)->toContain('Calzado de Seguridad (32)');
});

it('un KPI en 0 se ve como 0 en la tarjeta del Excel, nunca una celda vacía', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->seguimientoIndividual()->create();
    // Sólo disponibles, ninguna asignada => "Unidades asignadas" = 0.
    UnidadActivo::factory()->for($empresa, 'empresa')->for($activo)->for($almacen)->count(2)->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $respuesta = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=xlsx');
    $respuesta->assertOk();
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $resumen = IOFactory::createReader('Xlsx')->load($ruta)->getSheetByName('Resumen');

    $coordEtiqueta = coordenadaConTexto($resumen, 'Unidades asignadas');
    expect($coordEtiqueta)->not->toBeNull();
    // El valor vive una fila debajo de la etiqueta, misma columna.
    preg_match('/([A-Z]+)(\d+)/', $coordEtiqueta, $partes);
    $coordValor = $partes[1].((int) $partes[2] + 1);

    expect($resumen->getCell($coordValor)->getValue())->toBe(0);
});

it('el Excel conserva "Generado por" del usuario autenticado real, con empresa filtrada y con "Todas las empresas"', function () {
    sembrarRolesPermisos();
    $empresaA = Empresa::factory()->create();
    $sucursalA = Sucursal::factory()->for($empresaA)->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresaA)->create();
    EntregaUniforme::factory()->for($empresaA)->for($sucursalA)->create(['almacen_id' => $almacenA->id]);

    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $almacenB = Almacen::factory()->paraEmpresa($empresaB)->create();
    EntregaUniforme::factory()->for($empresaB)->for($sucursalB)->create(['almacen_id' => $almacenB->id]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresaA, $empresaB]);

    // Caso A: empresa específica.
    $respuestaA = $this->actingAs($admin)->get("/reportes/entregas/exportar?empresa_id={$empresaA->id}&formato=xlsx");
    $respuestaA->assertOk();
    $rutaA = $respuestaA->baseResponse->getFile()->getPathname();
    $resumenA = IOFactory::createReader('Xlsx')->load($rutaA)->getSheetByName('Resumen');
    expect(textoCompletoDeHoja($resumenA))->toContain($admin->name);

    // Caso B: "Todas las empresas" — nunca debe inventar un usuario distinto.
    $respuestaB = $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=xlsx');
    $respuestaB->assertOk();
    $rutaB = $respuestaB->baseResponse->getFile()->getPathname();
    $resumenB = IOFactory::createReader('Xlsx')->load($rutaB)->getSheetByName('Resumen');
    $textoB = textoCompletoDeHoja($resumenB);
    expect($textoB)->toContain($admin->name);
    expect($textoB)->toContain('Todas las empresas');
});
