<?php

use App\Acciones\SubirVersionDocumentoExpediente;
use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Sucursal;
use App\Models\VersionDocumentoExpediente;
use App\Models\VersionExpedienteEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Aislamiento histórico multiempresa tras trasladar un colaborador de una
 * empresa a otra. Comportamiento HTTP real (rutas + Policies):
 *
 * - La empresa de ORIGEN conserva LECTURA de SUS documentos históricos del
 *   colaborador (categorías empresariales, por versión). NO obtiene acceso al
 *   perfil actual ni puede escribir.
 * - La empresa de DESTINO ve las versiones de destino + los documentos
 *   personales que viajan (Identificación / Fiscal / Seguridad social).
 * - Admin / Superadmin ven todo.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->dasti = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->siesa = Empresa::factory()->create(['nombre_comercial' => 'SIESA']);
    $sucSiesa = Sucursal::factory()->for($this->siesa)->create();

    // Juan YA fue trasladado a SIESA (empresa actual = SIESA).
    $this->juan = Colaborador::factory()->for($this->siesa)->for($sucSiesa)->create(['nombre_completo' => 'Juan']);

    $this->admin = usuarioCon(RolSistema::Administrador->value);        // alcance global
    $this->userDasti = usuarioCon(RolSistema::Supervisor->value, [$this->dasti]);
    $this->userSiesa = usuarioCon(RolSistema::Supervisor->value, [$this->siesa]);

    $this->slot = fn (CategoriaDocumentoExpediente $cat, string $nombre): DocumentoExpediente => DocumentoExpediente::query()->create([
        'colaborador_id' => $this->juan->id, 'categoria' => $cat, 'nombre' => $nombre, 'creado_por' => $this->admin->id,
    ]);

    $this->version = function (DocumentoExpediente $slot, int $num, Empresa $origen): VersionDocumentoExpediente {
        $v = VersionDocumentoExpediente::query()->create([
            'documento_expediente_id' => $slot->id, 'version' => $num,
            'ruta' => "exp/{$slot->id}/v{$num}.pdf", 'nombre_archivo_original' => "v{$num}.pdf",
            'mime' => 'application/pdf', 'extension' => 'pdf', 'peso_bytes' => 10,
            'hash_sha256' => str_repeat('a', 64), 'subido_por' => $this->admin->id,
        ]);
        VersionExpedienteEmpresa::query()->create([
            'documento_expediente_version_id' => $v->id, 'empresa_id' => $origen->id,
        ]);
        Storage::disk('local')->put($v->ruta, 'x');

        return $v;
    };

    // Slot empresarial "Contratos": V1 DASTI, V2 SIESA.
    $this->contratos = ($this->slot)(CategoriaDocumentoExpediente::Contratos, 'Contrato de Juan');
    $this->v1 = ($this->version)($this->contratos, 1, $this->dasti);
    $this->v2 = ($this->version)($this->contratos, 2, $this->siesa);

    // INE personal (viaja) subida bajo DASTI.
    $this->ine = ($this->slot)(CategoriaDocumentoExpediente::Identificacion, 'INE');
    $this->ineV = ($this->version)($this->ine, 1, $this->dasti);

    $this->base = "/colaboradores/{$this->juan->id}";
});

/*
|--------------------------------------------------------------------------
| Usuario con acceso SÓLO a la empresa de ORIGEN (DASTI)
|--------------------------------------------------------------------------
*/

it('el perfil actual del colaborador sigue siendo 403 para el usuario de la empresa de origen', function () {
    $this->actingAs($this->userDasti)->get($this->base)->assertForbidden();
});

it('el usuario de origen SÍ puede abrir el expediente y ve sólo sus versiones DASTI', function () {
    $this->actingAs($this->userDasti)->get("{$this->base}/expediente")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // ve el slot empresarial (tiene versión DASTI) pero NO la INE personal
            ->where('documentos', fn ($docs) => collect($docs)->pluck('id')->all() === [$this->contratos->id])
            ->where('puedeAdministrar', false));

    $res = $this->actingAs($this->userDasti)
        ->getJson("{$this->base}/expediente/{$this->contratos->id}/versiones")->assertOk();
    expect(collect($res->json('versiones'))->pluck('version')->all())->toBe([1]);
});

it('el usuario de origen descarga V1 (DASTI) pero no V2 (SIESA)', function () {
    $this->actingAs($this->userDasti)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$this->v1->id}/descargar")
        ->assertOk()->assertDownload('v1-v1.pdf');

    $this->actingAs($this->userDasti)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$this->v2->id}/descargar")
        ->assertNotFound();

    // "versión vigente" para el usuario DASTI = V1 (no la global V2)
    $this->actingAs($this->userDasti)
        ->get("{$this->base}/expediente/{$this->contratos->id}/descargar")
        ->assertOk()->assertDownload('v1.pdf');
});

it('el usuario de origen NO ve la INE personal (viaja con el custodio actual)', function () {
    $this->actingAs($this->userDasti)
        ->getJson("{$this->base}/expediente/{$this->ine->id}/versiones")
        ->assertOk()->assertJsonPath('versiones', []);

    $this->actingAs($this->userDasti)
        ->get("{$this->base}/expediente/{$this->ine->id}/descargar")
        ->assertNotFound();
});

it('el usuario de origen NO puede escribir en el expediente', function () {
    // subir documento nuevo
    $this->actingAs($this->userDasti)
        ->post("{$this->base}/expediente", [
            'categoria' => 'contratos', 'nombre' => 'Intruso',
            'archivo' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
        ])->assertForbidden();

    // subir nueva versión
    $this->actingAs($this->userDasti)
        ->post("{$this->base}/expediente/{$this->contratos->id}/version", [
            'archivo' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
        ])->assertForbidden();

    // editar metadata / cambiar categoría
    $this->actingAs($this->userDasti)
        ->put("{$this->base}/expediente/{$this->contratos->id}", [
            'categoria' => 'contratos', 'nombre' => 'Renombrado', 'descripcion' => null,
        ])->assertForbidden();

    // toggle (eliminar)
    $this->actingAs($this->userDasti)
        ->post("{$this->base}/expediente/{$this->contratos->id}/estado")->assertForbidden();

    expect($this->contratos->fresh()->nombre)->toBe('Contrato de Juan')
        ->and(VersionDocumentoExpediente::query()->where('documento_expediente_id', $this->contratos->id)->count())->toBe(2);
});

it('un usuario sin acceso a NINGUNA empresa con versión histórica recibe 403 en el expediente', function () {
    $otra = Empresa::factory()->create();
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$otra]);

    $this->actingAs($ajeno)->get("{$this->base}/expediente")->assertForbidden();
    $this->actingAs($ajeno)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$this->v1->id}/descargar")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Usuario con acceso SÓLO a la empresa de DESTINO (SIESA)
|--------------------------------------------------------------------------
*/

it('el usuario de destino ve las versiones SIESA y no las DASTI, más los personales', function () {
    $res = $this->actingAs($this->userSiesa)
        ->getJson("{$this->base}/expediente/{$this->contratos->id}/versiones")->assertOk();
    expect(collect($res->json('versiones'))->pluck('version')->all())->toBe([2]);

    $this->actingAs($this->userSiesa)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$this->v1->id}/descargar")
        ->assertNotFound();

    $this->actingAs($this->userSiesa)
        ->get("{$this->base}/expediente/{$this->contratos->id}/descargar")
        ->assertOk()->assertDownload('v2.pdf');

    // INE personal subida bajo DASTI → accesible para el custodio actual SIESA
    $this->actingAs($this->userSiesa)
        ->get("{$this->base}/expediente/{$this->ine->id}/descargar")->assertOk();
});

/*
|--------------------------------------------------------------------------
| Admin global
|--------------------------------------------------------------------------
*/

it('el admin global ve ambas versiones y descarga cualquiera', function () {
    $res = $this->actingAs($this->admin)
        ->getJson("{$this->base}/expediente/{$this->contratos->id}/versiones")->assertOk();
    expect(collect($res->json('versiones'))->pluck('version')->all())->toBe([2, 1]);

    $this->actingAs($this->admin)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$this->v1->id}/descargar")->assertOk();
    $this->actingAs($this->admin)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$this->v2->id}/descargar")->assertOk();
});

/*
|--------------------------------------------------------------------------
| Caso V1 DASTI / V2 SIESA / V3 DASTI — cada usuario ve sólo lo suyo
|--------------------------------------------------------------------------
*/

it('con V1-DASTI, V2-SIESA, V3-DASTI cada usuario ve sólo sus versiones y su vigente', function () {
    $v3 = ($this->version)($this->contratos, 3, $this->dasti);

    $dasti = collect($this->actingAs($this->userDasti)
        ->getJson("{$this->base}/expediente/{$this->contratos->id}/versiones")->json('versiones'))->pluck('version')->all();
    $siesa = collect($this->actingAs($this->userSiesa)
        ->getJson("{$this->base}/expediente/{$this->contratos->id}/versiones")->json('versiones'))->pluck('version')->all();
    $adminV = collect($this->actingAs($this->admin)
        ->getJson("{$this->base}/expediente/{$this->contratos->id}/versiones")->json('versiones'))->pluck('version')->all();

    expect($dasti)->toBe([3, 1])
        ->and($siesa)->toBe([2])
        ->and($adminV)->toBe([3, 2, 1]);

    // vigente para cada uno
    $this->actingAs($this->userDasti)->get("{$this->base}/expediente/{$this->contratos->id}/descargar")
        ->assertOk()->assertDownload('v3.pdf');
    $this->actingAs($this->userSiesa)->get("{$this->base}/expediente/{$this->contratos->id}/descargar")
        ->assertOk()->assertDownload('v2.pdf');
    $this->actingAs($this->admin)->get("{$this->base}/expediente/{$this->contratos->id}/descargar")
        ->assertOk()->assertDownload('v3.pdf');

    // el usuario SIESA sigue sin poder bajar V3 aunque exista
    $this->actingAs($this->userSiesa)
        ->get("{$this->base}/expediente/{$this->contratos->id}/versiones/{$v3->id}/descargar")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Regresión Entregas / Devoluciones — aislamiento por empresa congelada
|--------------------------------------------------------------------------
*/

it('una entrega histórica de DASTI responde 403 para un usuario de SIESA', function () {
    $sucDasti = Sucursal::factory()->for($this->dasti)->create();
    $colDasti = Colaborador::factory()->for($this->dasti)->for($sucDasti)->create();
    $entrega = EntregaUniforme::factory()->for($this->dasti)->for($sucDasti)->for($colDasti)
        ->create(['estado' => EstadoEntrega::Firmada]);

    $this->actingAs($this->userSiesa)->get("/entregas/{$entrega->id}")->assertForbidden();
});

it('SubirVersionDocumentoExpediente etiqueta la versión con la empresa vigente del colaborador', function () {
    app(SubirVersionDocumentoExpediente::class)->ejecutar(
        $this->contratos,
        UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'),
        null,
        $this->admin,
    );

    $v = $this->contratos->versiones()->latest('version')->firstOrFail();
    expect($v->fresh()->empresaOrigenId())->toBe($this->siesa->id);
});
