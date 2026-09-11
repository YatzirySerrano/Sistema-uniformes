<?php

namespace App\Servicios;

use App\Enums\CategoriaDocumentoExpediente;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use App\Models\User;
use App\Models\VersionDocumentoExpediente;
use App\Soporte\AccesoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arma el payload del expediente digital de un colaborador (carpetas +
 * documentos + permisos del usuario actual). Única fuente de verdad: la usan
 * tanto la página dedicada del expediente (`DocumentoExpedienteController::index()`)
 * como el perfil del colaborador, que lo embebe como pestaña.
 *
 * AISLAMIENTO MULTIEMPRESA (traslado de colaborador entre empresas):
 *
 * - Categorías PERSONALES (`CategoriaDocumentoExpediente::viajaConLaPersona()`
 *   → Identificación / Fiscal / Seguridad social): viajan con la persona; las
 *   ve el CUSTODIO ACTUAL (acceso a la empresa actual del colaborador) o el
 *   alcance global. Un usuario con acceso sólo por empresa de ORIGEN NO es
 *   custodio y NO las ve.
 * - Categorías EMPRESARIALES: se filtran POR VERSIÓN. Cada versión sólo es
 *   visible para quien tenga acceso a la empresa bajo la que se incorporó
 *   (`version->empresaOrigenId()`) — sea el custodio actual de esa empresa o un
 *   usuario con acceso histórico a la empresa de origen. La "versión vigente"
 *   que ve un usuario es la de número más alto ENTRE LAS VISIBLES para él.
 *
 * La AUTORIZACIÓN de las rutas de LECTURA (`puedeAbrirExpediente()`) admite el
 * acceso a la empresa ACTUAL o a la empresa de origen de al menos una versión
 * empresarial. Las rutas de ESCRITURA siguen exigiendo empresa ACTUAL
 * (`ColaboradorPolicy::administrarExpediente`), sin cambios.
 */
class ServicioExpediente
{
    /**
     * Mimes que se pueden mostrar inline (`Content-Disposition: inline`) en
     * el navegador. Todo lo demás (Excel, Word…) sólo se ofrece para
     * descargar, nunca inline.
     *
     * @var list<string>
     */
    public const MIMES_PREVIEW_INLINE = [
        'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'text/csv',
    ];

    public static function esPrevisualizable(string $mime): bool
    {
        return in_array($mime, self::MIMES_PREVIEW_INLINE, true);
    }

    /**
     * Estados de filtro válidos para un usuario que SÍ puede ver eliminados.
     * Cualquier otro valor (incluyendo el de un usuario sin ese permiso) cae
     * en "activos" — los documentos eliminados son contenido archivado, nunca
     * el default.
     *
     * @var list<string>
     */
    private const FILTROS_CON_ELIMINADOS = ['eliminados', 'todos'];

    /**
     * ¿El usuario puede ver ESTA versión concreta?
     *
     * - Alcance global → siempre.
     * - Personal (viaja con la persona) → sólo el CUSTODIO ACTUAL (acceso a la
     *   empresa actual del colaborador). Un usuario con acceso sólo histórico
     *   NO es custodio.
     * - Empresarial → por empresa de ORIGEN de la versión (custodio actual de
     *   esa empresa o acceso histórico a ella). Sin origen registrado
     *   (no debería tras el backfill) → fail-closed: sólo el custodio actual.
     */
    public function usuarioPuedeVerVersion(User $usuario, DocumentoExpediente $documento, VersionDocumentoExpediente $version): bool
    {
        if ($usuario->tieneAlcanceGlobal()) {
            return true;
        }

        $documento->loadMissing('colaborador:id,empresa_id');
        $esCustodioActual = $documento->colaborador !== null
            && $usuario->puedeAccederEmpresa($documento->colaborador->empresa_id);

        if ($documento->categoria->viajaConLaPersona()) {
            return $esCustodioActual;
        }

        $empresaOrigenId = $version->empresaOrigenId();

        if ($empresaOrigenId === null) {
            return $esCustodioActual;
        }

        return $usuario->puedeAccederEmpresa($empresaOrigenId);
    }

    /**
     * Versiones del documento visibles para el usuario, ordenadas de la más
     * reciente a la más antigua.
     *
     * @return Collection<int, VersionDocumentoExpediente>
     */
    public function versionesVisibles(User $usuario, DocumentoExpediente $documento): Collection
    {
        return $documento->versiones
            ->filter(fn (VersionDocumentoExpediente $v): bool => $this->usuarioPuedeVerVersion($usuario, $documento, $v))
            ->sortByDesc('version')
            ->values();
    }

    /**
     * Versión VIGENTE para el usuario: la de número más alto entre las que
     * puede ver. `null` si no hay ninguna visible.
     */
    public function versionVigenteVisible(User $usuario, DocumentoExpediente $documento): ?VersionDocumentoExpediente
    {
        return $this->versionesVisibles($usuario, $documento)->first();
    }

    /**
     * ¿El documento (slot) aparece para el usuario? Personal → sólo el custodio
     * actual (o global). Empresarial → sólo si tiene al menos una versión
     * visible.
     */
    public function slotVisible(User $usuario, DocumentoExpediente $documento): bool
    {
        if ($usuario->tieneAlcanceGlobal()) {
            return true;
        }

        if ($documento->categoria->viajaConLaPersona()) {
            $documento->loadMissing('colaborador:id,empresa_id');

            return $documento->colaborador !== null
                && $usuario->puedeAccederEmpresa($documento->colaborador->empresa_id);
        }

        return $this->versionesVisibles($usuario, $documento)->isNotEmpty();
    }

    /**
     * ¿El usuario puede abrir las rutas de LECTURA del expediente de este
     * colaborador? Requiere el permiso correspondiente Y acceso a la empresa
     * ACTUAL del colaborador O a la empresa de ORIGEN de al menos una versión
     * empresarial de su expediente (acceso histórico, sólo lectura).
     *
     * @param  'ver'|'descargar'  $accion
     */
    public function puedeAbrirExpediente(User $usuario, Colaborador $colaborador, string $accion): bool
    {
        $permiso = $accion === 'descargar'
            ? 'colaboradores.expediente-descargar'
            : 'colaboradores.expediente-ver';

        if (! $usuario->can($permiso)) {
            return false;
        }

        return $usuario->tieneAlcanceGlobal()
            || $usuario->puedeAccederEmpresa($colaborador->empresa_id)
            || $this->tieneAccesoHistorico($usuario, $colaborador);
    }

    /**
     * ¿El usuario tiene acceso histórico de LECTURA al expediente de este
     * colaborador? = tiene acceso a la empresa de origen de al menos una
     * versión de una categoría EMPRESARIAL de su expediente. Nunca por
     * categorías personales (esas viajan con el custodio actual).
     */
    public function tieneAccesoHistorico(User $usuario, Colaborador $colaborador): bool
    {
        if ($usuario->tieneAlcanceGlobal() || $usuario->puedeAccederEmpresa($colaborador->empresa_id)) {
            return true;
        }

        $idsAutorizados = app(AccesoEmpresa::class)->idsAutorizados($usuario);

        if ($idsAutorizados->isEmpty()) {
            return false;
        }

        $empresariales = collect(CategoriaDocumentoExpediente::cases())
            ->reject(fn (CategoriaDocumentoExpediente $c): bool => $c->viajaConLaPersona())
            ->map(fn (CategoriaDocumentoExpediente $c): string => $c->value)
            ->all();

        return DB::table('documento_expediente_version_empresa as ve')
            ->join('documento_expediente_versiones as v', 'v.id', '=', 've.documento_expediente_version_id')
            ->join('documentos_expediente as d', 'd.id', '=', 'v.documento_expediente_id')
            ->where('d.colaborador_id', $colaborador->getKey())
            ->whereIn('d.categoria', $empresariales)
            ->whereIn('ve.empresa_id', $idsAutorizados)
            ->exists();
    }

    /**
     * Cuántos slots del expediente ve el usuario (para el KPI del perfil).
     */
    public function contarSlotsVisibles(Colaborador $colaborador, User $usuario): int
    {
        return $this->cargarDocumentos($colaborador)
            ->filter(fn (DocumentoExpediente $d): bool => $d->activo && $this->slotVisible($usuario, $d))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Colaborador $colaborador, User $usuario, string $filtroEstado = 'activos'): array
    {
        $puedeVerEliminados = $usuario->can('administrarExpediente', $colaborador);
        $filtroAplicado = $puedeVerEliminados && in_array($filtroEstado, self::FILTROS_CON_ELIMINADOS, true)
            ? $filtroEstado
            : 'activos';

        $documentos = $this->cargarDocumentos($colaborador)
            ->filter(function (DocumentoExpediente $d) use ($filtroAplicado): bool {
                return match ($filtroAplicado) {
                    'activos' => $d->activo,
                    'eliminados' => ! $d->activo,
                    default => true,
                };
            })
            ->filter(fn (DocumentoExpediente $d): bool => $this->slotVisible($usuario, $d))
            ->sortBy('nombre')
            ->values();

        return [
            'categorias' => collect(CategoriaDocumentoExpediente::cases())
                ->map(fn (CategoriaDocumentoExpediente $c): array => ['valor' => $c->value, 'etiqueta' => $c->etiqueta()])
                ->all(),
            'documentos' => $documentos->map(fn (DocumentoExpediente $d): array => $this->documentoPayload($d, $usuario))->all(),
            // Escritura: sólo empresa ACTUAL. Descarga: incluye acceso histórico
            // (coincide con lo que permiten las rutas).
            'puedeAdministrar' => $usuario->can('administrarExpediente', $colaborador),
            'puedeDescargar' => $this->puedeAbrirExpediente($usuario, $colaborador, 'descargar'),
            'puedeVerEliminados' => $puedeVerEliminados,
            'filtroEstado' => $filtroAplicado,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentoPayload(DocumentoExpediente $documento, User $usuario): array
    {
        $visibles = $this->versionesVisibles($usuario, $documento);
        $actual = $visibles->first();

        return [
            'id' => $documento->id,
            'categoria' => $documento->categoria->value,
            'nombre' => $documento->nombre,
            'descripcion' => $documento->descripcion,
            'activo' => $documento->activo,
            // La categoría queda FIJA en cuanto el slot tiene su primera versión
            // (frontera histórica de visibilidad): el frontend la muestra en
            // sólo lectura y el backend rechaza el cambio.
            'categoria_bloqueada' => $documento->versiones->isNotEmpty(),
            'creado_por' => $documento->creadoPor?->name,
            'total_versiones' => $visibles->count(),
            'version_actual' => $actual === null ? null : [
                'version' => $actual->version,
                'nombre_archivo_original' => $actual->nombre_archivo_original,
                'mime' => $actual->mime,
                'extension' => $actual->extension,
                'peso_bytes' => $actual->peso_bytes,
                'subido_en' => $actual->created_at?->toIso8601String(),
                'subido_por' => $actual->subidoPor?->name,
                'puede_previsualizar' => self::esPrevisualizable($actual->mime),
            ],
        ];
    }

    /**
     * Carga los slots del colaborador con TODAS sus versiones (y su empresa de
     * origen). Un expediente tiene pocos slots y pocas versiones cada uno.
     *
     * @return Collection<int, DocumentoExpediente>
     */
    private function cargarDocumentos(Colaborador $colaborador): Collection
    {
        return $colaborador->documentosExpediente()
            ->with([
                'versiones' => fn ($q) => $q->with(['subidoPor:id,name', 'origenEmpresa:id,documento_expediente_version_id,empresa_id'])->orderByDesc('version'),
                'creadoPor:id,name',
                'colaborador:id,empresa_id',
            ])
            ->orderBy('nombre')
            ->get();
    }
}
