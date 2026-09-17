<?php

use App\Enums\CondicionUnidadActivo;
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
use App\Models\Talla;
use App\Models\UnidadActivo;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Cobertura de `/reportes`: filtros, alcance multiempresa y — desde esta
 * ronda — que pantalla/Excel/PDF comparten LOS MISMOS KPIs/gráficas
 * (`ServicioReportes::metricas*()`), que el filtro/columna/gráfica de
 * "Estado" desapareció de Entregas (la firma ya es obligatoria en el flujo
 * actual) y que Inventario nunca muestra una celda vacía donde debería decir
 * "0".
 */
it('GET /reportes responde 200 en ambos tabs', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reportes/Index')->where('tab', 'entregas'));

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reportes/Index')->where('tab', 'inventario'));
});

it('el filtro de empresa en Reportes/Entregas acota los resultados a esa empresa', function () {
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

    $this->actingAs($admin)
        ->get("/reportes?empresa_id={$empresaA->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.Entregas realizadas', 1)
            ->has('entregas.data', 1));
});

it('el filtro "solo bajo mínimo" en Reportes/Inventario sólo devuelve saldos bajo mínimo', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 1, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 50, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/reportes?tab=inventario&solo_bajo_minimo=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('inventario.data', 1)->where('inventario.data.0.disponible', 1));
});

it('un rol restringido no ve en Reportes datos de una empresa fuera de su alcance', function () {
    sembrarRolesPermisos();
    $empresaPropia = Empresa::factory()->create();
    $sucursalPropia = Sucursal::factory()->for($empresaPropia)->create();
    $almacenPropio = Almacen::factory()->paraEmpresa($empresaPropia)->create();
    EntregaUniforme::factory()->for($empresaPropia)->for($sucursalPropia)->create(['almacen_id' => $almacenPropio->id]);

    $empresaAjena = Empresa::factory()->create();
    $sucursalAjena = Sucursal::factory()->for($empresaAjena)->create();
    $almacenAjeno = Almacen::factory()->paraEmpresa($empresaAjena)->create();
    EntregaUniforme::factory()->for($empresaAjena)->for($sucursalAjena)->create(['almacen_id' => $almacenAjeno->id]);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$empresaPropia]);

    $this->actingAs($supervisor)
        ->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('kpis.Entregas realizadas', 1)->has('entregas.data', 1));
});

it('la exportación de Excel de Entregas respeta el filtro de empresa activo', function () {
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

    $this->actingAs($admin)
        ->get("/reportes/entregas/exportar?empresa_id={$empresaA->id}&formato=xlsx")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('sin permiso reportes.ver, /reportes responde 403', function () {
    sembrarRolesPermisos();
    $colaborador = usuarioCon(RolSistema::Colaborador->value);

    $this->actingAs($colaborador)->get('/reportes')->assertForbidden();
});

it('exporta el reporte de inventario en PDF respetando el mismo permiso y los filtros', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 2, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 40, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $completo = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=pdf');
    $completo->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($completo->getContent(), 0, 4))->toBe('%PDF');

    // El filtro `solo_bajo_minimo` se respeta igual que en pantalla / Excel.
    $filtrado = $this->actingAs($admin)->get('/reportes/inventario/exportar?formato=pdf&solo_bajo_minimo=1');
    $filtrado->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('el Excel de inventario sigue funcionando tras añadir el PDF', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 3, 'minimo' => 1]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get('/reportes/inventario/exportar?formato=xlsx')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('el reporte de Entregas ya no expone el filtro/catálogo de Estado ni acepta ?estado= como filtro real', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'pendiente_firma']);
    EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    // El catálogo de estados ya no se envía al frontend.
    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('catalogos.estados'));

    // `?estado=` ya no es un filtro válido: se ignora, ambas entregas siguen apareciendo.
    $this->actingAs($admin)
        ->get('/reportes?estado=firmada')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('kpis.Entregas realizadas', 2));
});

it('KPIs de Entregas: entregas, renglones, piezas, colaboradores y tipos de activos se calculan correctamente', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $colaboradorA = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $colaboradorB = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $activoA = Activo::factory()->for($empresa)->create();
    $activoB = Activo::factory()->for($empresa)->create();

    $e1 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaboradorA)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e1, 'entrega')->for($activoA)->create(['cantidad' => 3]);
    DetalleEntrega::factory()->for($e1, 'entrega')->for($activoB)->create(['cantidad' => 2]);

    $e2 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaboradorB)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e2, 'entrega')->for($activoA)->create(['cantidad' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.Entregas realizadas', 2)
            ->where('kpis.Registros de artículos', 3)
            ->where('kpis.Piezas entregadas', 10)
            ->where('kpis.Colaboradores con entrega', 2)
            ->where('kpis.Tipos de activos entregados', 2)
            ->missing('kpis.Sucursales atendidas')
            ->missing('kpis.Líneas de detalle entregadas'));
});

it('Top activos entregados incluye la variante/talla cuando el renglón la tiene', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create(['nombre' => 'Calzado de Seguridad']);

    $e1 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e1, 'entrega')->for($activo)->create([
        'cantidad' => 5, 'activo_nombre_snapshot' => 'Calzado de Seguridad', 'talla_valor_snapshot' => '32',
    ]);
    $e2 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e2, 'entrega')->for($activo)->create([
        'cantidad' => 2, 'activo_nombre_snapshot' => 'Calzado de Seguridad', 'talla_valor_snapshot' => '40',
    ]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Misma variante distinta = renglón distinto del top, nunca
            // fusionado sólo por compartir el mismo activo.
            ->where('graficas.top_activos.0.activo', 'Calzado de Seguridad')
            ->where('graficas.top_activos.0.talla', '32')
            ->where('graficas.top_activos.0.piezas', 5)
            ->where('graficas.top_activos.1.talla', '40')
            ->where('graficas.top_activos.1.piezas', 2));
});

it('Piezas entregadas por sucursal se muestra aunque TODAS las entregas sean de una sola sucursal (nunca vacía si hay datos reales)', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['nombre' => 'Jiutepec']);
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $e1 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e1, 'entrega')->for($activo)->create(['cantidad' => 3]);
    $e2 = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e2, 'entrega')->for($activo)->create(['cantidad' => 4]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)
        ->get("/reportes?empresa_id={$empresa->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('graficas.por_sucursal', 1)
            ->where('graficas.por_sucursal.0.sucursal', 'Jiutepec')
            ->where('graficas.por_sucursal.0.piezas', 7));
});

it('el "Generado por" de la exportación siempre es el usuario autenticado, con empresa filtrada o con "Todas las empresas"', function () {
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

    Pdf::fake();

    // Caso A: empresa específica.
    $this->actingAs($admin)->get("/reportes/entregas/exportar?empresa_id={$empresaA->id}&formato=pdf")->assertOk();
    Pdf::assertSee(['Generado por', $admin->name]);

    // Caso B: "Todas las empresas" (sin empresa_id) — nunca debe inventar
    // un usuario distinto al que realmente ejecutó la exportación.
    $this->actingAs($admin)->get('/reportes/entregas/exportar?formato=pdf')->assertOk();
    Pdf::assertSee(['Generado por', $admin->name]);
});

it('Top activos entregados ordena por PIEZAS, no por número de renglones', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $muchosRenglonesPocasPiezas = Activo::factory()->for($empresa)->create(['nombre' => 'Muchos renglones']);
    $pocosRenglonesMuchasPiezas = Activo::factory()->for($empresa)->create(['nombre' => 'Pocos renglones']);

    // 3 renglones de 1 pieza cada uno, misma variante = 3 piezas agrupadas.
    $tallaComun = Talla::factory()->create(['valor' => 'Única']);
    foreach (range(1, 3) as $i) {
        $e = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
        DetalleEntrega::factory()->for($e, 'entrega')->for($muchosRenglonesPocasPiezas)->create([
            'cantidad' => 1, 'activo_nombre_snapshot' => 'Muchos renglones', 'talla_id' => $tallaComun->id, 'talla_valor_snapshot' => 'Única',
        ]);
    }
    // 1 solo renglón de 50 piezas.
    $e = EntregaUniforme::factory()->for($empresa)->for($sucursal)->create(['almacen_id' => $almacen->id, 'estado' => 'firmada']);
    DetalleEntrega::factory()->for($e, 'entrega')->for($pocosRenglonesMuchasPiezas)->create(['cantidad' => 50, 'activo_nombre_snapshot' => 'Pocos renglones']);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('graficas.top_activos.0.activo', 'Pocos renglones')
            ->where('graficas.top_activos.0.piezas', 50)
            ->where('graficas.top_activos.1.piezas', 3));
});

it('la gráfica "entregas vs devoluciones" cuenta PIEZAS, no folios', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $colaborador = Colaborador::factory()->for($empresa)->for($sucursal)->create();
    $activo = Activo::factory()->for($empresa)->create();

    $entrega = EntregaUniforme::factory()->for($empresa)->for($sucursal)->for($colaborador)->create([
        'almacen_id' => $almacen->id, 'estado' => 'firmada', 'fecha_entrega' => now(),
    ]);
    DetalleEntrega::factory()->for($entrega, 'entrega')->for($activo)->create(['cantidad' => 7]);

    $devolucion = Devolucion::factory()->for($empresa)->for($sucursal)->for($colaborador)->create(['fecha' => now()]);
    DetalleDevolucion::factory()->for($devolucion, 'devolucion')->for($activo)->create(['cantidad' => 4]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('graficas.entregas_vs_devoluciones')
            ->where('graficas.entregas_vs_devoluciones.entregadas.0', 7)
            ->where('graficas.entregas_vs_devoluciones.devueltas.0', 4));
});

it('un módulo sin entregas ni devoluciones omite la gráfica comparativa en vez de mandar un arreglo vacío', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('graficas.entregas_vs_devoluciones', null));
});

it('la exportación de PDF de Entregas respeta el filtro de empresa activo', function () {
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

    $respuesta = $this->actingAs($admin)
        ->get("/reportes/entregas/exportar?empresa_id={$empresaA->id}&formato=pdf");
    $respuesta->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($respuesta->getContent(), 0, 4))->toBe('%PDF');
});

it('REGRESIÓN: el literal solo_bajo_minimo=false (string) rompe la validación — el frontend/export NUNCA debe mandarlo, sólo omitir el parámetro', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 50, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    // `Rule::boolean` de Laravel sólo acepta true/false/0/1/"0"/"1" — la
    // cadena literal "false" (lo que produciría un botón de exportar que
    // mandara `solo_bajo_minimo=false` en vez de omitirlo) FALLA la
    // validación y redirige con error en vez de exportar. Por eso
    // `filtrosExportar` en Reportes/Index.vue convierte el checkbox a `1` o
    // `undefined`, nunca a `false` literal.
    $this->actingAs($admin)
        ->get('/reportes/inventario/exportar?formato=xlsx&solo_bajo_minimo=false')
        ->assertRedirect()
        ->assertSessionHasErrors('solo_bajo_minimo');

    // Omitir el parámetro por completo (el contrato real de `filtrosExportar`) sí exporta.
    $this->actingAs($admin)
        ->get('/reportes/inventario/exportar?formato=xlsx')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('un usuario sin reportes.exportar no puede descargar el PDF de inventario aunque arme la URL', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $sinPermiso = usuarioCon(RolSistema::Encargado->value, [$empresa]);

    $this->actingAs($sinPermiso)
        ->get("/reportes/inventario/exportar?formato=pdf&empresa_id={$empresa->id}")
        ->assertForbidden();
});

it('la tabla de Inventario nunca deja "disponible" vacío: un saldo en 0 se ve como 0', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 0, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inventario.data.0.disponible', 0)
            ->where('inventario.data.0.estado_stock', 'sin_existencias')
            ->where('inventario.data.0.estado_stock_etiqueta', 'Sin existencias'));
});

it('Estado de stock: bajo mínimo y correcto se clasifican según la regla de negocio real', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 3, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activo)->create(['cantidad' => 40, 'minimo' => 5]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inventario.data.0.estado_stock', 'bajo_minimo')
            ->where('inventario.data.1.estado_stock', 'correcto')
            ->where('kpis.Variantes/tallas bajo mínimo', 1)
            ->where('kpis.Variantes/tallas sin existencias', 0));
});

it('Disponible por almacén suma las piezas reales por almacén', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacenA = Almacen::factory()->paraEmpresa($empresa)->create(['nombre' => 'Almacén Uno']);
    $almacenB = Almacen::factory()->paraEmpresa($empresa)->create(['nombre' => 'Almacén Dos']);
    $activo = Activo::factory()->for($empresa)->create();

    SaldoInventario::factory()->for($empresa)->for($almacenA)->for($activo)->create(['cantidad' => 30, 'minimo' => 1]);
    SaldoInventario::factory()->for($empresa)->for($almacenB)->for($activo)->create(['cantidad' => 10, 'minimo' => 1]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('graficas.por_almacen.0.almacen', 'Almacén Uno')
            ->where('graficas.por_almacen.0.piezas', 30)
            ->where('graficas.por_almacen.1.piezas', 10));
});

it('Riesgo de desabasto ordena por MAYOR FALTANTE y expone disponible/mínimo/faltante', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activoPocoFaltante = Activo::factory()->for($empresa)->create(['nombre' => 'Casi completo']);
    $activoMuchoFaltante = Activo::factory()->for($empresa)->create(['nombre' => 'Muy escaso']);

    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activoPocoFaltante)->create(['cantidad' => 8, 'minimo' => 10]);
    SaldoInventario::factory()->for($empresa)->for($almacen)->for($activoMuchoFaltante)->create(['cantidad' => 0, 'minimo' => 20]);

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('graficas.riesgo_desabasto.0.activo', 'Muy escaso')
            ->where('graficas.riesgo_desabasto.0.disponible', 0)
            ->where('graficas.riesgo_desabasto.0.minimo', 20)
            ->where('graficas.riesgo_desabasto.0.faltante', 20)
            ->where('graficas.riesgo_desabasto.1.faltante', 2));
});

it('Unidades por estado y sus KPIs separan claramente las unidades identificadas de los saldos por cantidad', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->seguimientoIndividual()->create();

    UnidadActivo::factory()->for($empresa, 'empresa')->for($activo)->for($almacen)->count(2)->create();
    UnidadActivo::factory()->for($empresa, 'empresa')->for($activo)->for($almacen)->asignada()->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.Unidades disponibles', 2)
            ->where('kpis.Unidades asignadas', 1)
            ->where('kpis.Piezas disponibles', 0));
});

it('Reportes > Inventario sólo muestra 5 KPIs — el desglose completo de unidades sigue vivo en la gráfica, no en las tarjetas', function () {
    sembrarRolesPermisos();
    $empresa = Empresa::factory()->create();
    $almacen = Almacen::factory()->paraEmpresa($empresa)->create();
    $activo = Activo::factory()->for($empresa)->seguimientoIndividual()->create();

    UnidadActivo::factory()->for($empresa, 'empresa')->for($activo)->for($almacen)->conCondicion(CondicionUnidadActivo::EnReparacion)->create();
    UnidadActivo::factory()->for($empresa, 'empresa')->for($activo)->for($almacen)->baja()->create();

    $admin = usuarioCon(RolSistema::Administrador->value, [$empresa]);

    $this->actingAs($admin)->get('/reportes?tab=inventario')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('kpis', 5)
            ->missing('kpis.Unidades en reparación')
            ->missing('kpis.Unidades perdidas')
            ->missing('kpis.Unidades robadas')
            ->missing('kpis.Unidades en baja')
            // La gráfica "Unidades por estado" sí sigue trayendo el desglose completo.
            ->has('graficas.unidades_por_estado', 6));
});
