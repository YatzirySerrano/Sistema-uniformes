<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\CategoriaActivoPerfilTecnico;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use App\Soporte\FechaHora;
use Illuminate\Support\Facades\Storage;

/**
 * PDF de entrega y devolución: separa "activos por cantidad" de "unidades de
 * seguimiento individual" en dos tablas condicionales, mostrando el código y
 * los datos técnicos (marca/modelo/IMEI/teléfono/operador para Celular; sólo
 * marca/modelo para Computadora/Tablet — el sistema no registra número de
 * serie ni MAC, ver nota final del reporte) vigentes al momento de firmar.
 * Se prueba renderizando la plantilla Blade directamente con el snapshot real
 * de un acuse confirmado, que es justo lo que el PDF usa.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $this->activoConPerfil = function (string $perfil): Activo {
        $cat = CategoriaActivo::factory()->create();
        CategoriaActivoPerfilTecnico::query()->create([
            'categoria_activo_id' => $cat->id,
            'perfil' => strtolower($perfil),
        ]);

        return Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create([
            'categoria_id' => $cat->id,
        ]);
    };

    $this->crearUnidad = fn (Activo $activo, array $especificacion) => app(RegistrarUnidadesActivo::class)->ejecutar(
        empresa: $this->datos['empresaA'],
        activo: $activo,
        almacen: $this->datos['almacenA'],
        cantidad: 1,
        motivo: 'Alta de prueba',
        realizadoPor: $this->admin->id,
        especificaciones: [$especificacion],
    )->first();

    $this->renderEntrega = fn ($acuse) => view('acuses.comprobante', [
        'acuse' => $acuse,
        'snapshot' => $acuse->snapshot_entrega,
        'firmaDataUri' => null,
        'firmaOperadorDataUri' => null,
        'logoDataUri' => null,
        'evidenciasPorItem' => [],
    ])->render();

    $this->renderDevolucion = fn ($acuse) => view('acuses.comprobante-devolucion', [
        'acuse' => $acuse,
        'snapshot' => $acuse->snapshot_devolucion,
        'firmaDataUri' => null,
        'firmaOperadorDataUri' => null,
        'logoDataUri' => null,
        'evidenciasPorItem' => [],
    ])->render();
});

it('1. PDF de entrega sólo cantidad: tabla de cantidad existe, tabla de unidades no', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain('Activos por cantidad')
        ->not->toContain('Unidades de seguimiento individual');
});

it('2. PDF de entrega sólo una unidad individual: tabla de unidades existe, tabla de cantidad no, y muestra el código', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, [
        'marca' => 'Honor', 'modelo' => 'X5C Midnight Black', 'imei' => '111122223333444',
        'numero_telefonico' => '7771234567', 'operador' => 'Telcel',
    ]);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain('Unidades de seguimiento individual')
        ->not->toContain('Activos por cantidad');
    expect($html)->toContain($unidad->fresh()->codigo)
        ->toContain('Marca: Honor')
        ->toContain('Modelo: X5C Midnight Black')
        ->toContain('IMEI: 111122223333444')
        ->toContain('Número telefónico: 7771234567')
        ->toContain('Operador: Telcel');
});

it('3. entrega mixta (cantidad + individual): ambas tablas existen', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Samsung', 'modelo' => 'A04', 'imei' => '999988887777666']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        [['unidad_activo_id' => $unidad->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain('Activos por cantidad')
        ->toContain('Unidades de seguimiento individual')
        ->toContain($unidad->fresh()->codigo);
});

it('4. computadora: sólo muestra marca/modelo (el sistema no registra serie ni MAC)', function () {
    $activo = ($this->activoConPerfil)('computadora');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Dell', 'modelo' => 'Latitude 5420']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain('Marca: Dell')
        ->toContain('Modelo: Latitude 5420')
        ->not->toContain('IMEI')
        ->not->toContain('Número telefónico');
});

it('5. campos vacíos de la unidad no imprimen etiqueta ni fila', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Motorola', 'modelo' => 'G23', 'imei' => '555566667777888']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain('IMEI: 555566667777888')
        ->not->toContain('Operador:')
        ->not->toContain('Número telefónico:')
        ->not->toContain('Plan:');
});

it('6. varias unidades del mismo activo se identifican por su propio código/IMEI', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad1 = ($this->crearUnidad)($activo, ['marca' => 'Honor', 'modelo' => 'X5C', 'imei' => '100000000000001']);
    $unidad2 = ($this->crearUnidad)($activo, ['marca' => 'Honor', 'modelo' => 'X5C', 'imei' => '100000000000002']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad1->id], ['unidad_activo_id' => $unidad2->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain($unidad1->fresh()->codigo)
        ->toContain($unidad2->fresh()->codigo)
        ->toContain('IMEI: 100000000000001')
        ->toContain('IMEI: 100000000000002');
});

it('7. genera el PDF real (dompdf) de una entrega mixta sin lanzar y sin N+1 evidente', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Honor', 'modelo' => 'X5C', 'imei' => '100000000000009']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [['unidad_activo_id' => $unidad->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $this->actingAs($this->admin)
        ->get("/acuses/{$acuse->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

// --- Devolución ---

it('8. PDF de devolución sólo cantidad: tabla de cantidad existe, tabla de unidades no', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        [], [],
    );
    $detalle = $entrega->detalles->first();

    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable']],
        [], $this->admin->id,
    );

    $acuse = app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderDevolucion)($acuse);

    expect($html)->toContain('Activos devueltos por cantidad')
        ->not->toContain('Unidades individuales devueltas');
});

it('9. PDF de devolución sólo una unidad individual: tabla de unidades existe, tabla de cantidad no', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Honor', 'modelo' => 'X5C', 'imei' => '200000000000001']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entrega->detalles->first();

    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id, $this->datos['almacenA']->id, now()->toDateString(), [],
        [['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando']],
        $this->admin->id,
    );

    $acuse = app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderDevolucion)($acuse);

    expect($html)->toContain('Unidades individuales devueltas')
        ->not->toContain('Activos devueltos por cantidad');
    expect($html)->toContain($unidad->fresh()->codigo)
        ->toContain('IMEI: 200000000000001');
});

it('10. devolución mixta: ambas tablas existen y la condición sigue visible', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Honor', 'modelo' => 'X5C', 'imei' => '300000000000001']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 4]],
        [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleCantidad = $entrega->detalles->firstWhere('unidad_activo_id', null);
    $detalleUnidad = $entrega->detalles->firstWhere('unidad_activo_id', '!=', null);

    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $detalleCantidad->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
        [['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando']],
        $this->admin->id,
    );

    $acuse = app(ConfirmarAcuseDevolucion::class)->ejecutar($devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderDevolucion)($acuse);

    expect($html)->toContain('Activos devueltos por cantidad')
        ->toContain('Unidades individuales devueltas')
        ->toContain($unidad->fresh()->codigo)
        ->toContain('Funcionando'); // etiqueta de CondicionUnidadActivo::Funcionando en la columna Condición
});

it('11. no cambia la fecha/hora ni las huellas de firma del acuse al agregar el bloque de unidades', function () {
    $activo = ($this->activoConPerfil)('celular');
    $unidad = ($this->crearUnidad)($activo, ['marca' => 'Honor', 'modelo' => 'X5C', 'imei' => '400000000000001']);

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );

    $acuse = app(ConfirmarAcuseRecepcion::class)->ejecutar($entrega, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $html = ($this->renderEntrega)($acuse);

    expect($html)->toContain(FechaHora::local($acuse->firmado_en))
        ->toContain($acuse->hash_documento)
        ->toContain($acuse->hash_firma);
});
