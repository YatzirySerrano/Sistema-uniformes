<?php

use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\Colaborador;
use App\Models\TipoActivo;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

/**
 * "Existencias globales" también resume los activos de SEGUIMIENTO
 * INDIVIDUAL. Causa raíz del bug: la pantalla sólo consultaba
 * `saldos_inventario`, y las unidades individuales nunca viven ahí (cada
 * pieza es una fila de `unidades_activo`) — el filtro «Seguimiento
 * individual» siempre quedaba vacío.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA'], $this->datos['empresaB']]);

    $this->tipoMovil = TipoActivo::factory()->create(['nombre' => 'Dispositivo móvil']);
    $this->categoriaCelular = CategoriaActivo::factory()->create(['nombre' => 'Celular', 'tipo_activo_id' => $this->tipoMovil->id]);
    $this->celular = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create([
        'nombre' => 'Celular Samsung', 'tipo_activo_id' => $this->tipoMovil->id, 'categoria_id' => $this->categoriaCelular->id,
    ]);

    $this->unidad = fn (array $estado = [], ?Almacen $almacen = null): UnidadActivo => UnidadActivo::factory()
        ->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($almacen ?? $this->datos['almacenA'])->create($estado);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 12,
    ));

    $this->existencias = fn (array $filtros = []) => $this->actingAs($this->admin)->get('/inventario?'.http_build_query($filtros));
});

it('el filtro «Seguimiento individual» devuelve los activos individuales existentes', function () {
    ($this->unidad)();

    ($this->existencias)(['control' => 'individual'])
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('individuales.total', 1)
            ->where('individuales.data.0.activo', 'Celular Samsung')
            ->where('individuales.data.0.almacen', 'Almacén A'));
});

it('el resumen individual cuenta cada estado real sin inventar cantidades', function () {
    $colaborador = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    ($this->unidad)();
    ($this->unidad)();
    ($this->unidad)(['estado' => 'asignada', 'colaborador_id' => $colaborador->id]);
    ($this->unidad)(['condicion' => CondicionUnidadActivo::EnReparacion]);
    ($this->unidad)(['condicion' => CondicionUnidadActivo::Inservible]);
    ($this->unidad)(['estado' => 'asignada', 'colaborador_id' => $colaborador->id, 'condicion' => CondicionUnidadActivo::Robado]);
    ($this->unidad)(['estado' => 'asignada', 'colaborador_id' => $colaborador->id, 'condicion' => CondicionUnidadActivo::Perdido]);
    ($this->unidad)(['estado' => 'baja', 'dado_de_baja_en' => now()]);

    ($this->existencias)(['control' => 'individual'])
        ->assertInertia(fn ($p) => $p
            ->where('individuales.data.0.total', 8)
            ->where('individuales.data.0.estados', [
                'disponible' => 2, 'asignado' => 1, 'reparacion' => 1, 'inservible' => 1,
                'perdido' => 1, 'robado' => 1, 'baja' => 1,
            ]));
});

it('los filtros de empresa, almacén, tipo y categoría se combinan también para los individuales', function () {
    $otroAlmacen = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacén Norte']);
    ($this->unidad)();
    ($this->unidad)([], $otroAlmacen);
    $laptop = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Laptop']);
    UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($laptop)->for($this->datos['almacenA'])->create();
    $ajeno = Activo::factory()->for($this->datos['empresaB'])->seguimientoIndividual()->create(['nombre' => 'Celular B']);
    UnidadActivo::factory()->for($this->datos['empresaB'], 'empresa')->for($ajeno)->for($this->datos['almacenB'])->create();

    ($this->existencias)(['control' => 'individual', 'empresa_id' => $this->datos['empresaA']->id, 'almacen_id' => $otroAlmacen->id])
        ->assertInertia(fn ($p) => $p->where('individuales.total', 1)->where('individuales.data.0.almacen', 'Almacén Norte'));

    ($this->existencias)(['control' => 'individual', 'tipo_activo_id' => $this->tipoMovil->id, 'categoria_id' => $this->categoriaCelular->id])
        ->assertInertia(fn ($p) => $p->where('individuales.total', 2)->where('individuales.data.0.activo', 'Celular Samsung'));

    ($this->existencias)(['control' => 'individual', 'empresa_id' => $this->datos['empresaB']->id])
        ->assertInertia(fn ($p) => $p->where('individuales.total', 1)->where('individuales.data.0.activo', 'Celular B'));
});

it('los activos por cantidad siguen igual y el filtro «Por cantidad» excluye el resumen individual', function () {
    ($this->unidad)();

    ($this->existencias)(['control' => 'cantidad'])
        ->assertInertia(fn ($p) => $p
            ->where('individuales', null)
            ->where('saldos.total', 1)
            ->where('saldos.data.0.cantidad', 12)
            ->where('saldos.data.0.talla', 'M'));

    ($this->existencias)()
        ->assertInertia(fn ($p) => $p->where('saldos.total', 1)->where('individuales.total', 1));
});

it('exporta el resumen individual con el filtro «Seguimiento individual»', function () {
    ($this->unidad)();

    $this->actingAs($this->admin)
        ->get('/inventario/exportar?control=individual&formato=xlsx')
        ->assertOk()
        ->assertDownload();
});
