<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * "Distribución actual del activo" (detalle de Activo): dónde está hoy cada
 * pieza — almacén, o custodio + variante + finalidad — calculado en backend
 * con la misma custodia del resto del sistema. Sin filas anónimas, sin
 * duplicar lo redistribuido y sin mezclar finalidades ni custodios.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 30,
    ));

    $rol = Role::create(['name' => 'redistribuidor-'.Str::lower(Str::random(6)), 'guard_name' => 'web']);
    $rol->syncPermissions(['entregas.ver', 'entregas.redistribuir']);
    $this->redistribuidor = tap(User::factory()->create(), function (User $u) use ($rol): void {
        $u->assignRole($rol);
        $u->empresas()->sync([$this->datos['empresaA']->id]);
    });
    $this->yatziri = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
        ->create(['usuario_id' => $this->redistribuidor->id, 'nombre_completo' => 'Yatziri Custodia']);
    $this->juan = tap($this->datos['colaboradorA'])->update(['nombre_completo' => 'Juan Receptor']);

    $this->firmas = fn (): array => [
        'fecha_entrega' => now()->toDateString(), 'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true, 'idempotency_key' => (string) Str::uuid(),
    ];
    $this->camisas = fn (int $n, string $finalidad, array $extra = []): array => [
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $n, 'finalidad' => $finalidad, ...$extra,
    ];

    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(), 'colaborador_id' => $this->yatziri->id, 'almacen_id' => $this->datos['almacenA']->id,
        'activos' => [($this->camisas)(1, 'uso_personal'), ($this->camisas)(9, 'redistribucion')],
    ])->assertSessionHasNoErrors();

    $this->props = fn (Activo $activo): array => $this->actingAs($this->admin)->get("/activos/{$activo->id}")
        ->assertOk()->viewData('page')['props'];

    /** Filas legibles: [grupo, custodio o almacén, talla, cantidad]. */
    $this->filas = fn (array $props): array => collect($props['distribucion']['filas'])
        ->map(fn ($f) => [$f['grupo'], $f['custodio']['nombre_completo'] ?? $f['almacen'], $f['talla'], $f['cantidad']])
        ->all();
});

it('muestra almacén y custodia por finalidad, con nombre del custodio y variante', function () {
    $props = ($this->props)($this->datos['activoA']);

    expect(($this->filas)($props))->toBe([
        ['almacen', 'Almacén A', 'M', 20],
        ['uso_personal', 'Yatziri Custodia', 'M', 1],
        ['redistribucion', 'Yatziri Custodia', 'M', 9],
    ])->and($props['distribucion']['totales'])->toBe(['almacen' => 20, 'uso_personal' => 1, 'redistribucion' => 9, 'sin_clasificar' => 0]);
});

it('al redistribuir se actualiza por custodio, sin filas anónimas ni piezas duplicadas', function () {
    $this->actingAs($this->redistribuidor)->post('/entregas', [
        ...($this->firmas)(), 'origen' => 'custodia', 'colaborador_id' => $this->juan->id,
        'activos' => [($this->camisas)(4, 'uso_personal', ['bolsa' => 'redistribucion'])],
    ])->assertSessionHasNoErrors();

    $props = ($this->props)($this->datos['activoA']);

    expect(($this->filas)($props))->toBe([
        ['almacen', 'Almacén A', 'M', 20],
        ['uso_personal', 'Juan Receptor', 'M', 4],
        ['uso_personal', 'Yatziri Custodia', 'M', 1],
        ['redistribucion', 'Yatziri Custodia', 'M', 5],
    ])
        ->and(collect($props['distribucion']['filas'])->every(fn ($f) => $f['custodio'] !== null || $f['almacen'] !== null))->toBeTrue()
        // El resumen "Asignado" tampoco cuenta dos veces lo redistribuido.
        ->and($props['resumenCantidades']['asignado'])->toBe(10);
});

it('lo sin clasificar va en su propio grupo, nunca sumado a uso personal', function () {
    DetalleEntrega::query()->where('finalidad', 'redistribucion')->update(['finalidad' => null]);

    expect(($this->props)($this->datos['activoA'])['distribucion']['totales'])
        ->toBe(['almacen' => 20, 'uso_personal' => 1, 'redistribucion' => 0, 'sin_clasificar' => 9]);
});

it('las unidades individuales muestran código, estado, custodio, almacén, condición y finalidad', function () {
    $laptop = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    $asignada = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptop)->for($this->datos['almacenA'])->create();
    $libre = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptop)->for($this->datos['almacenA'])->create();

    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(), 'colaborador_id' => $this->yatziri->id, 'almacen_id' => $this->datos['almacenA']->id,
        'unidades' => [['unidad_activo_id' => $asignada->id, 'finalidad' => 'redistribucion']],
    ])->assertSessionHasNoErrors();

    $unidades = collect(($this->props)($laptop)['distribucion']['unidades'])->keyBy('codigo');

    expect($unidades[$asignada->codigo])->toMatchArray([
        'grupo' => 'redistribucion', 'grupo_etiqueta' => 'Para redistribuir', 'finalidad_etiqueta' => 'Para redistribuir',
        'custodio' => [
            'id' => $this->yatziri->id, 'nombre_completo' => 'Yatziri Custodia', 'numero_empleado' => $this->yatziri->numero_empleado,
            'empresa' => 'Empresa A', 'sucursal' => $this->datos['sucursalA']->nombre, 'otra_empresa' => false,
        ],
        'condicion' => 'Funcionando',
    ])->and($unidades[$libre->codigo])->toMatchArray([
        'grupo' => 'almacen', 'grupo_etiqueta' => 'En almacén', 'custodio' => null, 'almacen' => 'Almacén A', 'finalidad_etiqueta' => null,
    ]);
});

describe('salir de "Nueva entrega" (limpieza del apartado)', function () {
    it('quien sólo redistribuye abre el listado y el formulario, y liberar su apartado no le da 403', function () {
        $this->actingAs($this->redistribuidor)->get('/entregas')->assertOk();
        $this->actingAs($this->redistribuidor)->get('/entregas/crear')->assertOk();

        $this->actingAs($this->redistribuidor)
            ->deleteJson('/entregas/reserva/'.Str::uuid())
            ->assertOk()->assertJson(['ok' => true]);
    });

    it('un 403 real se conserva: sin ningún permiso de entregas, liberar sigue prohibido', function () {
        $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

        $this->actingAs($sinPermiso)->deleteJson('/entregas/reserva/'.Str::uuid())->assertForbidden();
    });
});
