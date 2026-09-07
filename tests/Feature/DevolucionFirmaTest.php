<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

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

    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
        [],
        [],
    );
    $this->detalle = $this->entrega->detalles->first();

    $this->devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $this->entrega->id,
        $this->datos['almacenA']->id,
        now()->toDateString(),
        [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable']],
        [],
        $this->admin->id,
    );

    $this->confirmar = app(ConfirmarAcuseDevolucion::class);
});

it('la devolución nace pendiente de firma', function () {
    expect($this->devolucion->estado)->toBe(EstadoDevolucion::PendienteFirma)
        ->and($this->devolucion->confirmada_en)->toBeNull();
});

it('crea el acuse con AMBAS firmas almacenadas de forma privada, huellas y snapshot, y aumenta el stock exactamente una vez (CASO 2)', function () {
    expect(SaldoInventario::first()->cantidad)->toBe(10); // pendiente de firma: el saldo aún no refleja la devolución

    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, '127.0.0.1', 'PHPUnit');

    expect(SaldoInventario::first()->cantidad)->toBe(13) // 10 + 3 confirmados
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Devolucion->value)->count())->toBe(1);

    expect($acuse->folio)->toStartWith('ACD-')
        ->and($acuse->hash_documento)->toHaveLength(64)
        ->and($acuse->hash_firma)->toHaveLength(64)
        ->and($acuse->hash_firma_operador)->toHaveLength(64)
        ->and($acuse->aceptacion_titular)->toBeTrue()
        ->and($acuse->texto_aceptado_snapshot)->not->toBeNull()
        ->and($acuse->snapshot_devolucion['items'][0]['activo'])->toBe('Camisa')
        ->and($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::Confirmada)
        ->and($this->devolucion->fresh()->confirmada_en)->not->toBeNull();

    Storage::disk('local')->assertExists($acuse->ruta_firma);
    Storage::disk('local')->assertExists($acuse->ruta_firma_operador);
    Storage::disk('local')->assertExists($acuse->ruta_pdf);
});

it('rechaza una firma vacía o inválida (colaborador o encargado)', function () {
    $this->confirmar->ejecutar($this->devolucion, 'data:image/png;base64,AAAA', firmaDemoBase64(), true, null, null, null);
})->throws(ExcepcionDeNegocioSimple::class);

it('rechaza confirmar sin la aceptación explícita de quien recibe, y el stock no cambia (CASO 7)', function () {
    try {
        $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), false, null, null, null);
    } catch (ExcepcionDeNegocioSimple) {
        // esperado
    }

    expect(SaldoInventario::first()->cantidad)->toBe(10);
});

it('no deja archivos de firma huérfanos si falla por falta de aceptación', function () {
    try {
        $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), false, null, null, null);
    } catch (ExcepcionDeNegocioSimple) {
        // esperado
    }

    Storage::disk('local')->assertDirectoryEmpty("firmas/{$this->devolucion->empresa_id}");
});

it('impide confirmar dos veces la misma devolución y nunca duplica el reingreso al inventario (CASO 3 y 9)', function () {
    $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    expect(SaldoInventario::first()->cantidad)->toBe(13);

    try {
        $this->confirmar->ejecutar($this->devolucion->fresh(), firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);
    } catch (ExcepcionDeNegocioSimple) {
        // esperado
    }

    expect(SaldoInventario::first()->cantidad)->toBe(13) // no se duplicó el reingreso
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Devolucion->value)->count())->toBe(1);
});

it('el snapshot del acuse no cambia aunque después se renombre el activo', function () {
    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    $this->datos['activoA']->update(['nombre' => 'Camisa Renombrada']);

    expect($acuse->fresh()->snapshot_devolucion['items'][0]['activo'])->toBe('Camisa');
});

it('otro colaborador no puede descargar el PDF de un acuse de devolución ajeno (IDOR)', function () {
    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    $intruso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($intruso)
        ->get("/acuses-devolucion/{$acuse->id}/pdf")
        ->assertForbidden();
});

it('un supervisor autorizado sí puede descargar el PDF de devolución', function () {
    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($supervisor)
        ->get("/acuses-devolucion/{$acuse->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('el titular con cuenta puede ver su propio comprobante de devolución', function () {
    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    $titular = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $this->datos['colaboradorA']->update(['usuario_id' => $titular->id]);

    $this->actingAs($titular)
        ->get("/acuses-devolucion/{$acuse->id}/pdf")
        ->assertOk();
});

it('un usuario de rol restringido de otra empresa recibe 403 al intentar ver el PDF de devolución', function () {
    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)
        ->get("/acuses-devolucion/{$acuse->id}/pdf")
        ->assertForbidden();
});

it('el endpoint HTTP rechaza confirmar sin la firma del encargado o sin la aceptación, y el stock no cambia (CASO 6)', function () {
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->post("/devoluciones/{$this->devolucion->id}/firmar", [
            'firma' => firmaDemoBase64(),
        ])
        ->assertSessionHasErrors(['firma_operador', 'aceptacion']);

    expect($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::PendienteFirma)
        ->and(SaldoInventario::first()->cantidad)->toBe(10);
});

it('el endpoint HTTP confirma la devolución con ambas firmas y la aceptación marcada', function () {
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->post("/devoluciones/{$this->devolucion->id}/firmar", [
            'firma' => firmaDemoBase64(),
            'firma_operador' => firmaDemoBase64(),
            'aceptacion' => true,
        ])
        ->assertRedirect();

    expect($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::Confirmada);
});

it('un rol restringido de otra empresa recibe 403 al intentar firmar la devolución (cross-company)', function () {
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)
        ->get("/devoluciones/{$this->devolucion->id}/firmar")
        ->assertForbidden();
});

it('al confirmar una devolución de unidad funcionando, la unidad vuelve al almacén, se libera el colaborador y vuelve a ser entregable (CASO 5)', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entregaUnidad = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entregaUnidad->detalles->first();

    $devolucionUnidad = app(RegistrarDevolucion::class)->ejecutar($entregaUnidad->id, $this->datos['almacenA']->id, now()->toDateString(), [], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando'],
    ], $this->admin->id);

    // Antes de firmar: sigue asignada, no disponible.
    expect($unidad->fresh()->estado)->toBe(EstadoUnidadActivo::Asignada);

    $this->confirmar->ejecutar($devolucionUnidad, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $unidad->refresh();
    expect($unidad->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($unidad->colaborador_id)->toBeNull()
        ->and($unidad->almacen_id)->toBe($this->datos['almacenA']->id)
        ->and($unidad->esEntregable())->toBeTrue();
});

it('al confirmar una devolución de unidad en reparación, la unidad vuelve al almacén pero NO queda entregable (CASO 5)', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entregaUnidad = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entregaUnidad->detalles->first();

    $devolucionUnidad = app(RegistrarDevolucion::class)->ejecutar($entregaUnidad->id, $this->datos['almacenA']->id, now()->toDateString(), [], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'en_reparacion'],
    ], $this->admin->id);

    $this->confirmar->ejecutar($devolucionUnidad, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null);

    $unidad->refresh();
    expect($unidad->estado)->toBe(EstadoUnidadActivo::EnAlmacen)
        ->and($unidad->colaborador_id)->toBeNull()
        ->and($unidad->esEntregable())->toBeFalse();
});

it('si una unidad de la devolución deja de estar asignada (p. ej. incidencia reportada mientras esperaba firma), la confirmación falla sin aplicar ningún movimiento parcial (CASO 8 y 11)', function () {
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->datos['activoA'])->for($this->datos['almacenA'])->create();

    // Una devolución con DOS renglones (cantidad + unidad) para probar que, si el
    // segundo ya no es coherente, el primero tampoco queda aplicado a medias.
    $entregaMixta = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        [['unidad_activo_id' => $unidad->id]],
        [],
    );
    $detalleCantidad = $entregaMixta->detalles->firstWhere('unidad_activo_id', null);
    $detalleUnidad = $entregaMixta->detalles->firstWhere('unidad_activo_id', $unidad->id);

    expect(SaldoInventario::first()->cantidad)->toBe(7); // 10 (tras $this->entrega) - 3 (entregaMixta)

    $devolucionMixta = app(RegistrarDevolucion::class)->ejecutar($entregaMixta->id, $this->datos['almacenA']->id, now()->toDateString(), [
        ['detalle_entrega_id' => $detalleCantidad->id, 'cantidad' => 3, 'condicion' => 'reutilizable'],
    ], [
        ['detalle_entrega_id' => $detalleUnidad->id, 'condicion' => 'funcionando'],
    ], $this->admin->id);

    // La unidad deja de estar asignada por otra vía (incidencia) mientras la devolución espera firma.
    $unidad->update(['estado' => EstadoUnidadActivo::Baja]);

    expect(fn () => $this->confirmar->ejecutar($devolucionMixta, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, null, null))
        ->toThrow(ExcepcionDeNegocioSimple::class);

    expect(SaldoInventario::first()->cantidad)->toBe(7) // la línea de cantidad NO se aplicó pese a existir en la misma devolución
        ->and($devolucionMixta->fresh()->estado)->toBe(EstadoDevolucion::PendienteFirma)
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Devolucion->value)->count())->toBe(0);

    Storage::disk('local')->assertDirectoryEmpty("firmas/{$devolucionMixta->empresa_id}");
});
