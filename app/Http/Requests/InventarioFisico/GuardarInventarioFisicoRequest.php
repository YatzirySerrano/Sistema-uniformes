<?php

namespace App\Http\Requests\InventarioFisico;

use App\Http\Requests\Concerns\ResuelveEmpresa;
use App\Models\InventarioFisico;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de una ronda de inventario físico INTEGRAL de una empresa. La empresa
 * llega en `empresa_id` y se valida contra el alcance del usuario
 * (`ResuelveEmpresa`); nunca se confía en el frontend. No hay "alcance" que
 * elegir: el backend arma el universo completo (existencias por cantidad de
 * sus almacenes + unidades identificadas). Un `almacen_id` que llegue se
 * ignora (no está en las reglas).
 */
class GuardarInventarioFisicoRequest extends FormRequest
{
    use ResuelveEmpresa;

    public function authorize(): bool
    {
        return $this->user()?->can('create', InventarioFisico::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $this->empresaResuelta();

        return [
            'empresa_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa de la ronda.',
            'nombre.required' => 'Ponle un nombre a la ronda (por ejemplo «Inventario diciembre 2026 – DASTI»).',
        ];
    }
}
