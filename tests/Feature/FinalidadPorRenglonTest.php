<?php

use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\CondicionDevolucion;
use App\Enums\FinalidadCustodia;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\AcuseRecepcion;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\SaldoInventario;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * La finalidad vive en CADA renglón y se conserva de punta a punta: al
 * registrar (1 uso personal + 9 para redistribuir nunca se funden), en "Mis
 * activos", al redistribuir (sólo sale de la bolsa elegida), al devolver (del
 * renglón exacto), en el detalle de la entrega y en el acuse/PDF.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id, almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id, tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial, cantidad: 50,
    ));

    $this->usuarioConPermisos = function (array $permisos): User {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return tap(User::factory()->create(), function (User $u) use ($rol): void {
            $u->assignRole($rol);
            $u->empresas()->sync([$this->datos['empresaA']->id]);
        });
    };

    $this->custodioUsuario = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir', 'activos.ver-custodia-propia']);
    $this->yatziri = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
        ->create(['usuario_id' => $this->custodioUsuario->id, 'nombre_completo' => 'Yatziri Custodia']);
    $this->juan = $this->datos['colaboradorA'];

    $this->firmas = fn (): array => [
        'fecha_entrega' => now()->toDateString(), 'firma' => firmaDemoBase64(), 'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true, 'idempotency_key' => (string) Str::uuid(),
    ];
    $this->camisas = fn (int $n, ?string $finalidad, array $extra = []): array => [
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $n, 'finalidad' => $finalidad, ...$extra,
    ];

    // Mismo activo + talla en DOS renglones: 1 de uso personal y 9 para redistribuir.
    $this->actingAs($this->admin)->post('/entregas', [
        ...($this->firmas)(),
        'colaborador_id' => $this->yatziri->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'activos' => [($this->camisas)(1, 'uso_personal'), ($this->camisas)(9, 'redistribucion')],
    ])->assertSessionHasNoErrors();
    $this->entrega = EntregaUniforme::sole();

    $this->renglon = fn (FinalidadCustodia $f): DetalleEntrega => $this->entrega->detalles()->where('finalidad', $f)->sole();

    /** Lo que "Mis activos" muestra hoy a Yatziri, por sección. */
    $this->misActivos = function (): array {
        $props = $this->actingAs($this->custodioUsuario)->get('/mis-activos')->assertOk()->viewData('page')['props'];

        return collect(['personales', 'redistribuir', 'sinClasificar'])
            ->mapWithKeys(fn (string $k): array => [$k => collect($props[$k])->sum('cantidad')])
            ->all();
    };

    $this->devolver = fn (DetalleEntrega $detalle, int $cantidad) => app(RegistrarDevolucionFirmada::class)->ejecutar(
        $this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $detalle->id, 'cantidad' => $cantidad, 'condicion' => CondicionDevolucion::Reutilizable->value]],
        [], $this->admin->id, null, null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );

    $this->redistribuir = fn (User $u, array $activos) => $this->actingAs($u)->post('/entregas', [
        ...($this->firmas)(), 'origen' => 'custodia', 'colaborador_id' => $this->juan->id, 'activos' => $activos,
    ]);
});

it('1 de uso personal + 9 para redistribuir quedan como dos renglones y así los ve el custodio', function () {
    expect($this->entrega->detalles()->count())->toBe(2)
        ->and(($this->renglon)(FinalidadCustodia::UsoPersonal)->cantidad)->toBe(1)
        ->and(($this->renglon)(FinalidadCustodia::Redistribucion)->cantidad)->toBe(9)
        ->and(($this->misActivos)())->toBe(['personales' => 1, 'redistribuir' => 9, 'sinClasificar' => 0]);
});

it('redistribuir 4 sólo consume la bolsa para redistribuir: queda 1 personal y 5 para redistribuir', function () {
    ($this->redistribuir)($this->custodioUsuario, [($this->camisas)(4, 'uso_personal', ['bolsa' => 'redistribucion'])])
        ->assertSessionHasNoErrors();

    $recibido = DetalleEntrega::query()->whereHas('entrega', fn ($q) => $q->where('colaborador_id', $this->juan->id))->sole();

    expect(($this->misActivos)())->toBe(['personales' => 1, 'redistribuir' => 5, 'sinClasificar' => 0])
        ->and($recibido->detalle_origen_id)->toBe(($this->renglon)(FinalidadCustodia::Redistribucion)->id)
        ->and($recibido->finalidad)->toBe(FinalidadCustodia::UsoPersonal);

    // Trazabilidad: la auditoría dice de qué bolsa salió y con qué finalidad llegó.
    $renglonAuditado = BitacoraAuditoria::query()->where('accion', 'redistribuir')->sole()->valores_nuevos['renglones'][0];
    expect($renglonAuditado['desde'])->toBe('Para redistribuir')
        ->and($renglonAuditado['finalidad_destinatario'])->toBe('Uso personal');
});

it('no se puede redistribuir más de lo que hay en la bolsa para redistribuir aunque sume con lo personal', function () {
    ($this->redistribuir)($this->custodioUsuario, [($this->camisas)(10, 'uso_personal', ['bolsa' => 'redistribucion'])])
        ->assertSessionHasErrors('activos.0.cantidad');

    expect(($this->misActivos)())->toBe(['personales' => 1, 'redistribuir' => 9, 'sinClasificar' => 0]);
});

it('con permiso de reasignar lo propio, elegir la bolsa personal sólo toca lo personal', function () {
    $conPropios = ($this->usuarioConPermisos)(['entregas.ver', 'entregas.redistribuir', 'entregas.redistribuir-propios', 'activos.ver-custodia-propia']);
    $this->yatziri->update(['usuario_id' => $conPropios->id]);
    $this->custodioUsuario = $conPropios;

    ($this->redistribuir)($conPropios, [($this->camisas)(1, 'uso_personal', ['bolsa' => 'personal'])])
        ->assertSessionHasNoErrors();

    expect(($this->misActivos)())->toBe(['personales' => 0, 'redistribuir' => 9, 'sinClasificar' => 0]);
});

it('devolver descuenta del renglón exacto: 2 para redistribuir → 1/7; luego el personal → 0/7', function () {
    ($this->devolver)(($this->renglon)(FinalidadCustodia::Redistribucion), 2);
    expect(($this->misActivos)())->toBe(['personales' => 1, 'redistribuir' => 7, 'sinClasificar' => 0]);

    ($this->devolver)(($this->renglon)(FinalidadCustodia::UsoPersonal), 1);
    expect(($this->misActivos)())->toBe(['personales' => 0, 'redistribuir' => 7, 'sinClasificar' => 0])
        ->and((int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad'))->toBe(43);
});

it('el formulario de devolución identifica la finalidad de cada renglón pendiente', function () {
    $this->actingAs($this->admin)->get("/devoluciones/crear?entrega_id={$this->entrega->id}")
        ->assertInertia(fn ($page) => $page
            ->where('entrega.renglones', fn ($renglones) => collect($renglones)
                ->map(fn ($r) => [$r['pendiente'], $r['finalidad_etiqueta']])->sortBy(0)->values()->all() === [[1, 'Uso personal'], [9, 'Para redistribuir']]));
});

it('devolver el renglón personal no toca lo recibido para redistribuir', function () {
    ($this->devolver)(($this->renglon)(FinalidadCustodia::UsoPersonal), 1);

    expect(($this->misActivos)())->toBe(['personales' => 0, 'redistribuir' => 9, 'sinClasificar' => 0]);
});

it('lo histórico sin clasificar se muestra aparte en Mis activos, sin convertirse ni mezclarse con uso personal', function () {
    ($this->renglon)(FinalidadCustodia::Redistribucion)->update(['finalidad' => null]);

    expect(($this->misActivos)())->toBe(['personales' => 1, 'redistribuir' => 0, 'sinClasificar' => 9])
        ->and($this->entrega->detalles()->whereNull('finalidad')->value('cantidad'))->toBe(9);
});

it('el detalle de la entrega muestra la finalidad de cada renglón sin fundirlos', function () {
    $this->actingAs($this->admin)->get("/entregas/{$this->entrega->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('entrega.items', 2)
            ->where('entrega.items', fn ($items) => collect($items)
                ->map(fn ($i) => [$i['cantidad'], $i['finalidad'], $i['finalidad_etiqueta']])
                ->sortBy(0)->values()->all() === [[1, 'uso_personal', 'Uso personal'], [9, 'redistribucion', 'Para redistribuir']]));
});

describe('acuse y PDF', function () {
    beforeEach(function () {
        $this->acuse = AcuseRecepcion::sole();
        $this->html = fn (AcuseRecepcion $acuse): string => view('acuses.comprobante', [
            'acuse' => $acuse, 'snapshot' => $acuse->snapshot_entrega, 'firmaDataUri' => null,
            'firmaOperadorDataUri' => null, 'logoDataUri' => null, 'evidenciasPorItem' => [],
        ])->render();
    });

    it('el snapshot inmutable guarda la finalidad de cada renglón', function () {
        expect(collect($this->acuse->snapshot_entrega['items'])->map(fn ($i) => [$i['cantidad'], $i['finalidad']])->all())
            ->toBe([[1, 'uso_personal'], [9, 'redistribucion']]);
    });

    it('el comprobante muestra la columna Finalidad y un texto general de activos', function () {
        expect(($this->html)($this->acuse))
            ->toContain('<th>Finalidad</th>')
            ->toContain('Uso personal')
            ->toContain('Para redistribuir')
            ->toContain('entrega y recepción de activos y/o uniformes')
            ->not->toContain('recepción de uniformes.');
    });

    it('un renglón sin finalidad se imprime como "Sin clasificar"', function () {
        $snapshot = $this->acuse->snapshot_entrega;
        $snapshot['items'][1]['finalidad'] = null;
        $this->acuse->forceFill(['snapshot_entrega' => $snapshot]);

        expect(($this->html)($this->acuse))->toContain('Sin clasificar');
    });

    it('un acuse firmado antes de existir la finalidad se imprime sin esa columna', function () {
        $snapshot = $this->acuse->snapshot_entrega;
        $snapshot['items'] = array_map(function (array $item): array {
            unset($item['finalidad'], $item['conjunto']);

            return $item;
        }, $snapshot['items']);
        $this->acuse->forceFill(['snapshot_entrega' => $snapshot]);

        expect(($this->html)($this->acuse))->not->toContain('<th>Finalidad</th>')->not->toContain('Sin clasificar');
        $this->actingAs($this->admin)->get("/acuses/{$this->acuse->id}/pdf")->assertOk();
    });
});
