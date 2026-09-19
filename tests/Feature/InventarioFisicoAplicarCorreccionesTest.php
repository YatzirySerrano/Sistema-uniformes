<?php

use App\Acciones\AplicarCorreccionesInventarioFisico;
use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Acciones\VerificarExistenciaInventarioFisico;
use App\Enums\EstadoInventarioFisico;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\DiferenciasInventarioFisicoDesactualizadasException;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

/**
 * Aplicación MASIVA y CONTROLADA de las diferencias de una ronda de
 * inventario físico ya finalizada y firmada: TODO o NADA, nunca antes de
 * firmar, nunca automática, nunca dos veces, y siempre revalidando bajo lock
 * que el inventario no cambió desde el snapshot de la ronda.
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

    $this->pantalon = Activo::factory()->for($this->empresa)->create(['nombre' => 'Pantalón']);

    $inv = app(ServicioInventario::class);
    $inv->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->m->id, tipo: TipoMovimiento::Inicial, cantidad: 10,
    ));
    $inv->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->s->id, tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));
    $inv->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->pantalon->id, tallaId: null, tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));

    // Crea una ronda, verifica cada renglón con el conteo indicado (por
    // defecto = esperada, es decir "coincide") y la finaliza firmada.
    // `$conteos` se indexa por "activo_id-talla_id" ('null' sin variante).
    $this->crearYFirmar = function (array $conteos = []): InventarioFisico {
        $ronda = app(CrearRondaInventarioFisico::class)->ejecutar(
            $this->empresa, 'Ronda', $this->almacen, null, $this->admin->id,
        );

        foreach (InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get() as $fila) {
            $clave = $fila->activo_id.'-'.($fila->talla_id ?? 'null');
            $contada = $conteos[$clave] ?? $fila->cantidad_esperada;
            app(VerificarExistenciaInventarioFisico::class)->ejecutar($ronda, $fila, $contada, $this->admin);
        }

        app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

        return $ronda->fresh();
    };

    $this->claveCamisaM = $this->camisa->id.'-'.$this->m->id;
    $this->claveCamisaS = $this->camisa->id.'-'.$this->s->id;
    $this->clavePantalon = $this->pantalon->id.'-null';
});

it('1. una ronda EnProceso no puede aplicar correcciones', function () {
    $ronda = app(CrearRondaInventarioFisico::class)->ejecutar($this->empresa, 'Ronda', $this->almacen, null, $this->admin->id);

    expect(fn () => app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin))
        ->toThrow(ExcepcionDeNegocioSimple::class);

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertSessionHasErrors('negocio');

    expect($ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

it('2. una ronda Finalizada sin firma no puede aplicar correcciones', function () {
    $ronda = app(CrearRondaInventarioFisico::class)->ejecutar($this->empresa, 'Ronda', $this->almacen, null, $this->admin->id);
    foreach (InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get() as $f) {
        app(VerificarExistenciaInventarioFisico::class)->ejecutar($ronda, $f, $f->cantidad_esperada, $this->admin);
    }
    // Estado forzado sin pasar por FinalizarRondaInventarioFisico (que crea
    // la firma atómicamente) — comprueba la defensa propia de la Acción.
    $ronda->update(['estado' => EstadoInventarioFisico::Finalizado, 'finalizado_en' => now()]);

    expect(fn () => app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda->fresh(), $this->admin))
        ->toThrow(ExcepcionDeNegocioSimple::class);
});

it('3-4. ronda finalizada y firmada con 2 diferencias: las aplica ambas y dispara el flujo HTTP completo', function () {
    $ronda = ($this->crearYFirmar)([
        $this->claveCamisaM => 8,  // faltante: 10 -> 8
        $this->clavePantalon => 8, // sobrante: 5 -> 8
        // Camisa S sin entrada => coincide (20 -> 20)
    ]);

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $ronda->refresh();
    expect($ronda->correcciones_aplicadas_en)->not->toBeNull()
        ->and($ronda->correcciones_aplicadas_por)->toBe($this->admin->id)
        ->and(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))->toBe(8)
        ->and(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->value('cantidad'))->toBe(8)
        // Camisa S nunca tuvo diferencia: su saldo no se toca.
        ->and(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->s->id)->value('cantidad'))->toBe(20);
});

it('5. un renglón sin diferencia (coincide) no genera ningún movimiento', function () {
    $ronda = ($this->crearYFirmar)([
        $this->claveCamisaM => 8,
        // Camisa S y Pantalón: coinciden.
    ]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    expect(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->camisa->id)->where('talla_id', $this->s->id)->exists())->toBeFalse()
        ->and(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->pantalon->id)->exists())->toBeFalse()
        ->and(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->exists())->toBeTrue();
});

it('6. faltante: 10 -> 8 queda como ajuste negativo', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    expect(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))->toBe(8);

    $mov = MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->firstOrFail();
    expect($mov->tipo)->toBe(TipoMovimiento::AjusteSalida)
        ->and($mov->cantidad)->toBe(2)
        ->and($mov->existencia_anterior)->toBe(10)
        ->and($mov->existencia_resultante)->toBe(8);
});

it('7. sobrante: 5 -> 8 queda como ajuste positivo', function () {
    $ronda = ($this->crearYFirmar)([$this->clavePantalon => 8]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    expect(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->value('cantidad'))->toBe(8);

    $mov = MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->pantalon->id)->firstOrFail();
    expect($mov->tipo)->toBe(TipoMovimiento::AjusteEntrada)
        ->and($mov->cantidad)->toBe(3)
        ->and($mov->existencia_anterior)->toBe(5)
        ->and($mov->existencia_resultante)->toBe(8);
});

it('8. conteo en cero es una corrección válida: 5 -> 0', function () {
    $ronda = ($this->crearYFirmar)([$this->clavePantalon => 0]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    expect(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->value('cantidad'))->toBe(0);

    $mov = MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->pantalon->id)->firstOrFail();
    expect($mov->tipo)->toBe(TipoMovimiento::AjusteSalida)
        ->and($mov->cantidad)->toBe(5)
        ->and($mov->existencia_resultante)->toBe(0);
});

it('9. los movimientos generados referencian la ronda origen y el motivo nombra su folio', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    $mov = MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->firstOrFail();
    expect($mov->referencia_tipo)->toBe(InventarioFisico::class)
        ->and($mov->referencia_id)->toBe($ronda->id)
        ->and($mov->motivo)->toContain($ronda->folio)
        ->and($mov->realizado_por)->toBe($this->admin->id)
        ->and($mov->empresa_id)->toBe($this->empresa->id)
        ->and($mov->almacen_id)->toBe($this->almacen->id);
});

it('10. deja un registro de auditoría del lote con la ronda origen', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8, $this->clavePantalon => 8]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    $registro = BitacoraAuditoria::query()->where('modulo', 'inventario_fisico')->where('accion', 'correcciones_aplicar')->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->entidad_id)->toBe($ronda->id)
        ->and($registro->descripcion)->toContain($ronda->folio)
        ->and($registro->descripcion)->toContain('2');
});

it('11. persiste quién y cuándo se aplicaron las correcciones', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);

    // Margen de un segundo: la columna `timestamp` no conserva microsegundos,
    // así que comparar contra un `now()` capturado al vuelo puede quedar
    // unos milisegundos "por delante" del valor ya truncado en BD.
    $antes = now()->subSecond();
    $resultado = app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    expect($resultado->correcciones_aplicadas_por)->toBe($this->admin->id)
        ->and($resultado->correcciones_aplicadas_en)->not->toBeNull()
        ->and($resultado->correcciones_aplicadas_en->greaterThanOrEqualTo($antes))->toBeTrue();
});

it('12. una segunda aplicación de la misma ronda es rechazada', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);
    $saldoTrasPrimeraAplicacion = SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad');

    expect(fn () => app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda->fresh(), $this->admin))
        ->toThrow(ExcepcionDeNegocioSimple::class);

    // El segundo intento NO vuelve a descontar/sumar nada.
    expect(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))
        ->toBe($saldoTrasPrimeraAplicacion)
        ->and(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('referencia_id', $ronda->id)->count())->toBe(1);

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertSessionHasErrors('negocio');
});

it('13. si una existencia cambió después del snapshot, se aborta TODO el lote', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8, $this->clavePantalon => 8]);

    // Un movimiento legítimo posterior al snapshot cambia el saldo real.
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->m->id, tipo: TipoMovimiento::Entrada, cantidad: 5,
    ));
    // Ahora el saldo real es 15, pero la ronda esperaba 10.

    expect(fn () => app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda->fresh(), $this->admin))
        ->toThrow(DiferenciasInventarioFisicoDesactualizadasException::class);

    // NINGUNA corrección se aplicó — ni siquiera la del pantalón, que sí
    // seguía coincidiendo con su snapshot.
    expect($ronda->fresh()->correcciones_aplicadas_en)->toBeNull()
        ->and(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))->toBe(15)
        ->and(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->value('cantidad'))->toBe(5)
        ->and(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->where('referencia_id', $ronda->id)->count())->toBe(0);
});

it('14. con 3 diferencias, si la segunda entra en conflicto, ninguna de las otras dos se aplica', function () {
    $ronda = ($this->crearYFirmar)([
        $this->claveCamisaM => 8,  // seguirá coincidiendo con el saldo real
        $this->claveCamisaS => 25, // ESTA entrará en conflicto
        $this->clavePantalon => 8, // seguirá coincidiendo con el saldo real
    ]);

    // Cambia el saldo real de Camisa S DESPUÉS del snapshot.
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->s->id, tipo: TipoMovimiento::Entrada, cantidad: 1,
    ));

    try {
        app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda->fresh(), $this->admin);
        $this->fail('Debió lanzar DiferenciasInventarioFisicoDesactualizadasException.');
    } catch (DiferenciasInventarioFisicoDesactualizadasException $e) {
        expect($e->conflictos)->toHaveCount(1)
            ->and($e->conflictos[0]['esperada'])->toBe(20)
            ->and($e->conflictos[0]['actual'])->toBe(21);
    }

    expect(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))->toBe(10)
        ->and(SaldoInventario::query()->where('activo_id', $this->pantalon->id)->value('cantidad'))->toBe(5)
        ->and($ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

it('15. un usuario sin permiso inventario.ajustar es rechazado', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);
    // Supervisor tiene inventario-fisico.administrar (puede finalizar), pero
    // NO inventario.ajustar (deliberado: separación de responsabilidades).
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->empresa]);

    $this->actingAs($supervisor)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertForbidden();

    expect($ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

it('16. un usuario con el permiso pero sin acceso a la empresa de la ronda es rechazado', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);
    $otraEmpresa = Empresa::factory()->create();
    // Administrador/Superadministrador tienen alcance GLOBAL por diseño (ver
    // `.ai/rules/policies.md`): para probar el aislamiento por empresa hace
    // falta un usuario con el permiso concedido directamente (sin uno de
    // esos dos roles) y asignado sólo a otra empresa.
    $ajeno = User::factory()->create();
    $ajeno->givePermissionTo(['inventario.ajustar', 'inventario-fisico.ver']);
    $ajeno->empresas()->sync([$otraEmpresa->id]);

    $this->actingAs($ajeno)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertForbidden();
});

it('17. un almacén desactivado (fuera del alcance autorizado) rechaza la aplicación', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);
    $this->almacen->update(['activo' => false]);

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertForbidden();

    expect($ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

it('18. una excepción durante la aplicación revierte TODO (rollback completo)', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8, $this->clavePantalon => 8]);

    // Fuerza un conflicto en el SEGUNDO renglón procesado (orden por
    // activo_id, así que Pantalón cae después de Camisa).
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->pantalon->id, tallaId: null, tipo: TipoMovimiento::Entrada, cantidad: 100,
    ));

    expect(fn () => app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda->fresh(), $this->admin))
        ->toThrow(DiferenciasInventarioFisicoDesactualizadasException::class);

    // La corrección de Camisa M (que sí coincidía) NUNCA se escribió: todo
    // el lote se revirtió, incluida la que se hubiera aplicado primero.
    expect(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))->toBe(10)
        ->and(MovimientoInventario::query()->where('referencia_tipo', InventarioFisico::class)->count())->toBe(0)
        ->and($ronda->fresh()->correcciones_aplicadas_en)->toBeNull();
});

it('19. el snapshot (cantidad_esperada/cantidad_contada) no se modifica al aplicar', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);
    $fila = InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)
        ->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->firstOrFail();

    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    expect($fila->fresh()->cantidad_esperada)->toBe(10)
        ->and($fila->fresh()->cantidad_contada)->toBe(8);
});

it('20. finalizar/firmar sigue funcionando SIN aplicar correcciones (la firma nunca aplica inventario)', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);

    expect($ronda->estado)->toBe(EstadoInventarioFisico::Finalizado)
        ->and($ronda->firma)->not->toBeNull()
        ->and($ronda->correcciones_aplicadas_en)->toBeNull()
        // La firma NO tocó el saldo: sigue en 10, la diferencia sigue sólo
        // como snapshot comparativo hasta que alguien decida aplicarla.
        ->and(SaldoInventario::query()->where('activo_id', $this->camisa->id)->where('talla_id', $this->m->id)->value('cantidad'))->toBe(10);
});

it('sin diferencias en la ronda: no hay nada por aplicar', function () {
    $ronda = ($this->crearYFirmar)(); // todo coincide

    expect(fn () => app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin))
        ->toThrow(ExcepcionDeNegocioSimple::class);

    $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($page) => $page
            ->where('correcciones.estado', 'sin_diferencias')
            ->where('permisos.aplicarCorrecciones', false)
        );
});

it('el detalle de la ronda expone el panel de correcciones pendientes con el permiso correcto', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);

    $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($page) => $page
            ->where('correcciones.estado', 'pendientes')
            ->where('correcciones.total_diferencias', 1)
            ->where('permisos.aplicarCorrecciones', true)
        );
});

it('tras aplicar, el detalle muestra "aplicadas" con fecha y usuario, y ya no permite reintentar', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8, $this->clavePantalon => 8]);
    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda, $this->admin);

    $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($page) => $page
            ->where('correcciones.estado', 'aplicadas')
            ->where('correcciones.total_aplicadas', 2)
            ->where('correcciones.aplicadas_por', $this->admin->name)
            ->where('permisos.aplicarCorrecciones', false)
        );
});

it('un conflicto de concurrencia queda visible en la siguiente carga de la pantalla', function () {
    $ronda = ($this->crearYFirmar)([$this->claveCamisaM => 8]);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->m->id, tipo: TipoMovimiento::Entrada, cantidad: 1,
    ));

    $this->actingAs($this->admin)
        ->post("/inventarios-fisicos/{$ronda->id}/aplicar-correcciones")
        ->assertSessionHasErrors('negocio');

    $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}")
        ->assertInertia(fn ($page) => $page
            ->has('conflictosInventarioFisico', 1)
            ->where('conflictosInventarioFisico.0.esperada', 10)
            ->where('conflictosInventarioFisico.0.actual', 11)
            ->where('correcciones.estado', 'pendientes')
        );
});
