<?php

use App\Enums\RolSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Servicio;
use App\Models\Sucursal;
use Illuminate\Support\Facades\DB;

/**
 * Administración de personal desde el detalle del Servicio. La única fuente de
 * verdad sigue siendo `colaboradores.servicio_actual_id`; tanto "Colaborador →
 * Cambiar servicio" como "Servicio → Asignar colaboradores" modifican esa
 * misma columna con la misma lógica central (`CambiarServicioColaborador`).
 */
beforeEach(function () {
    sembrarRolesPermisos();

    $this->empresa = Empresa::factory()->create();
    $this->sucursal = Sucursal::factory()->for($this->empresa)->create(['nombre' => 'Cuernavaca']);
    $this->contrato = Contrato::factory()->for($this->empresa)->create(['nombre' => 'Laboratorios Polab']);
    $this->servicio = Servicio::factory()->for($this->contrato)->for($this->sucursal)->create(['nombre' => 'Polab Cuernavaca']);
    $this->admin = usuarioCon(RolSistema::Administrador->value);
});

it('el detalle del servicio muestra el contador y la lista de colaboradores asignados', function () {
    Colaborador::factory()->count(3)->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $this->servicio->id]);

    // Un colaborador con OTRO servicio de la misma empresa no debe aparecer.
    $otroServicio = Servicio::factory()->for($this->contrato)->for($this->sucursal)->create(['nombre' => 'Polab Jiutepec']);
    Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $otroServicio->id]);

    $this->actingAs($this->admin)
        ->get("/servicios/{$this->servicio->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Servicios/Detalle')
            ->where('servicio.colaboradores_actuales', 3)
            ->where('colaboradoresAsignados.total', 3)
            ->has('colaboradoresAsignados.data', 3));
});

it('la lista de colaboradores no incluye personal de otra empresa', function () {
    Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $this->servicio->id]);

    // Inconsistencia imposible por la app pero forzada aquí: colaborador de
    // otra empresa apuntando a este servicio.
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    Colaborador::factory()->for($empresaB)->for($sucursalB)->create(['servicio_actual_id' => $this->servicio->id]);

    $this->actingAs($this->admin)
        ->get("/servicios/{$this->servicio->id}")
        ->assertInertia(fn ($page) => $page->where('colaboradoresAsignados.total', 1));
});

it('pagina la lista de colaboradores del servicio en el servidor', function () {
    Colaborador::factory()->count(25)->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $this->servicio->id]);

    $this->actingAs($this->admin)
        ->get("/servicios/{$this->servicio->id}")
        ->assertInertia(fn ($page) => $page
            ->where('colaboradoresAsignados.total', 25)
            ->where('colaboradoresAsignados.last_page', 3)
            ->has('colaboradoresAsignados.data', 10));

    $this->actingAs($this->admin)
        ->get("/servicios/{$this->servicio->id}?colab_page=3")
        ->assertInertia(fn ($page) => $page
            ->where('colaboradoresAsignados.current_page', 3)
            ->has('colaboradoresAsignados.data', 5));
});

it('busca colaboradores del servicio por nombre y por número de empleado', function () {
    Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create([
        'servicio_actual_id' => $this->servicio->id, 'nombre_completo' => 'Juan Pérez', 'numero_empleado' => 'EMP-0012',
    ]);
    Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create([
        'servicio_actual_id' => $this->servicio->id, 'nombre_completo' => 'Pedro López', 'numero_empleado' => 'EMP-0015',
    ]);

    $this->actingAs($this->admin)
        ->get("/servicios/{$this->servicio->id}?colab_buscar=Juan")
        ->assertInertia(fn ($page) => $page->where('colaboradoresAsignados.total', 1)
            ->where('colaboradoresAsignados.data.0.nombre_completo', 'Juan Pérez'));

    $this->actingAs($this->admin)
        ->get("/servicios/{$this->servicio->id}?colab_buscar=EMP-0015")
        ->assertInertia(fn ($page) => $page->where('colaboradoresAsignados.total', 1)
            ->where('colaboradoresAsignados.data.0.nombre_completo', 'Pedro López'));
});

it('asigna un colaborador al servicio en lote', function () {
    $colaborador = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();

    $this->actingAs($this->admin)
        ->from("/servicios/{$this->servicio->id}")
        ->post("/servicios/{$this->servicio->id}/colaboradores", ['colaborador_ids' => [$colaborador->id]])
        ->assertRedirect()
        ->assertSessionHas('toast')
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->servicio_actual_id)->toBe($this->servicio->id);
});

it('asigna varios colaboradores en una sola petición', function () {
    $colaboradores = Colaborador::factory()->count(4)->for($this->empresa)->for($this->sucursal)->create();

    $this->actingAs($this->admin)
        ->post("/servicios/{$this->servicio->id}/colaboradores", ['colaborador_ids' => $colaboradores->pluck('id')->all()])
        ->assertSessionHasNoErrors();

    expect(Colaborador::query()->where('servicio_actual_id', $this->servicio->id)->count())->toBe(4);
});

it('mover un colaborador desde otro servicio lo cambia y registra auditoría individual', function () {
    $servicioOrigen = Servicio::factory()->for($this->contrato)->for($this->sucursal)->create(['nombre' => 'Polab Jiutepec']);
    $a = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $servicioOrigen->id]);
    $b = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();

    $this->actingAs($this->admin)
        ->post("/servicios/{$this->servicio->id}/colaboradores", [
            'colaborador_ids' => [$a->id, $b->id],
            'motivo' => 'Reestructura de guardias',
        ])
        ->assertSessionHasNoErrors();

    expect($a->fresh()->servicio_actual_id)->toBe($this->servicio->id);
    expect($b->fresh()->servicio_actual_id)->toBe($this->servicio->id);

    // Una entrada de auditoría POR colaborador, nunca un solo registro agregado.
    $entradas = BitacoraAuditoria::query()
        ->where('modulo', 'colaboradores')->where('accion', 'cambiar_servicio')
        ->get();
    expect($entradas)->toHaveCount(2);

    $deA = $entradas->firstWhere('entidad_id', $a->id);
    expect($deA->motivo)->toBe('Reestructura de guardias');
    expect($deA->valores_anteriores['servicio'])->toBe('Polab Jiutepec');
    expect($deA->valores_nuevos['servicio'])->toBe('Polab Cuernavaca');
});

it('quitar del servicio deja servicio_actual_id en null sin tocar nada más', function () {
    $colaborador = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $this->servicio->id]);

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/servicio", ['servicio_id' => null])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->servicio_actual_id)->toBeNull();
});

it('rechaza asignar un colaborador de otra empresa y no lo modifica', function () {
    $empresaB = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->for($empresaB)->create();
    $ajeno = Colaborador::factory()->for($empresaB)->for($sucursalB)->create();

    $this->actingAs($this->admin)
        ->from("/servicios/{$this->servicio->id}")
        ->post("/servicios/{$this->servicio->id}/colaboradores", ['colaborador_ids' => [$ajeno->id]])
        ->assertSessionHasErrors('colaborador_ids.0');

    expect($ajeno->fresh()->servicio_actual_id)->toBeNull();
});

it('un rol sin permiso de edición de colaboradores no puede asignar desde el servicio', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->empresa]);
    $colaborador = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();

    $this->actingAs($encargado)
        ->post("/servicios/{$this->servicio->id}/colaboradores", ['colaborador_ids' => [$colaborador->id]])
        ->assertForbidden();

    expect($colaborador->fresh()->servicio_actual_id)->toBeNull();
});

it('un supervisor fuera del alcance de la empresa no puede asignar al servicio', function () {
    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);
    $colaborador = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();

    $this->actingAs($supervisorAjeno)
        ->post("/servicios/{$this->servicio->id}/colaboradores", ['colaborador_ids' => [$colaborador->id]])
        ->assertForbidden();
});

it('la lista de colaboradores del servicio no crece en consultas con más filas (sin N+1)', function () {
    Colaborador::factory()->count(10)->for($this->empresa)->for($this->sucursal)->create(['servicio_actual_id' => $this->servicio->id]);

    DB::enableQueryLog();
    $this->actingAs($this->admin)->get("/servicios/{$this->servicio->id}")->assertOk();
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($consultas)->toBeLessThan(20);
});
