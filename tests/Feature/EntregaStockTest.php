<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Excepciones\ExistenciasInsuficientesException;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Conjunto;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * QA de Entregas: un activo/variante/unidad sin existencia real NUNCA debe
 * poder agregarse a una entrega — ni por selección de UI ni, sobre todo, en
 * el backend (que es quien de verdad lo decide). Cubre disponibilidad por
 * cantidad, variantes, unidades identificadas, conjuntos, concurrencia,
 * autorización cross-empresa y que los errores de negocio nunca sean un 500.
 */
beforeEach(function () {
    // El flujo único de entrega firma en el mismo POST: guarda las firmas y
    // materializa el PDF del acuse. Aquí sólo interesa la validación de
    // existencias/autorización, así que se aísla el disco y se sustituye el
    // render real del PDF (dompdf) por un doble para no acumular su costo en
    // cada caso "sin errores" de este archivo.
    Storage::fake('local');
    Mail::fake();
    $this->mock(ServicioAcusePdf::class, function ($mock): void {
        $mock->shouldReceive('generar')->andReturn('acuses/fake.pdf');
        $mock->shouldReceive('contenido')->andReturn(null);
    });

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);
});

function postEntrega(TestCase $test, array $payload)
{
    return $test->actingAs($test->admin)->post('/entregas', $payload);
}

// ------------------------------------------------------------------
// Alcance de búsqueda: Empresa → Sucursal → Colaborador
// ------------------------------------------------------------------

it('1. la búsqueda de sucursales de la empresa A no devuelve sucursales de la empresa B', function () {
    $respuesta = $this->actingAs($this->admin)
        ->get("/sucursales/buscar?empresa_id={$this->datos['empresaA']->id}")
        ->json('sucursales');

    expect(collect($respuesta)->pluck('id')->all())
        ->toContain($this->datos['sucursalA']->id)
        ->not->toContain($this->datos['sucursalB']->id);
});

it('2. la búsqueda de colaboradores acotada a sucursal_id sólo devuelve colaboradores de esa empresa+sucursal', function () {
    $otraSucursalMismaEmpresa = Sucursal::factory()->for($this->datos['empresaA'])->create();
    $colaboradorOtraSucursal = Colaborador::factory()->for($this->datos['empresaA'])->for($otraSucursalMismaEmpresa)->create();

    $respuesta = $this->actingAs($this->admin)
        ->get("/colaboradores/buscar?empresa_id={$this->datos['empresaA']->id}&sucursal_id={$this->datos['sucursalA']->id}")
        ->json('colaboradores');

    $ids = collect($respuesta)->pluck('id');
    expect($ids)->toContain($this->datos['colaboradorA']->id)
        ->not->toContain($colaboradorOtraSucursal->id);
});

it('3. la búsqueda de colaboradores encuentra por nombre uno que no está entre los primeros 20', function () {
    Colaborador::factory()->count(25)->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();
    $buscado = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create([
        'nombre_completo' => 'Zzz Colaborador Escondido',
        'numero_empleado' => 'ESC-001',
    ]);

    $respuesta = $this->actingAs($this->admin)
        ->get("/colaboradores/buscar?empresa_id={$this->datos['empresaA']->id}&sucursal_id={$this->datos['sucursalA']->id}&q=Escondido")
        ->json('colaboradores');

    expect(collect($respuesta)->pluck('id')->all())->toContain($buscado->id);
});

it('4. un usuario de la empresa A no encuentra colaboradores de la empresa B', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create(['nombre_completo' => 'Colaborador Empresa B']);
    $supervisorA = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $respuesta = $this->actingAs($supervisorA)
        ->get("/colaboradores/buscar?empresa_id={$this->datos['empresaB']->id}&sucursal_id={$this->datos['sucursalB']->id}&q=Empresa+B")
        ->json('colaboradores');

    expect($respuesta)->toBe([]);
});

it('5. la búsqueda de almacenes de la empresa A no devuelve almacenes que sólo abastecen a la empresa B', function () {
    $almacenSoloB = Almacen::factory()->paraEmpresa($this->datos['empresaB'])->create();

    $respuesta = $this->actingAs($this->admin)
        ->get("/almacenes/buscar?empresa_id={$this->datos['empresaA']->id}")
        ->json('almacenes');

    expect(collect($respuesta)->pluck('id')->all())->not->toContain($almacenSoloB->id);
});

// ------------------------------------------------------------------
// Stock por cantidad
// ------------------------------------------------------------------

function payloadBase(array $datos, int $encargadoId): array
{
    return [
        'colaborador_id' => $datos['colaboradorA']->id,
        'almacen_id' => $datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        // Flujo único: la petición SIEMPRE trae ambas firmas y la aceptación.
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
    ];
}

it('6. un activo con stock 0 no puede entregarse: el backend lo rechaza con un error de negocio, no un 500', function () {
    $respuesta = postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ]);

    $respuesta->assertSessionHasErrors('activos.0.cantidad');
    expect(EntregaUniforme::count())->toBe(0);
});

it('7. con stock 3, intentar entregar 4 es rechazado', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 3,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 4]],
    ])->assertSessionHasErrors('activos.0.cantidad');

    expect(EntregaUniforme::count())->toBe(0);
});

it('8. con stock 3, entregar 3 es permitido y el saldo final queda en 0', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 3,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
    ])->assertSessionHasNoErrors();

    expect(EntregaUniforme::count())->toBe(1)
        ->and(SaldoInventario::first()->cantidad)->toBe(0);
});

it('9. una variante sin existencia (S=0) no es entregable aunque otra variante del mismo activo sí tenga stock', function () {
    $tallaS = Talla::factory()->create(['valor' => 'S']);
    $this->datos['activoA']->tallas()->attach($tallaS);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id, // M
        tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $tallaS->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('activos.0.cantidad');

    expect(EntregaUniforme::count())->toBe(0);
});

it('10. una variante con existencia (M=5) sí permite entregar hasta 5', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
    ])->assertSessionHasNoErrors();

    expect(SaldoInventario::first()->cantidad)->toBe(0);
});

it('11. existencia en OTRO almacén no habilita el activo en el almacén de la entrega', function () {
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $otroAlmacen->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('activos.0.cantidad');

    expect(EntregaUniforme::count())->toBe(0);
});

it('12. un activo inactivo no puede entregarse', function () {
    $this->datos['activoA']->update(['activo' => false]);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 20,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('activos.0.activo_id');
});

// ------------------------------------------------------------------
// Unidades de seguimiento individual
// ------------------------------------------------------------------

it('13-17. sólo una unidad en_almacen+funcionando en el almacén de la entrega es entregable', function () {
    $activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();

    $asignada = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($this->datos['almacenA'])->asignada()->create();
    $reparacion = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($this->datos['almacenA'])->conCondicion(CondicionUnidadActivo::EnReparacion)->create();
    $perdida = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($this->datos['almacenA'])->conCondicion(CondicionUnidadActivo::Perdido)->create();
    $robada = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($this->datos['almacenA'])->conCondicion(CondicionUnidadActivo::Robado)->create();
    $funcionando = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($this->datos['almacenA'])->create();

    foreach ([$asignada, $reparacion, $perdida, $robada] as $unidad) {
        postEntrega($this, [
            ...payloadBase($this->datos, $this->admin->id),
            'unidades' => [['unidad_activo_id' => $unidad->id]],
        ])->assertSessionHasErrors('unidades.0.unidad_activo_id');
    }

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'unidades' => [['unidad_activo_id' => $funcionando->id]],
    ])->assertSessionHasNoErrors();

    expect($funcionando->fresh()->estado)->toBe(EstadoUnidadActivo::Asignada);
});

it('18. una unidad de otro almacén es rechazada', function () {
    $activo = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activo)->for($otroAlmacen)->create();

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'unidades' => [['unidad_activo_id' => $unidad->id]],
    ])->assertSessionHasErrors('unidades.0.unidad_activo_id');
});

it('19. una unidad de otra empresa es rechazada', function () {
    $activoB = Activo::factory()->for($this->datos['empresaB'])->seguimientoIndividual()->create();
    $unidadB = UnidadActivo::factory()->for($this->datos['empresaB'], 'empresa')->for($activoB)->for($this->datos['almacenB'])->create();

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'unidades' => [['unidad_activo_id' => $unidadB->id]],
    ])->assertSessionHasErrors('unidades.0.unidad_activo_id');
});

// ------------------------------------------------------------------
// Conjuntos
// ------------------------------------------------------------------

it('20-21. un conjunto sin disponibilidad de un componente no puede agregarse; la disponibilidad la marca el componente limitante', function () {
    $pantalon = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    // Camisa (activoA) con 10, Pantalón con 0.
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 10,
    ));

    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1, 'talla_id' => $this->datos['tallaA']->id]);
    $conjunto->componentes()->create(['activo_id' => $pantalon->id, 'cantidad_requerida' => 1]);

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('conjuntos.0.cantidad');

    expect(EntregaUniforme::count())->toBe(0);
});

it('22. un conjunto con un activo inactivo como componente no es entregable', function () {
    $pantalon = Activo::factory()->for($this->datos['empresaA'])->create(['activo' => false]);
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $pantalon->id, 'cantidad_requerida' => 1]);

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('conjuntos.0.cantidad');
});

it('23. un conjunto cuyo componente de seguimiento individual no tiene unidades entregables no está disponible', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->asignada()->create();

    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $activoIndividual->id, 'cantidad_requerida' => 1]);

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('conjuntos.0.cantidad');
});

// ------------------------------------------------------------------
// Concurrencia (sin stock negativo, sin doble consumo del último saldo)
// ------------------------------------------------------------------

it('24-25. dos intentos contra el último saldo: sólo uno consume la existencia, el otro es rechazado y nunca hay stock negativo', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 1,
    ));

    $otroColaborador = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    // Primera "petición" gana la última existencia.
    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasNoErrors();

    // Segunda "petición" (otro colaborador) llega tarde: ya no hay existencia.
    $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $otroColaborador->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('activos.0.cantidad');

    expect(EntregaUniforme::count())->toBe(1)
        ->and(SaldoInventario::first()->cantidad)->toBe(0)
        ->and(SaldoInventario::first()->cantidad)->toBeGreaterThanOrEqual(0);
});

it('el servicio de inventario rechaza dejar el saldo en negativo incluso saltándose la validación del Request', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 1,
    ));

    expect(fn () => app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Entrega, cantidad: 2,
    )))->toThrow(ExistenciasInsuficientesException::class);

    expect(SaldoInventario::first()->cantidad)->toBe(1);
});

// ------------------------------------------------------------------
// Autorización cross-empresa
// ------------------------------------------------------------------

it('32. un almacen_id de otra empresa es rechazado', function () {
    postEntrega($this, [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenB']->id,
        'fecha_entrega' => now()->toDateString(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('almacen_id');
});

it('33. un colaborador de otra empresa con un almacén/activo que no le corresponden es rechazado (la empresa se deriva del colaborador)', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    // almacenA/activoA pertenecen a la empresa A; el colaborador es de la empresa B:
    // la petición deriva empresa_id del colaborador, así que el desajuste se
    // detecta en almacen_id / activos, no en colaborador_id (que es válido en sí mismo).
    postEntrega($this, [
        'colaborador_id' => $colaboradorB->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors(['almacen_id', 'activos.0.activo_id']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('33b. un usuario sin acceso a la empresa del colaborador recibe 403 (defensa en el controller, no sólo en el Request)', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();
    $activoB = Activo::factory()->for($this->datos['empresaB'])->create();
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaB']->id, almacenId: $this->datos['almacenB']->id,
        activoId: $activoB->id, tallaId: null,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));
    $supervisorSoloA = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($supervisorSoloA)->post('/entregas', [
        'colaborador_id' => $colaboradorB->id,
        'almacen_id' => $this->datos['almacenB']->id,
        'fecha_entrega' => now()->toDateString(),
        'activos' => [['activo_id' => $activoB->id, 'cantidad' => 1]],
    ])->assertForbidden();

    expect(EntregaUniforme::count())->toBe(0);
});

it('34. un activo de otra empresa es rechazado', function () {
    $activoB = Activo::factory()->for($this->datos['empresaB'])->create();

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $activoB->id, 'cantidad' => 1]],
    ])->assertSessionHasErrors('activos.0.activo_id');
});

// ------------------------------------------------------------------
// Combinaciones válidas y "conjunto opcional" (QA post-Fase 10): ningún
// elemento (activo/unidad/conjunto) es obligatorio por sí mismo — sólo se
// exige que la entrega termine con AL MENOS UNO en total. El bug reportado
// ("El campo conjunto es obligatorio" al registrar una entrega sin
// conjuntos) era del FRONTEND: `Entregas/Crear.vue` dejaba en el payload una
// fila vacía `{conjunto_id: '', ...}` al pulsar "+ Agregar conjunto" sin
// seleccionar nada; `Crear.vue::enviar()` ahora la filtra antes de
// `form.post()`. `GuardarEntregaRequest`/`CrearEntregaUniforme` en sí ya
// tratan cada tipo como opcional — estos tests lo prueban contra el
// endpoint HTTP completo y cubren la defensa en profundidad del backend si
// esa fila vacía llegara de todos modos.
// ------------------------------------------------------------------

it('35. varios activos en una sola entrega son válidos', function () {
    $pantalon = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $pantalon->id, tallaId: null,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [
            ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1],
            ['activo_id' => $pantalon->id, 'talla_id' => null, 'cantidad' => 1],
        ],
    ])->assertSessionHasNoErrors();

    expect(EntregaUniforme::sole()->detalles)->toHaveCount(2);
});

it('36. sólo un conjunto (sin activos ni unidades) con disponibilidad suficiente es válido', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $this->datos['activoA']->id, 'cantidad_requerida' => 1, 'talla_id' => $this->datos['tallaA']->id]);

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    ])->assertSessionHasNoErrors();

    expect(EntregaUniforme::count())->toBe(1);
});

it('37. un activo suelto y un conjunto se pueden combinar en la misma entrega', function () {
    $pantalon = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón']);
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $pantalon->id, tallaId: null,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));
    $conjunto = Conjunto::factory()->for($this->datos['empresaA'])->create();
    $conjunto->componentes()->create(['activo_id' => $pantalon->id, 'cantidad_requerida' => 1]);

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        'conjuntos' => [['conjunto_id' => $conjunto->id, 'cantidad' => 1]],
    ])->assertSessionHasNoErrors();

    expect(EntregaUniforme::sole()->detalles)->toHaveCount(2);
});

it('38. una entrega completamente vacía es rechazada con un mensaje humano, no por un campo "obligatorio" suelto', function () {
    postEntrega($this, payloadBase($this->datos, $this->admin->id))
        ->assertSessionHasErrors(['items' => 'Agrega al menos un activo, unidad identificada o conjunto a la entrega.']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('39. una fila de conjunto dejada vacía ("+ Agregar conjunto" sin seleccionar nada) no exige "conjunto obligatorio": el backend responde "Selecciona un conjunto." y no bloquea el activo válido con un error ambiguo', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));

    postEntrega($this, [
        ...payloadBase($this->datos, $this->admin->id),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        // Simula un cliente que no filtró la fila vacía de "+ Agregar
        // conjunto" antes de enviar (el frontend real ya la filtra en
        // `Crear.vue::enviar()`; esto prueba que el backend, como fuente de
        // verdad, no depende de ese filtrado para dar un mensaje claro).
        'conjuntos' => [['conjunto_id' => null, 'cantidad' => 1]],
    ])->assertSessionHasErrors(['conjuntos.0.conjunto_id' => 'Selecciona un conjunto.']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('si el stock baja entre la previsualización y la firma, la acción revierte todo con el mensaje "El stock disponible cambió"', function () {
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));

    // La acción de creación no pasa por el Form Request: es la autoridad final
    // que re-lee el saldo bajo lock. Con saldo 5 y una entrega de 8 → revienta
    // dentro de `registrarComponenteCantidad`.
    try {
        app(CrearEntregaUniforme::class)->ejecutar(
            $this->datos['colaboradorA']->id,
            $this->datos['almacenA']->id,
            $this->admin->id,
            now()->toDateString(),
            [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 8]],
            [], [],
        );
        $this->fail('Se esperaba un error de negocio por stock insuficiente.');
    } catch (ExcepcionDeNegocioSimple $e) {
        expect($e->getMessage())->toStartWith('El stock disponible cambió.')
            ->and($e->getMessage())->toContain('8')
            ->and($e->getMessage())->toContain('5');
    }

    expect(EntregaUniforme::count())->toBe(0)
        ->and(SaldoInventario::first()->cantidad)->toBe(5)
        ->and(MovimientoInventario::where('tipo', TipoMovimiento::Entrega->value)->count())->toBe(0);
});
