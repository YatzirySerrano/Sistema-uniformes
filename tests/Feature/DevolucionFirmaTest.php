<?php

use App\Acciones\ConfirmarAcuseDevolucion;
use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Enums\EstadoDevolucion;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
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

it('crea el acuse con AMBAS firmas almacenadas de forma privada, huellas y snapshot', function () {
    $acuse = $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, $this->admin->id, '127.0.0.1', 'PHPUnit');

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

it('rechaza confirmar sin la aceptación explícita de quien recibe', function () {
    $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), false, null, null, null);
})->throws(ExcepcionDeNegocioSimple::class);

it('no deja archivos de firma huérfanos si falla por falta de aceptación', function () {
    try {
        $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), false, null, null, null);
    } catch (ExcepcionDeNegocioSimple) {
        // esperado
    }

    Storage::disk('local')->assertDirectoryEmpty("firmas/{$this->devolucion->empresa_id}");
});

it('impide confirmar dos veces la misma devolución', function () {
    $this->confirmar->ejecutar($this->devolucion, firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);

    $this->confirmar->ejecutar($this->devolucion->fresh(), firmaDemoBase64(), firmaDemoBase64(), true, null, null, null);
})->throws(ExcepcionDeNegocioSimple::class);

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

it('el endpoint HTTP rechaza confirmar sin la firma del encargado o sin la aceptación', function () {
    $operador = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($operador)
        ->post("/devoluciones/{$this->devolucion->id}/firmar", [
            'firma' => firmaDemoBase64(),
        ])
        ->assertSessionHasErrors(['firma_operador', 'aceptacion']);

    expect($this->devolucion->fresh()->estado)->toBe(EstadoDevolucion::PendienteFirma);
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
