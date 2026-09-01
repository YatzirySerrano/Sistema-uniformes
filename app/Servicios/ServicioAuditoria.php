<?php

namespace App\Servicios;

use App\Models\BitacoraAuditoria;
use App\Soporte\ContextoEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Punto único de escritura de la bitácora de auditoría. Nunca se debe llamar a
 * BitacoraAuditoria::create() directamente desde los controladores.
 */
class ServicioAuditoria
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
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
            'empresa_id' => $opciones['empresa_id'] ?? $this->contexto->id(),
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
