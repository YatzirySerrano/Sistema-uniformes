<?php

use App\Acciones\CrearRondaInventarioFisico;
use App\Enums\EstadoInventarioFisico;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\InventarioFisicoUnidad;
use App\Models\UnidadActivo;
use Illuminate\Support\Facades\Storage;

/**
 * Botón "Presente" / "Deshacer" del inventario físico. El backend es la
 * autoridad: marcar es idempotente (una unidad esperada contribuye UNA sola vez
 * a "Encontrados" por más que se repita el POST), desmarcar revierte la marca
 * mientras la ronda siga abierta, y una ronda finalizada es inmutable.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create(['nombre' => 'Laptop Dell']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->unidad = fn (): UnidadActivo => UnidadActivo::factory()
        ->for($this->empresa)->for($this->activo)->for($this->almacen)->create();

    // Ronda en proceso con 3 unidades esperadas, ninguna escaneada.
    ($this->unidad)();
    ($this->unidad)();
    ($this->unidad)();
    $this->ronda = app(CrearRondaInventarioFisico::class)->ejecutar($this->empresa, 'Ronda de prueba', $this->almacen, null, $this->admin->id);
    $this->renglon = fn (): InventarioFisicoUnidad => InventarioFisicoUnidad::query()
        ->where('inventario_fisico_id', $this->ronda->id)->orderBy('id')->firstOrFail();

    $this->marcar = fn (int $renglonId) => $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$this->ronda->id}/unidades/{$renglonId}/presente");
    $this->desmarcar = fn (int $renglonId) => $this->actingAs($this->admin)
        ->deleteJson("/inventarios-fisicos/{$this->ronda->id}/unidades/{$renglonId}/presente");
});

it('marcar presente una vez suma 1 a Encontrados y descuenta de Faltantes', function () {
    $renglon = ($this->renglon)();

    $res = ($this->marcar)($renglon->id)->assertOk();

    expect($res->json('contadores.encontrados'))->toBe(1)
        ->and($res->json('contadores.pendientes'))->toBe(2);

    $renglon->refresh();
    expect($renglon->escaneado_en)->not->toBeNull()
        ->and($renglon->escaneado_por)->toBe($this->admin->id);
});

it('repetir el POST de presente NO vuelve a sumar (idempotente)', function () {
    $renglon = ($this->renglon)();

    ($this->marcar)($renglon->id)->assertOk();
    $segunda = ($this->marcar)($renglon->id)->assertOk();

    expect($segunda->json('contadores.encontrados'))->toBe(1);
});

it('diez POST de presente sobre la misma unidad dejan una sola presencia', function () {
    $renglon = ($this->renglon)();

    $ultima = null;
    for ($i = 0; $i < 10; $i++) {
        $ultima = ($this->marcar)($renglon->id)->assertOk();
    }

    expect($ultima->json('contadores.encontrados'))->toBe(1);

    $renglon->refresh();
    expect($renglon->escaneado_por)->toBe($this->admin->id);
    expect(InventarioFisicoUnidad::query()
        ->where('inventario_fisico_id', $this->ronda->id)
        ->whereNotNull('escaneado_en')->count())->toBe(1);
});

it('la respuesta devuelve la fila actualizada con escaneado_en y clasificación encontrado', function () {
    $renglon = ($this->renglon)();

    $res = ($this->marcar)($renglon->id)->assertOk();

    expect($res->json('unidad.id'))->toBe($renglon->id)
        ->and($res->json('unidad.escaneado_en'))->not->toBeNull()
        ->and($res->json('unidad.escaneado_por'))->toBe($this->admin->name)
        ->and($res->json('unidad.clasificacion'))->toBe('encontrado');
});

it('desmarcar limpia escaneado_en / escaneado_por y devuelve la unidad a Faltantes', function () {
    $renglon = ($this->renglon)();
    ($this->marcar)($renglon->id)->assertOk();

    $res = ($this->desmarcar)($renglon->id)->assertOk();

    expect($res->json('contadores.encontrados'))->toBe(0)
        ->and($res->json('contadores.pendientes'))->toBe(3)
        ->and($res->json('unidad.clasificacion'))->toBe('faltante');

    $renglon->refresh();
    expect($renglon->escaneado_en)->toBeNull()
        ->and($renglon->escaneado_por)->toBeNull();
});

it('se puede volver a marcar después de desmarcar', function () {
    $renglon = ($this->renglon)();

    ($this->marcar)($renglon->id)->assertOk();
    ($this->desmarcar)($renglon->id)->assertOk();
    $res = ($this->marcar)($renglon->id)->assertOk();

    expect($res->json('contadores.encontrados'))->toBe(1);
    expect($renglon->refresh()->escaneado_en)->not->toBeNull();
});

it('una ronda finalizada rechaza marcar, desmarcar y escanear', function () {
    $renglon = ($this->renglon)();
    ($this->marcar)($renglon->id)->assertOk();

    $this->ronda->update(['estado' => EstadoInventarioFisico::Finalizado, 'finalizado_en' => now()]);

    ($this->marcar)($renglon->id)->assertStatus(422);
    ($this->desmarcar)($renglon->id)->assertStatus(422);
    $this->actingAs($this->admin)
        ->postJson("/inventarios-fisicos/{$this->ronda->id}/escanear", ['codigo' => $renglon->unidad->codigo])
        ->assertStatus(422);

    expect($renglon->refresh()->escaneado_en)->not->toBeNull(); // la marca previa se conserva
});

it('un renglón de otra ronda es rechazado', function () {
    ($this->unidad)();
    $otraRonda = app(CrearRondaInventarioFisico::class)->ejecutar($this->empresa, 'Ronda de prueba', $this->almacen, null, $this->admin->id);
    $renglonAjeno = InventarioFisicoUnidad::query()->where('inventario_fisico_id', $otraRonda->id)->firstOrFail();

    ($this->marcar)($renglonAjeno->id)->assertNotFound();
    ($this->desmarcar)($renglonAjeno->id)->assertNotFound();
});

it('un encargado sin permiso de administrar no puede marcar ni desmarcar', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $renglon = ($this->renglon)();

    $this->actingAs($encargado)
        ->postJson("/inventarios-fisicos/{$this->ronda->id}/unidades/{$renglon->id}/presente")
        ->assertForbidden();
    $this->actingAs($encargado)
        ->deleteJson("/inventarios-fisicos/{$this->ronda->id}/unidades/{$renglon->id}/presente")
        ->assertForbidden();
});
