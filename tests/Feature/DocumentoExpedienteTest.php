<?php

use App\Acciones\SubirDocumentoExpediente;
use App\Acciones\SubirVersionDocumentoExpediente;
use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value);
});

/*
|--------------------------------------------------------------------------
| Alta y versiones
|--------------------------------------------------------------------------
*/

it('sube el primer documento del expediente y crea la versión 1', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => CategoriaDocumentoExpediente::Identificacion->value,
            'nombre' => 'INE',
            'descripcion' => 'Identificación oficial',
            'archivo' => UploadedFile::fake()->create('ine.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect();

    $documento = DocumentoExpediente::query()->where('colaborador_id', $colaborador->id)->first();

    expect($documento)->not->toBeNull()
        ->and($documento->nombre)->toBe('INE')
        ->and($documento->categoria)->toBe(CategoriaDocumentoExpediente::Identificacion)
        ->and($documento->versiones)->toHaveCount(1)
        ->and($documento->versionActual->version)->toBe(1);

    Storage::disk('local')->assertExists($documento->versionActual->ruta);
});

it('"Subido por" refleja a quien subió la VERSIÓN ACTUAL, no a quien creó el documento originalmente', function () {
    $colaborador = $this->datos['colaboradorA'];
    $usuarioA = usuarioCon(RolSistema::Administrador->value);
    $usuarioA->update(['name' => 'Ana Administradora']);
    $usuarioB = usuarioCon(RolSistema::Administrador->value);
    $usuarioB->update(['name' => 'Carlos Directivo']);

    $this->actingAs($usuarioA)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Identificacion->value,
        'nombre' => 'INE',
        'archivo' => UploadedFile::fake()->create('ine-v1.pdf', 100, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'INE')->firstOrFail();

    $this->actingAs($usuarioB)->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/version", [
        'archivo' => UploadedFile::fake()->create('ine-v2.pdf', 100, 'application/pdf'),
    ]);

    $respuesta = $this->actingAs($usuarioA)->get("/colaboradores/{$colaborador->id}/expediente");

    $respuesta->assertInertia(fn ($page) => $page
        ->component('Colaboradores/Expediente')
        ->where('documentos.0.creado_por', 'Ana Administradora')
        ->where('documentos.0.version_actual.subido_por', 'Carlos Directivo')
    );
});

it('sube una nueva versión, incrementa el número y conserva la anterior', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Fiscal->value,
        'nombre' => 'Constancia fiscal',
        'archivo' => UploadedFile::fake()->create('csf.pdf', 100, 'application/pdf'),
    ]);

    $documento = DocumentoExpediente::query()->where('nombre', 'Constancia fiscal')->firstOrFail();
    $rutaAnterior = $documento->versionActual->ruta;

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/version", [
            'archivo' => UploadedFile::fake()->create('csf-actualizada.pdf', 120, 'application/pdf'),
            'comentario' => 'Actualizada por vencimiento',
        ])
        ->assertRedirect();

    $documento->refresh();

    expect($documento->versiones)->toHaveCount(2)
        ->and($documento->versionActual->version)->toBe(2);

    Storage::disk('local')->assertExists($rutaAnterior);
    Storage::disk('local')->assertExists($documento->versionActual->ruta);
});

it('rechaza tipos de archivo no permitidos y archivos demasiado pesados', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => CategoriaDocumentoExpediente::Otros->value,
            'nombre' => 'Archivo raro',
            'archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ])
        ->assertSessionHasErrors('archivo');

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => CategoriaDocumentoExpediente::Otros->value,
            'nombre' => 'Archivo pesado',
            'archivo' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf'),
        ])
        ->assertSessionHasErrors('archivo');

    expect(DocumentoExpediente::query()->count())->toBe(0);
});

it('rechaza una categoría que no existe en el catálogo', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => 'no-existe',
            'nombre' => 'Documento',
            'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ])
        ->assertSessionHasErrors('categoria');
});

it('lista el historial completo de versiones', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Contratos->value,
        'nombre' => 'Contrato',
        'archivo' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Contrato')->firstOrFail();

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/version", [
        'archivo' => UploadedFile::fake()->create('contrato-v2.pdf', 100, 'application/pdf'),
    ]);

    $respuesta = $this->actingAs($this->admin)
        ->getJson("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/versiones");

    $respuesta->assertOk();
    expect($respuesta->json('versiones'))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Eliminar / restaurar + auditoría
|--------------------------------------------------------------------------
*/

it('cambia el estado activo del documento (eliminar/restaurar) y lo audita', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento a eliminar',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento a eliminar')->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/estado")
        ->assertRedirect();

    expect($documento->fresh()->activo)->toBeFalse();

    $this->assertDatabaseHas('bitacora_auditoria', [
        'modulo' => 'colaboradores',
        'accion' => 'expediente-eliminar',
        'entidad_id' => $documento->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/estado")
        ->assertRedirect();

    expect($documento->fresh()->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Autorización / IDOR
|--------------------------------------------------------------------------
*/

it('impide descargar el expediente sin el permiso de descarga', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Confidencial',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Confidencial')->firstOrFail();

    // El Supervisor tiene `expediente-descargar` por defecto (Permisos::porRol());
    // se le revoca explícitamente para probar el gate de forma aislada.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $supervisor->revokePermissionTo('colaboradores.expediente-descargar');
    $supervisor->roles->first()->revokePermissionTo('colaboradores.expediente-descargar');

    $this->actingAs($supervisor)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/descargar")
        ->assertForbidden();
});

it('impide ver el expediente de un colaborador de otra empresa (IDOR)', function () {
    $colaboradorA = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaboradorA->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento A',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento A')->firstOrFail();

    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($supervisorAjeno)
        ->get("/colaboradores/{$colaboradorA->id}/expediente/{$documento->id}/descargar")
        ->assertForbidden();

    $this->actingAs($supervisorAjeno)
        ->get("/colaboradores/{$colaboradorA->id}/expediente")
        ->assertForbidden();
});

it('devuelve 404 si el documento no pertenece al colaborador de la URL', function () {
    $colaboradorA = $this->datos['colaboradorA'];
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    $this->actingAs($this->admin)->post("/colaboradores/{$colaboradorA->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento A',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento A')->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaboradorB->id}/expediente/{$documento->id}/descargar")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Sin archivos huérfanos si falla la base de datos
|--------------------------------------------------------------------------
*/

it('borra el archivo huérfano si falla la base de datos al subir el primer documento', function () {
    // colaborador_id inexistente para forzar una violación de FK dentro de la transacción.
    $colaboradorFalso = Colaborador::factory()->make(['id' => 999999]);

    $accion = app(SubirDocumentoExpediente::class);

    expect(fn () => $accion->ejecutar(
        $colaboradorFalso,
        CategoriaDocumentoExpediente::Otros,
        'Documento fantasma',
        null,
        UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        $this->admin,
    ))->toThrow(Exception::class);

    expect(Storage::disk('local')->allFiles('expedientes/999999'))->toBeEmpty();
});

it('borra el archivo huérfano si falla la base de datos al subir una nueva versión', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento')->firstOrFail();

    // Se borran las filas directamente (sin pasar por el modelo, versión
    // primero por la FK) para dejar el objeto en memoria "vivo" pero
    // inexistente en BD, y así forzar que `firstOrFail()` falle dentro de la
    // transacción.
    DB::table('documento_expediente_versiones')->where('documento_expediente_id', $documento->id)->delete();
    DB::table('documentos_expediente')->where('id', $documento->id)->delete();

    $accion = app(SubirVersionDocumentoExpediente::class);

    expect(fn () => $accion->ejecutar(
        $documento,
        UploadedFile::fake()->create('nueva.pdf', 50, 'application/pdf'),
        null,
        $this->admin,
    ))->toThrow(Exception::class);

    // Sólo debe quedar el archivo de la versión 1 original; el de la versión
    // fallida no debe persistir en disco.
    expect(Storage::disk('local')->allFiles("expedientes/{$colaborador->id}/otros"))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Documentos eliminados: no viajan a un usuario sin permiso de administrar
|--------------------------------------------------------------------------
*/

it('el payload del expediente NO incluye documentos eliminados para un usuario sin permiso de administrar', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento visible',
        'archivo' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
    ]);
    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento eliminado',
        'archivo' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
    ]);
    DocumentoExpediente::query()->where('nombre', 'Documento eliminado')->firstOrFail()->update(['activo' => false]);

    $normal = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $normal->givePermissionTo('colaboradores.expediente-descargar');

    // Aunque intente forzar el filtro por querystring, sin el permiso se ignora.
    $this->actingAs($normal)
        ->get("/colaboradores/{$colaborador->id}/expediente?estado=eliminados")
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Expediente')
            ->where('puedeVerEliminados', false)
            ->where('filtroEstado', 'activos')
            ->where('documentos', fn ($documentos) => collect($documentos)->pluck('nombre')->all() === ['Documento visible'])
        );
});

it('el payload del expediente SÍ puede incluir documentos eliminados para un usuario que puede administrar', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento eliminado',
        'archivo' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
    ]);
    DocumentoExpediente::query()->where('nombre', 'Documento eliminado')->firstOrFail()->update(['activo' => false]);

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaborador->id}/expediente?estado=eliminados")
        ->assertInertia(fn ($page) => $page
            ->component('Colaboradores/Expediente')
            ->where('puedeVerEliminados', true)
            ->where('filtroEstado', 'eliminados')
            ->where('documentos', fn ($documentos) => collect($documentos)->pluck('nombre')->all() === ['Documento eliminado'])
        );
});

it('un usuario normal recibe 404 al intentar previsualizar o descargar un documento eliminado por URL directa', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Notas',
        'archivo' => UploadedFile::fake()->create('notas.txt', 5, 'text/plain'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Notas')->firstOrFail();
    $documento->update(['activo' => false]);

    $normal = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $normal->givePermissionTo('colaboradores.expediente-descargar');

    $this->actingAs($normal)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/ver")
        ->assertNotFound();

    $this->actingAs($normal)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/descargar")
        ->assertNotFound();
});

it('un administrador SÍ puede previsualizar y descargar un documento eliminado', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Notas',
        'archivo' => UploadedFile::fake()->create('notas.txt', 5, 'text/plain'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Notas')->firstOrFail();
    $documento->update(['activo' => false]);

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/ver")
        ->assertOk();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/descargar")
        ->assertOk();
});

it('descargarVersion respeta la misma regla de visibilidad para documentos eliminados', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Con versiones',
        'archivo' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Con versiones')->firstOrFail();
    $version = $documento->versionActual;
    $documento->update(['activo' => false]);

    $normal = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $normal->givePermissionTo('colaboradores.expediente-descargar');

    $this->actingAs($normal)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/versiones/{$version->id}/descargar")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/versiones/{$version->id}/descargar")
        ->assertOk();
});

it('al restaurar un documento vuelve a aparecer en el payload normal (activos)', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento restaurable',
        'archivo' => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento restaurable')->firstOrFail();

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/estado"); // elimina

    $normal = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaA']]);
    $normal->givePermissionTo('colaboradores.expediente-descargar');

    $this->actingAs($normal)
        ->get("/colaboradores/{$colaborador->id}/expediente")
        ->assertInertia(fn ($page) => $page->where('documentos', []));

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/estado"); // restaura

    $this->actingAs($normal)
        ->get("/colaboradores/{$colaborador->id}/expediente")
        ->assertInertia(fn ($page) => $page
            ->where('documentos', fn ($documentos) => collect($documentos)->pluck('nombre')->all() === ['Documento restaurable'])
        );
});

/*
|--------------------------------------------------------------------------
| Preview: mimes ampliados (texto/CSV) sin abrir la puerta a Office
|--------------------------------------------------------------------------
*/

it('permite previsualizar texto plano inline pero sigue rechazando Excel', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Notas',
        'archivo' => UploadedFile::fake()->create('notas.txt', 5, 'text/plain'),
    ]);
    $documentoTexto = DocumentoExpediente::query()->where('nombre', 'Notas')->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documentoTexto->id}/ver")
        ->assertOk();

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Hoja de cálculo',
        'archivo' => UploadedFile::fake()->create('datos.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    ]);
    $documentoExcel = DocumentoExpediente::query()->where('nombre', 'Hoja de cálculo')->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documentoExcel->id}/ver")
        ->assertStatus(415);
});
