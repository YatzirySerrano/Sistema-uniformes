<?php

namespace App\Http\Requests\Concerns;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Resuelve la empresa a la que aplica un Form Request y valida el acceso del
 * usuario. La empresa llega del formulario (alta, campo `empresa_id`) o del
 * modelo de la ruta (edición). Nunca se confía en el `empresa_id` del frontend
 * sin validar `User::puedeAccederEmpresa()`.
 */
trait ResuelveEmpresa
{
    private ?Empresa $empresaResuelta = null;

    /**
     * @param  string  $rutaParametro  nombre del parámetro de ruta del modelo en edición
     */
    public function empresaResuelta(string $rutaParametro = ''): Empresa
    {
        if ($this->empresaResuelta instanceof Empresa) {
            return $this->empresaResuelta;
        }

        $modelo = $rutaParametro !== '' ? $this->route($rutaParametro) : null;

        if ($modelo instanceof Model && $modelo->getAttribute('empresa_id') !== null) {
            $empresaId = (int) $modelo->getAttribute('empresa_id');
        } else {
            $empresaId = (int) $this->input('empresa_id');
        }

        $empresa = $empresaId > 0 ? Empresa::query()->find($empresaId) : null;

        if ($empresa === null || ! $this->user()?->puedeAccederEmpresa($empresa)) {
            throw ValidationException::withMessages([
                'empresa_id' => 'Selecciona una empresa válida a la que tengas acceso.',
            ]);
        }

        return $this->empresaResuelta = $empresa;
    }
}
