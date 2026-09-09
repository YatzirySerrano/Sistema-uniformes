<?php

namespace App\Http\Requests\Servicios;

use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Contrato;
use App\Models\Servicio;
use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Validación de alta y edición de servicios. `Servicio` NO tiene `empresa_id`
 * propio (se deriva de `contrato->empresa_id`), así que a diferencia del
 * resto de módulos NO se usa el trait `ResuelveEmpresa` (asume que el modelo
 * valida contra su propia columna `empresa_id`) — la empresa se resuelve
 * aquí mismo a partir de `contrato_id` y se valida el acceso del usuario.
 *
 * `contrato_id` y `sucursal_id` deben pertenecer a la MISMA empresa: se
 * revalida siempre en `withValidator()`, sin confiar en lo que muestre el
 * combobox del frontend.
 */
class GuardarServicioRequest extends FormRequest
{
    use NormalizaEntrada;

    private ?Contrato $contratoResuelto = null;

    public function authorize(): bool
    {
        $servicio = $this->route('servicio');

        return $servicio instanceof Servicio
            ? ($this->user()?->can('update', $servicio) ?? false)
            : ($this->user()?->can('create', Servicio::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $nombre = $this->limpiar($this->input('nombre'));

        $this->merge([
            'nombre' => $nombre === null ? null : (string) preg_replace('/\s+/u', ' ', $nombre),
            'direccion' => $this->limpiar($this->input('direccion')),
            'descripcion' => $this->limpiar($this->input('descripcion')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $servicio = $this->route('servicio');
        $servicioId = $servicio instanceof Servicio ? $servicio->getKey() : null;

        return [
            'contrato_id' => ['required', 'integer', 'exists:contratos,id'],
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('servicios', 'nombre')
                    ->where(fn ($q) => $q->where('contrato_id', $this->integer('contrato_id')))
                    ->ignore($servicioId),
            ],
            // `codigo` NUNCA se valida como entrada del usuario: lo genera
            // el backend (autogenerado y único de plataforma, SER-0001…).
            'direccion' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Resuelve y valida el acceso a la empresa del contrato indicado — el
     * equivalente de `ResuelveEmpresa::empresaResuelta()` para un modelo sin
     * `empresa_id` propio. Lanza `ValidationException` en `contrato_id` si el
     * contrato no existe o el usuario no tiene acceso a su empresa.
     */
    public function contratoResuelto(): Contrato
    {
        if ($this->contratoResuelto !== null) {
            return $this->contratoResuelto;
        }

        $contrato = Contrato::query()->find($this->integer('contrato_id'));

        if ($contrato === null || ! $this->user()?->puedeAccederEmpresa($contrato->empresa_id)) {
            throw ValidationException::withMessages([
                'contrato_id' => 'Selecciona un contrato válido al que tengas acceso.',
            ]);
        }

        return $this->contratoResuelto = $contrato;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('contrato_id') || $validator->errors()->has('sucursal_id')) {
                return;
            }

            $contrato = Contrato::query()->find($this->integer('contrato_id'));
            $sucursal = Sucursal::query()->find($this->integer('sucursal_id'));

            if ($contrato === null || $sucursal === null) {
                return; // ya lo marcó la regla `exists`
            }

            if (! ($this->user()?->puedeAccederEmpresa($contrato->empresa_id) ?? false)) {
                $validator->errors()->add('contrato_id', 'Selecciona un contrato válido al que tengas acceso.');

                return;
            }

            if ($contrato->empresa_id !== $sucursal->empresa_id) {
                $validator->errors()->add('sucursal_id', 'La sucursal debe pertenecer a la misma empresa que el contrato.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contrato_id.required' => 'Selecciona el contrato del servicio.',
            'contrato_id.exists' => 'El contrato seleccionado no es válido.',
            'sucursal_id.required' => 'Selecciona la sucursal responsable del servicio.',
            'sucursal_id.exists' => 'La sucursal seleccionada no es válida.',
            'nombre.required' => 'El nombre del servicio es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 255 caracteres.',
            'nombre.unique' => 'Ya existe un servicio con ese nombre en este contrato.',
            'direccion.max' => 'La dirección no puede superar los 255 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
        ];
    }
}
