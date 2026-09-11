<?php

namespace App\Models;

use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\EstadoVisibleUnidad;
use App\Enums\PerfilTecnicoUnidad;
use App\Soporte\ResolverPerfilTecnicoUnidad;
use Database\Factories\UnidadActivoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Objeto físico individual de un Activo con `tipo_control = individual`
 * (una laptop, una silla, una herramienta costosa…). Es la fuente de verdad
 * física: no hay saldo agregado editable para estas unidades, los conteos se
 * derivan de esta tabla.
 *
 * `codigo` lo genera el sistema (`App\Soporte\ServicioGeneradorCodigos`) y es
 * estable de por vida — no depende del almacén ni cambia si la unidad se
 * transfiere. `public_token` (UUID) es el identificador permanente y no
 * enumerable usado en el QR (`/activos/unidades/{public_token}`).
 *
 * `estado` (ciclo de posesión) y `condicion` (salud física) son ejes
 * independientes — ver `EstadoUnidadActivo` / `CondicionUnidadActivo`.
 * `colaborador_id` es sólo la referencia de ESTADO ACTUAL; el histórico real
 * vive en movimientos/entregas/devoluciones.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $activo_id
 * @property int $almacen_id
 * @property string $codigo
 * @property string $public_token
 * @property EstadoUnidadActivo $estado
 * @property CondicionUnidadActivo $condicion
 * @property int|null $colaborador_id
 * @property Carbon|null $dado_de_baja_en
 * @property string|null $motivo_baja
 * @property string|null $incidencia_motivo
 * @property Carbon|null $incidencia_registrada_en
 * @property int|null $incidencia_registrada_por
 */
class UnidadActivo extends Model
{
    /** @use HasFactory<UnidadActivoFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'unidades_activo';

    protected $fillable = [
        'empresa_id',
        'activo_id',
        'almacen_id',
        'codigo',
        'public_token',
        'estado',
        'condicion',
        'observaciones',
        'colaborador_id',
        'registrado_por',
        'dado_de_baja_en',
        'motivo_baja',
        'incidencia_motivo',
        'incidencia_registrada_en',
        'incidencia_registrada_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoUnidadActivo::class,
            'condicion' => CondicionUnidadActivo::class,
            'dado_de_baja_en' => 'datetime',
            'incidencia_registrada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Activo, $this>
     */
    public function activo(): BelongsTo
    {
        return $this->belongsTo(Activo::class);
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class);
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function incidenciaRegistradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incidencia_registrada_por');
    }

    /**
     * Datos técnicos del equipo (marca / modelo / IMEI / número / operador /
     * plan). Sólo aplica a unidades cuyo activo tiene perfil técnico
     * (`perfilTecnico()` no nulo); en el resto no se muestra ni se pide.
     *
     * @return HasOne<UnidadActivoEspecificacion, $this>
     */
    public function especificacion(): HasOne
    {
        return $this->hasOne(UnidadActivoEspecificacion::class);
    }

    /**
     * Perfil técnico del activo de esta unidad (Celular / Computadora / Tablet
     * / null). Fuente única: `ResolverPerfilTecnicoUnidad` (por `codigo` de
     * categoría/tipo, nunca por nombre).
     */
    public function perfilTecnico(): ?PerfilTecnicoUnidad
    {
        $this->loadMissing('activo');

        if ($this->activo === null) {
            return null;
        }

        return app(ResolverPerfilTecnicoUnidad::class)->paraActivo($this->activo);
    }

    /**
     * Datos del equipo listos para pintar: sólo los campos del perfil, con su
     * etiqueta y valor (`null` → "Sin especificar" lo resuelve la UI). Lista
     * vacía si la unidad no tiene perfil técnico.
     *
     * @return list<array{campo: string, etiqueta: string, valor: string|null}>
     */
    public function datosEquipo(): array
    {
        $perfil = $this->perfilTecnico();

        if ($perfil === null) {
            return [];
        }

        $this->loadMissing('especificacion');
        $esp = $this->especificacion;

        $etiquetas = [
            'marca' => 'Marca',
            'modelo' => 'Modelo',
            'imei' => 'IMEI',
            'numero_telefonico' => 'Número telefónico',
            'operador' => 'Operador',
            'plan' => 'Plan',
        ];

        return array_map(fn (string $campo): array => [
            'campo' => $campo,
            'etiqueta' => $etiquetas[$campo],
            'valor' => $esp?->{$campo},
        ], $perfil->camposVisibles());
    }

    /**
     * Sólo una unidad en almacén, no asignada, activa y funcionando puede
     * entregarse. Backend es la fuente de verdad (nunca sólo el frontend).
     */
    public function esEntregable(): bool
    {
        return $this->estado === EstadoUnidadActivo::EnAlmacen
            && $this->condicion === CondicionUnidadActivo::Funcionando;
    }

    /**
     * Ubicación operativa actual — única fuente de verdad, reusada por
     * índice y detalle para no duplicar la regla (mismo espíritu que
     * `DescripcionAuditoria`). Nunca se persiste (`unidad_activo.servicio_id`
     * NO existe a propósito): se deriva en vivo de `colaborador.servicioActual`
     * cuando está asignada, o del almacén cuando no lo está.
     *
     * - `en_almacen` → ubicación = el almacén (comportamiento previo, intacto).
     * - `asignada` → ubicación = servicio VIGENTE del colaborador (o
     *   "sin_servicio" si el colaborador no tiene uno asignado).
     * - `baja` → sin ubicación operativa.
     *
     * @return array{tipo: 'almacen', almacen: array{id: int, nombre: string}|null}|array{tipo: 'servicio', colaborador: string, contrato: string, servicio: string}|array{tipo: 'sin_servicio', colaborador: string|null}|array{tipo: 'baja'}
     */
    public function ubicacionOperativa(): array
    {
        if ($this->estado === EstadoUnidadActivo::Baja) {
            return ['tipo' => 'baja'];
        }

        if ($this->estado === EstadoUnidadActivo::EnAlmacen) {
            return [
                'tipo' => 'almacen',
                'almacen' => $this->almacen === null ? null : ['id' => $this->almacen->id, 'nombre' => $this->almacen->nombre],
            ];
        }

        // Asignada: la ubicación viene SIEMPRE del servicio vigente del
        // colaborador — nunca de un dato propio de la unidad (evita
        // desincronización, ver módulo Contratos/Servicios).
        $servicio = $this->colaborador?->servicioActual;

        if ($servicio === null) {
            return [
                'tipo' => 'sin_servicio',
                'colaborador' => $this->colaborador?->nombre_completo,
            ];
        }

        return [
            'tipo' => 'servicio',
            'colaborador' => $this->colaborador->nombre_completo,
            'contrato' => $servicio->contrato->nombre,
            'servicio' => $servicio->nombre,
        ];
    }

    /**
     * Estado visible consolidado (ver `EstadoVisibleUnidad`). `Inservible` se
     * agrupa junto con `EnReparacion` bajo "Reparación": ambas son "fuera de
     * operación con diagnóstico abierto" de cara al usuario, aunque
     * `condicion` (el eje real) conserva la distinción — nunca se colapsa
     * "Inservible" a "Baja" automáticamente, esa sigue siendo una operación
     * explícita y auditada (`App\Acciones\DarDeBajaUnidadActivo`).
     */
    public function estadoVisible(): EstadoVisibleUnidad
    {
        return EstadoVisibleUnidad::resolver($this->estado, $this->condicion);
    }
}
