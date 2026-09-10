<?php

use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Acciones\VerificarExistenciaInventarioFisico;
use App\Enums\EstadoInventarioFisico;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\InventarioFisicoFirma;
use App\Models\InventarioFisicoUnidad;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

/**
 * Inventario físico — comprobación MANUAL de artículos por cantidad + firma de
 * cierre + inmutabilidad de la ronda finalizada. El módulo sólo COMPARA: nunca
 * ajusta `saldos_inventario`.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Cuernavaca']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->camisa = Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisa']);
    $this->m = Talla::factory()->create(['valor' => 'M']);
    $this->s = Talla::factory()->create(['valor' => 'S']);
    $this->camisa->tallas()->attach([$this->m->id, $this->s->id]);

    $inv = app(ServicioInventario::class);
    $inv->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->m->id, tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));
    $inv->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->s->id, tipo: TipoMovimiento::Inicial, cantidad: 15,
    ));

    $this->ronda = fn (): InventarioFisico => app(CrearRondaInventarioFisico::class)->ejecutar(
        $this->empresa, 'Ronda', $this->almacen, null, $this->admin->id,
    );
    $this->verificar = fn (InventarioFisico $r, InventarioFisicoExistencia $e, int $contada) => $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$r->id}/existencias/{$e->id}", ['cantidad_contada' => $contada]);
});

it('la ronda nueva exige almacén', function () {
    $this->actingAs($this->admin)
        ->post('/inventarios-fisicos', ['empresa_id' => $this->empresa->id, 'nombre' => 'X'])
        ->assertSessionHasErrors('almacen_id');
});

it('al iniciar la ronda se congela una fila por (activo, talla) con saldo > 0', function () {
    $ronda = ($this->ronda)();

    $filas = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get();
    expect($filas)->toHaveCount(2)
        ->and($filas->firstWhere('talla_id', $this->m->id)->cantidad_esperada)->toBe(20)
        ->and($filas->firstWhere('talla_id', $this->s->id)->cantidad_esperada)->toBe(15)
        ->and($filas->pluck('cantidad_contada')->filter())->toHaveCount(0);
});

it('«Coincide» fija contada = esperada; una cantidad distinta calcula la diferencia; el stock nunca cambia', function () {
    $ronda = ($this->ronda)();
    $filaM = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->where('talla_id', $this->m->id)->firstOrFail();
    $filaS = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->where('talla_id', $this->s->id)->firstOrFail();

    ($this->verificar)($ronda, $filaM, 20)->assertOk()->assertJsonPath('existencia.resultado', 'coincide');
    ($this->verificar)($ronda, $filaS, 14)->assertOk()
        ->assertJsonPath('existencia.resultado', 'faltante')
        ->assertJsonPath('existencia.diferencia', -1);

    expect($filaM->fresh()->cantidad_contada)->toBe(20)
        ->and($filaM->fresh()->verificada_por)->toBe($this->admin->id)
        ->and($filaS->fresh()->diferencia())->toBe(-1)
        // El saldo real NO se toca.
        ->and(SaldoInventario::query()->where('talla_id', $this->m->id)->value('cantidad'))->toBe(20)
        ->and(SaldoInventario::query()->where('talla_id', $this->s->id)->value('cantidad'))->toBe(15);

    // 21 → sobrante.
    ($this->verificar)($ronda, $filaS->fresh(), 21)->assertOk()->assertJsonPath('existencia.resultado', 'sobrante');
});

it('el snapshot de cantidades no se recalcula si el stock cambia después', function () {
    $ronda = ($this->ronda)();

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->m->id, tipo: TipoMovimiento::AjusteSalida, cantidad: 5,
    ));

    expect(InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->where('talla_id', $this->m->id)->value('cantidad_esperada'))->toBe(20);
});

it('no se puede finalizar mientras queden renglones de cantidad sin verificar', function () {
    $ronda = ($this->ronda)();

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64(), 'aceptacion' => true])
        ->assertSessionHasErrors('negocio');

    expect($ronda->fresh()->estado)->toBe(EstadoInventarioFisico::EnProceso);
});

it('finalizar exige firma y aceptación, y con ambas cierra la ronda con InventarioFisicoFirma', function () {
    $ronda = ($this->ronda)();
    foreach (InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get() as $fila) {
        ($this->verificar)($ronda, $fila, $fila->cantidad_esperada)->assertOk();
    }

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['aceptacion' => true])->assertSessionHasErrors('firma');
    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64()])->assertSessionHasErrors('aceptacion');

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64(), 'aceptacion' => true])
        ->assertRedirect();

    $ronda->refresh();
    expect($ronda->estado)->toBe(EstadoInventarioFisico::Finalizado)
        ->and($ronda->finalizado_en)->not->toBeNull();

    $firma = InventarioFisicoFirma::query()->where('inventario_fisico_id', $ronda->id)->firstOrFail();
    expect($firma->hash_firma)->toHaveLength(64)
        ->and($firma->nombre_firmante)->toBe($this->admin->name)
        ->and($firma->texto_aceptado)->toBe(FinalizarRondaInventarioFisico::TEXTO_ACEPTACION);
    Storage::disk('local')->assertExists($firma->ruta_firma);
});

it('una ronda FINALIZADA es inmutable: rechaza verificar cantidad, marcar presente, escanear y re-finalizar', function () {
    // Una unidad QR esperada (para la marca "Presente") + las 2 filas de cantidad.
    $activoU = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->empresa)->for($activoU)->for($this->almacen)->create();

    $ronda = ($this->ronda)();
    $renglonUnidad = InventarioFisicoUnidad::query()->where('inventario_fisico_id', $ronda->id)->where('unidad_activo_id', $unidad->id)->firstOrFail();

    foreach (InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get() as $f) {
        ($this->verificar)($ronda, $f, $f->cantidad_esperada)->assertOk();
    }
    $fila = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->firstOrFail();
    $contadaOriginal = $fila->fresh()->cantidad_contada;

    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64(), 'aceptacion' => true])->assertRedirect();

    ($this->verificar)($ronda, $fila, 999)->assertStatus(422);
    $this->actingAs($this->admin)->postJson("/inventarios-fisicos/{$ronda->id}/unidades/{$renglonUnidad->id}/presente")->assertStatus(422);
    $this->actingAs($this->admin)->postJson("/inventarios-fisicos/{$ronda->id}/escanear", ['codigo' => $unidad->public_token])->assertStatus(422);
    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64(), 'aceptacion' => true])->assertSessionHasErrors('negocio');

    expect($fila->fresh()->cantidad_contada)->toBe($contadaOriginal)
        ->and($renglonUnidad->fresh()->escaneado_en)->toBeNull()
        ->and(InventarioFisicoFirma::query()->where('inventario_fisico_id', $ronda->id)->count())->toBe(1);
});

it('la acción de verificación rechaza una ronda finalizada aunque se le pase directamente', function () {
    $ronda = ($this->ronda)();
    foreach (InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get() as $f) {
        ($this->verificar)($ronda, $f, $f->cantidad_esperada)->assertOk();
    }
    $this->actingAs($this->admin)->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64(), 'aceptacion' => true])->assertRedirect();

    $fila = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->firstOrFail();

    expect(fn () => app(VerificarExistenciaInventarioFisico::class)->ejecutar($ronda->fresh(), $fila, 1, $this->admin))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('el export ?tipo=cantidad trae Activo/Talla/Esperado/Contado/Diferencia/Resultado', function () {
    $ronda = ($this->ronda)();
    $filaM = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->where('talla_id', $this->m->id)->firstOrFail();
    ($this->verificar)($ronda, $filaM, 19)->assertOk();

    $respuesta = $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}/exportar?tipo=cantidad&formato=pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(substr($respuesta->getContent(), 0, 4))->toBe('%PDF');
});

it('un rol sólo lector (Encargado) no puede verificar cantidades ni finalizar', function () {
    $ronda = ($this->ronda)();
    $fila = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->firstOrFail();
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);

    $this->actingAs($encargado)->postJson("/inventarios-fisicos/{$ronda->id}/existencias/{$fila->id}", ['cantidad_contada' => 1])->assertForbidden();
    $this->actingAs($encargado)->post("/inventarios-fisicos/{$ronda->id}/finalizar", ['firma' => firmaDemoBase64(), 'aceptacion' => true])->assertForbidden();
});
