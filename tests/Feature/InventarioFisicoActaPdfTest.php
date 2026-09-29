<?php

use App\Acciones\AplicarCorreccionesInventarioFisico;
use App\Acciones\CrearRondaInventarioFisico;
use App\Acciones\EscanearUnidadInventarioFisico;
use App\Acciones\FinalizarRondaInventarioFisico;
use App\Acciones\VerificarExistenciaInventarioFisico;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\InventarioFisico;
use App\Models\InventarioFisicoExistencia;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * El PDF de una ronda es su ACTA completa: ambos bloques (unidades
 * identificadas y artículos por cantidad) de forma independiente, con los
 * datos HISTÓRICOS de la ronda. Antes el PDF era la tabla de UNA sección de
 * unidades (la que la pantalla mandaba: `faltantes` por defecto) e ignoraba
 * las existencias, así que una ronda sólo por cantidad salía vacía.
 */
beforeEach(function () {
    Storage::fake('local');
    Pdf::fake();
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->laptop = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create(['nombre' => 'Laptop Dell']);
    $this->camisa = Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisa polo']);
    $this->talla = Talla::factory()->create(['valor' => 'XL']);
    $this->camisa->tallas()->attach($this->talla);

    $this->conUnidad = fn (): UnidadActivo => UnidadActivo::factory()
        ->for($this->empresa)->for($this->laptop)->for($this->almacen)->create();
    $this->conExistencia = fn (int $cantidad) => app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->empresa->id, almacenId: $this->almacen->id,
        activoId: $this->camisa->id, tallaId: $this->talla->id, tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
    ));
    $this->ronda = fn (): InventarioFisico => app(CrearRondaInventarioFisico::class)->ejecutar(
        $this->empresa, 'Ronda trimestral', $this->almacen, 'Revisión de cierre', $this->admin->id,
    );
    $this->verificarTodo = function (InventarioFisico $ronda, int $diferencia = 0): void {
        foreach (InventarioFisicoExistencia::query()->where('inventario_fisico_id', $ronda->id)->get() as $fila) {
            app(VerificarExistenciaInventarioFisico::class)->ejecutar($ronda, $fila, $fila->cantidad_esperada + $diferencia, $this->admin);
        }
    };
    // Lo que envía el botón «Exportar → PDF» del detalle (sección por defecto).
    $this->pedirPdf = fn (InventarioFisico $ronda) => $this->actingAs($this->admin)
        ->get("/inventarios-fisicos/{$ronda->id}/exportar?seccion=faltantes&formato=pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('una ronda sólo con unidades genera el acta con sus unidades', function () {
    $unidad = ($this->conUnidad)();
    $ronda = ($this->ronda)();

    ($this->pedirPdf)($ronda);

    Pdf::assertViewIs('reportes.inventario-fisico-acta');
    Pdf::assertSee([$ronda->folio, 'Ronda trimestral', 'Revisión de cierre', $unidad->codigo, 'Laptop Dell', 'Faltante',
        'Esta ronda no incluye artículos por cantidad.']);
});

it('una ronda sólo con existencias por cantidad genera el acta con sus renglones (ya no sale vacía)', function () {
    ($this->conExistencia)(20);
    $ronda = ($this->ronda)();

    ($this->pedirPdf)($ronda);

    Pdf::assertSee(['Camisa polo', 'XL', '<td class="num">20</td>', 'Sin verificar',
        'Esta ronda no incluye unidades identificadas.']);
});

it('una ronda mixta genera ambos bloques, aunque la sección de la pantalla no tenga filas', function () {
    $unidad = ($this->conUnidad)();
    ($this->conExistencia)(20);
    $ronda = ($this->ronda)();
    // La única unidad queda encontrada ⇒ la sección «faltantes» (la que
    // manda la pantalla) está vacía; el acta aun así la incluye.
    app(EscanearUnidadInventarioFisico::class)->ejecutar($ronda, $unidad->public_token, $this->admin);

    ($this->pedirPdf)($ronda);

    Pdf::assertSee([$unidad->codigo, 'Encontrado', 'Camisa polo', '<td class="num">20</td>']);
    Pdf::assertDontSee(['Esta ronda no incluye unidades identificadas.', 'Esta ronda no incluye artículos por cantidad.']);
});

it('usa el histórico de la ronda, no el estado actual del inventario', function () {
    $unidad = ($this->conUnidad)();
    ($this->conExistencia)(20);
    $ronda = ($this->ronda)();

    // Después de iniciar la ronda: el stock sube a 35 y la unidad se asigna.
    ($this->conExistencia)(15);
    $colaborador = Colaborador::factory()->for($this->empresa)->create(['nombre_completo' => 'Persona Asignada Después']);
    $unidad->update(['colaborador_id' => $colaborador->id]);

    ($this->pedirPdf)($ronda);

    Pdf::assertSee('<td class="num">20</td>');
    Pdf::assertDontSee(['<td class="num">35</td>', 'Persona Asignada Después']);
});

it('una ronda en proceso se identifica como tal y sin firma', function () {
    ($this->conExistencia)(5);
    $ronda = ($this->ronda)();

    ($this->pedirPdf)($ronda);

    Pdf::assertSee(['En proceso', 'La ronda aún no tiene firma de cierre.']);
});

it('una ronda finalizada y firmada sin diferencias muestra firmante y «sin diferencias»', function () {
    ($this->conExistencia)(5);
    $ronda = ($this->ronda)();
    ($this->verificarTodo)($ronda);
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    ($this->pedirPdf)($ronda);

    Pdf::assertSee(['Finalizado', 'Firmado por', $this->admin->name, FinalizarRondaInventarioFisico::TEXTO_ACEPTACION,
        'Sin diferencias: no hay correcciones que aplicar.', 'Coincide']);
});

it('una ronda finalizada con diferencias muestra la diferencia y las correcciones pendientes', function () {
    ($this->conExistencia)(5);
    $ronda = ($this->ronda)();
    ($this->verificarTodo)($ronda, -2);
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);

    ($this->pedirPdf)($ronda);

    Pdf::assertSee(['<td class="num">3</td>', '-2', 'Faltante', 'pendientes de aplicar']);
});

it('una ronda con correcciones aplicadas lo refleja en el acta', function () {
    ($this->conExistencia)(5);
    $ronda = ($this->ronda)();
    ($this->verificarTodo)($ronda, 1);
    app(FinalizarRondaInventarioFisico::class)->ejecutar($ronda->fresh(), firmaDemoBase64(), $this->admin);
    app(AplicarCorreccionesInventarioFisico::class)->ejecutar($ronda->fresh(), $this->admin);

    ($this->pedirPdf)($ronda);

    Pdf::assertSee(['Correcciones <b>aplicadas</b>', '1 movimiento(s) de ajuste']);
});

it('respeta la Policy: sin permiso o fuera de la empresa responde 403', function () {
    ($this->conExistencia)(5);
    $ronda = ($this->ronda)();

    $sinPermiso = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);
    $otraEmpresa = usuarioCon(RolSistema::Supervisor->value, [Empresa::factory()->create()]);

    $this->actingAs($sinPermiso)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=pdf")->assertForbidden();
    $this->actingAs($otraEmpresa)->get("/inventarios-fisicos/{$ronda->id}/exportar?formato=pdf")->assertForbidden();
});
