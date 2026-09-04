<?php

namespace App\Http\Controllers\Concerns;

/**
 * Mensaje de toast compartido para los endpoints `.../suspendidos/reactivar`
 * (Empresa/Sucursal/Activo): informa cuántos se reactivaron de verdad y, si
 * alguno no pudo por seguir dependiendo de algo inactivo (blindaje
 * multicausa), lo deja claro en vez de un éxito silencioso a medias.
 */
trait ReactivaSuspendidos
{
    /**
     * @param  array{reactivados: int, omitidos: list<array{id: int, motivos: list<string>}>}  $resultado
     * @return array{type: string, message: string}
     */
    protected function toastDeReactivacion(array $resultado): array
    {
        $mensaje = $resultado['reactivados'] > 0
            ? "{$resultado['reactivados']} registro(s) reactivado(s)."
            : 'No había nada que reactivar.';

        if ($resultado['omitidos'] !== []) {
            $mensaje .= ' '.count($resultado['omitidos']).' no se pudo(pudieron) reactivar: aún depende(n) de algo inactivo.';
        }

        return ['type' => $resultado['omitidos'] !== [] ? 'warning' : 'success', 'message' => $mensaje];
    }
}
