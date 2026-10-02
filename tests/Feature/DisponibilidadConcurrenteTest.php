<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Almacen;
use App\Models\Conjunto;
use App\Models\ConjuntoComponente;
use App\Models\MovimientoInventario;
use App\Models\Reserva;
use App\Models\SaldoInventario;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lectura periódica de disponibilidad entre dos sesiones concurrentes (QA
 * 2026-10): lo que el usuario B ve SIN recargar la página debe reflejar lo
 * que A apartó, sin descontarse su propio apartado, sin renovar ni alterar
 * reservas, y los rechazos deben traer los números reales del backend.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->usuarioA = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->usuarioB = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 10,
    ));

    $this->fila = fn (int $cantidad, ?int $tallaId = null): array => [
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $tallaId ?? $this->datos['tallaA']->id, 'cantidad' => $cantidad,
    ];
    $this->reservar = fn ($usuario, string $token, array $activos, array $conjuntos = []) => $this->actingAs($usuario)->postJson('/entregas/reserva', [
        'token' => $token, 'empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => $this->datos['almacenA']->id,
        'colaborador_id' => $this->datos['colaboradorA']->id, 'activos' => $activos, 'unidades' => [], 'conjuntos' => $conjuntos,
    ]);
    /** Lo que el formulario de un usuario lee en cada refresco (con su token y los activos en pantalla). */
    $this->disponibleVisto = function ($usuario, ?string $token = null): int {
        $saldos = $this->actingAs($usuario)->getJson('/entregas/disponibilidad?'.http_build_query(array_filter([
            'empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => $this->datos['almacenA']->id,
            'token' => $token, 'activo_ids' => [$this->datos['activoA']->id],
        ])))->assertOk()->json('saldos');

        return (int) collect($saldos)->first(fn (array $s): bool => $s['activo_id'] === $this->datos['activoA']->id && $s['talla_id'] === $this->datos['tallaA']->id)['disponible'];
    };
});

it('lo que B ve sigue a lo que A aparta: 10 → 9 → 1 → 0, y vuelve a 10 al liberar', function () {
    $tokenA = (string) Str::uuid();

    expect(($this->disponibleVisto)($this->usuarioB))->toBe(10);

    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(1)])->assertOk()->assertJsonPath('ok', true);
    expect(($this->disponibleVisto)($this->usuarioB))->toBe(9);

    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(9)])->assertOk()->assertJsonPath('ok', true);
    expect(($this->disponibleVisto)($this->usuarioB))->toBe(1);

    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(10)])->assertOk()->assertJsonPath('ok', true);
    expect(($this->disponibleVisto)($this->usuarioB))->toBe(0);

    $this->actingAs($this->usuarioA)->deleteJson("/entregas/reserva/{$tokenA}")->assertOk();
    expect(($this->disponibleVisto)($this->usuarioB))->toBe(10);
});

it('el propio apartado no se descuenta a sí mismo', function () {
    $tokenA = (string) Str::uuid();
    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(3)])->assertOk();

    expect(($this->disponibleVisto)($this->usuarioA, $tokenA))->toBe(10)
        ->and(($this->disponibleVisto)($this->usuarioB))->toBe(7);
});

it('una reserva vencida no resta y la disponibilidad nunca es negativa', function () {
    $tokenA = (string) Str::uuid();
    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(10)])->assertOk();
    Reserva::query()->where('token', $tokenA)->update(['expira_en' => now()->subMinute()]);

    expect(($this->disponibleVisto)($this->usuarioB))->toBe(10);

    // El stock real baja por debajo de lo apartado (operación legítima con
    // el apartado de A vigente): lo visible se queda en 0, nunca negativo.
    $tokenA2 = (string) Str::uuid();
    ($this->reservar)($this->usuarioA, $tokenA2, [($this->fila)(8)])->assertOk();
    SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->update(['cantidad' => 5]);

    expect(($this->disponibleVisto)($this->usuarioB))->toBe(0);
});

it('consultar disponibilidad es una lectura pura: no crea, renueva ni cambia apartados, stock ni movimientos', function () {
    $tokenA = (string) Str::uuid();
    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(4)])->assertOk();
    $this->travel(5)->minutes();
    $antes = Reserva::query()->where('token', $tokenA)->firstOrFail();
    $movimientos = MovimientoInventario::query()->count();

    foreach (range(1, 3) as $_) {
        ($this->disponibleVisto)($this->usuarioA, $tokenA);
        ($this->disponibleVisto)($this->usuarioB, (string) Str::uuid());
    }

    $despues = Reserva::query()->where('token', $tokenA)->firstOrFail();
    expect(Reserva::query()->count())->toBe(1)
        ->and($despues->expira_en->equalTo($antes->expira_en))->toBeTrue()
        ->and($despues->renglones()->sum('cantidad'))->toBe(4)
        ->and(MovimientoInventario::query()->count())->toBe($movimientos)
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(10);
});

it('la consulta periódica no hace una consulta por renglón (batch)', function () {
    foreach (range(1, 15) as $_) {
        $talla = Talla::factory()->create();
        app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
            activoId: $this->datos['activoA']->id, tallaId: $talla->id, tipo: TipoMovimiento::Inicial, cantidad: 2,
        ));
    }
    $this->actingAs($this->usuarioB);
    $consultas = 0;
    DB::listen(function ($q) use (&$consultas): void {
        if (str_contains($q->sql, 'saldos_inventario') || str_contains($q->sql, 'reservas_inventario')) {
            $consultas++;
        }
    });

    $this->getJson('/entregas/disponibilidad?'.http_build_query([
        'empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => $this->datos['almacenA']->id,
    ]))->assertOk()->assertJsonCount(16, 'saldos');

    expect($consultas)->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Rechazo por concurrencia: números reales del backend
|--------------------------------------------------------------------------
*/

it('caso QA: A pide 10 con B apartando 1 → A obtiene 9; B pide 9 → obtiene 1, con números correctos', function () {
    $tokenA = (string) Str::uuid();
    $tokenB = (string) Str::uuid();
    ($this->reservar)($this->usuarioB, $tokenB, [($this->fila)(1)])->assertOk()->assertJsonPath('ok', true);

    $respuestaA = ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(10)])->assertOk();
    $respuestaA->assertJsonPath('ok', false)
        ->assertJsonPath('lineas_cantidad.0.solicitado_combinado', 10)
        ->assertJsonPath('lineas_cantidad.0.disponible_efectivo', 9)
        ->assertJsonPath('lineas_cantidad.0.apartado_por_otros', 1);

    $respuestaB = ($this->reservar)($this->usuarioB, $tokenB, [($this->fila)(9)])->assertOk();
    $respuestaB->assertJsonPath('ok', false)
        ->assertJsonPath('lineas_cantidad.0.activo_nombre', 'Camisa')
        ->assertJsonPath('lineas_cantidad.0.talla_valor', 'M')
        ->assertJsonPath('lineas_cantidad.0.solicitado_combinado', 9)
        ->assertJsonPath('lineas_cantidad.0.disponible_efectivo', 1)
        ->assertJsonPath('lineas_cantidad.0.apartado_por_otros', 9);

    // Nunca se aparta más que el stock: 9 (A) + 1 (B) = 10.
    expect((int) Reserva::query()->where('token', $tokenA)->firstOrFail()->renglones()->sum('cantidad'))->toBe(9)
        ->and((int) Reserva::query()->where('token', $tokenB)->firstOrFail()->renglones()->sum('cantidad'))->toBe(1);
});

it('pide 9 cuando ya no queda nada: disponible 0 y nada apartado', function () {
    ($this->reservar)($this->usuarioA, (string) Str::uuid(), [($this->fila)(10)])->assertOk();

    $tokenB = (string) Str::uuid();
    ($this->reservar)($this->usuarioB, $tokenB, [($this->fila)(9)])->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('lineas_cantidad.0.solicitado_combinado', 9)
        ->assertJsonPath('lineas_cantidad.0.disponible_efectivo', 0);

    expect((int) Reserva::query()->where('token', $tokenB)->firstOrFail()->renglones()->sum('cantidad'))->toBe(0);
});

it('el rechazo usa el estado actual, no uno viejo: refleja una liberación ocurrida entre intentos', function () {
    $tokenA = (string) Str::uuid();
    ($this->reservar)($this->usuarioA, $tokenA, [($this->fila)(9)])->assertOk();
    $tokenB = (string) Str::uuid();
    ($this->reservar)($this->usuarioB, $tokenB, [($this->fila)(9)])->assertJsonPath('lineas_cantidad.0.disponible_efectivo', 1);

    $this->actingAs($this->usuarioA)->deleteJson("/entregas/reserva/{$tokenA}")->assertOk();

    ($this->reservar)($this->usuarioB, $tokenB, [($this->fila)(9)])
        ->assertJsonPath('ok', true)
        ->assertJsonPath('lineas_cantidad.0.disponible_efectivo', 10)
        ->assertJsonPath('lineas_cantidad.0.apartado_por_otros', 0);
});

it('la validación final con demanda mixta usa singular/plural y nombra activo y talla', function (int $stock, string $esperado) {
    $talla = Talla::factory()->create(['valor' => 'CH']);
    if ($stock > 0) {
        app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
            activoId: $this->datos['activoA']->id, tallaId: $talla->id, tipo: TipoMovimiento::Inicial, cantidad: $stock,
        ));
    }
    $this->datos['activoA']->tallas()->syncWithoutDetaching([$talla->id]);
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    ConjuntoComponente::factory()->for($conjunto)->create([
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $talla->id, 'cantidad_requerida' => 1,
    ]);

    $this->actingAs($this->usuarioA)->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id, 'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(), 'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true, 'idempotency_key' => (string) Str::uuid(),
        'activos' => [[...($this->fila)(1, $talla->id), 'finalidad' => 'uso_personal']],
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 8, 'variantes' => [], 'finalidad' => 'uso_personal']],
    ])->assertSessionHasErrors(['items' => $esperado]);
})->with([
    'plural' => [2, 'Sumando artículos sueltos y conjuntos solicitaste 9 piezas de «Camisa» talla CH, pero sólo quedan 2 disponibles en este almacén.'],
    'singular' => [1, 'Sumando artículos sueltos y conjuntos solicitaste 9 piezas de «Camisa» talla CH, pero sólo queda 1 disponible en este almacén.'],
]);

/*
|--------------------------------------------------------------------------
| Traspasos: almacén ORIGEN
|--------------------------------------------------------------------------
*/

it('traspasos: el origen refleja apartados de entregas y traspasos ajenos, no el propio', function () {
    $destino = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();
    $tokenTraspaso = (string) Str::uuid();
    $this->actingAs($this->usuarioA)->postJson('/inventario/traspasos/reserva', [
        'token' => $tokenTraspaso, 'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 6]],
    ])->assertOk()->assertJsonPath('lineas_cantidad.0.apartado_por_otros', 0);
    ($this->reservar)($this->usuarioB, (string) Str::uuid(), [($this->fila)(1)])->assertOk();

    $consultar = fn ($usuario, ?string $token) => collect($this->actingAs($usuario)->getJson('/inventario/traspasos/disponibilidad?'.http_build_query(array_filter([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'activo_ids' => [$this->datos['activoA']->id], 'token' => $token,
    ])))->assertOk()->json('saldos'))->firstWhere('talla_id', $this->datos['tallaA']->id)['disponible'];

    expect($consultar($this->usuarioA, $tokenTraspaso))->toBe(9)
        ->and($consultar($this->usuarioB, null))->toBe(3)
        ->and(SaldoInventario::query()->where('almacen_id', $destino->id)->exists())->toBeFalse();
});

it('traspasos: la consulta exige permiso de traspasos y acceso a la empresa origen', function () {
    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $url = '/inventario/traspasos/disponibilidad?'.http_build_query([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'activo_ids' => [$this->datos['activoA']->id],
    ]);

    $this->actingAs($sinPermiso)->getJson($url)->assertForbidden();
    $this->actingAs($ajeno)->getJson($url)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Devoluciones: CUSTODIA devolvible (nunca stock de almacén)
|--------------------------------------------------------------------------
*/

it('devoluciones: custodia 5, A aparta 3 → B ve 2; A cancela → 5; A confirma 3 → 2 reales; sin tocar stock', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->usuarioA->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]], [], [],
    );
    $detalle = $entrega->detalles->first();
    $saldoAntes = (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');

    $reservarDevolucion = fn ($usuario, string $token, int $cantidad) => $this->actingAs($usuario)->postJson('/devoluciones/reserva', [
        'token' => $token, 'entrega_uniforme_id' => $entrega->id,
        'activos' => [['detalle_entrega_id' => $detalle->id, 'cantidad' => $cantidad]], 'unidades' => [],
    ])->assertOk();
    $devolvible = fn ($usuario, ?string $token = null): array => collect($this->actingAs($usuario)->getJson('/devoluciones/disponibilidad?'.http_build_query(array_filter([
        'entrega_uniforme_id' => $entrega->id, 'token' => $token,
    ])))->assertOk()->json('renglones'))->firstWhere('detalle_entrega_id', $detalle->id);

    expect($devolvible($this->usuarioB))->toMatchArray(['pendiente_real' => 5, 'apartado_por_otros' => 0, 'disponible' => 5]);

    $tokenA = (string) Str::uuid();
    $reservarDevolucion($this->usuarioA, $tokenA, 3);
    expect($devolvible($this->usuarioB))->toMatchArray(['pendiente_real' => 5, 'apartado_por_otros' => 3, 'disponible' => 2])
        ->and($devolvible($this->usuarioA, $tokenA)['disponible'])->toBe(5);

    $this->actingAs($this->usuarioA)->deleteJson("/devoluciones/reserva/{$tokenA}")->assertOk();
    expect($devolvible($this->usuarioB)['disponible'])->toBe(5)
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe($saldoAntes);

    $tokenA2 = (string) Str::uuid();
    $reservarDevolucion($this->usuarioA, $tokenA2, 3);
    $this->actingAs($this->usuarioA)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id, 'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(), 'motivo' => 'Talla incorrecta',
        'activos' => [['detalle_entrega_id' => $detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable']],
        'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(), 'aceptacion' => true,
        'reserva_token' => $tokenA2,
    ])->assertSessionHasNoErrors();

    expect($devolvible($this->usuarioB))->toMatchArray(['pendiente_real' => 2, 'apartado_por_otros' => 0, 'disponible' => 2]);
});

it('devoluciones: la consulta exige acceso a la empresa de la entrega', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->usuarioA->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]], [], [],
    );
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)->getJson("/devoluciones/disponibilidad?entrega_uniforme_id={$entrega->id}")->assertForbidden();
});
