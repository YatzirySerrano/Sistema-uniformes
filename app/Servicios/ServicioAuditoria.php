<?php

namespace App\Servicios;

use App\Models\BitacoraAuditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Punto único de escritura de la bitácora de auditoría. Nunca se debe llamar a
 * BitacoraAuditoria::create() directamente desde los controladores.
 *
 * La empresa afectada se pasa siempre de forma explícita en
 * `$opciones['empresa_id']`: el contexto de empresa se resuelve por recurso /
 * formulario / filtro en el controlador, no desde una empresa activa global.
 */
class ServicioAuditoria
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * @param  array{
     *     descripcion?: string|null,
     *     tipo_entidad?: string|null,
     *     entidad_id?: int|string|null,
     *     valores_anteriores?: iterable<mixed>|null,
     *     valores_nuevos?: iterable<mixed>|null,
     *     motivo?: string|null,
     *     empresa_id?: int|null,
     *     sucursal_id?: int|null,
     * }  $opciones
     */
    public function registrar(string $modulo, string $accion, array $opciones = []): BitacoraAuditoria
    {
        $usuario = Auth::user();

        return BitacoraAuditoria::query()->create([
            'usuario_id' => $usuario?->getKey(),
            'nombre_usuario_snapshot' => $usuario?->name,
            'empresa_id' => $opciones['empresa_id'] ?? null,
            'sucursal_id' => $opciones['sucursal_id'] ?? null,
            'modulo' => $modulo,
            'accion' => $accion,
            'tipo_entidad' => $opciones['tipo_entidad'] ?? null,
            'entidad_id' => $opciones['entidad_id'] ?? null,
            'descripcion' => $opciones['descripcion'] ?? null,
            'valores_anteriores' => $opciones['valores_anteriores'] ?? null,
            'valores_nuevos' => $opciones['valores_nuevos'] ?? null,
            'motivo' => $opciones['motivo'] ?? null,
            'ip' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
        ]);
    }
}
