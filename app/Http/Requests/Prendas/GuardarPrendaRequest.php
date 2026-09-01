<?php

namespace App\Http\Requests\Prendas;

use App\Models\Prenda;
use App\Soporte\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPrendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $prenda = $this->route('prenda');

        return $prenda instanceof Prenda
            ? $this->user()?->can('update', $prenda) ?? false
            : $this->user()?->can('create', Prenda::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresaId = app(ContextoEmpresa::class)->empresaObligatoria()->getKey();
        $prenda = $this->route('prenda');
        $prendaId = $prenda instanceof Prenda ? $prenda->getKey() : null;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'codigo_interno' => [
                'nullable', 'string', 'max:60',
                Rule::unique('prendas', 'codigo_interno')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->ignore($prendaId),
            ],
            'activa' => ['boolean'],
            'imagen' => ['nullable', 'image', 'max:4096'],
            'tallas' => ['array'],
            'tallas.*' => [
                'integer',
                Rule::exists('tallas', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_interno.unique' => 'El código interno ya está en uso en esta empresa.',
            'tallas.*.exists' => 'Una de las tallas seleccionadas no pertenece a esta empresa.',
        ];
    }
}
