<?php

namespace App\Http\Requests\Devoluciones;

use App\Http\Requests\Concerns\ReglasArchivoIdentidad;
use App\Models\Devolucion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Captura de una identificación oficial FALTANTE durante la firma de una
 * devolución YA REGISTRADA (flujo legado `devoluciones/{devolucion}/firmar`,
 * ver `.ai/rules/devoluciones.md`). El colaborador se resuelve SIEMPRE desde
 * `Devolucion->colaborador` — nunca desde un id manipulable en la URL — y la
 * autorización es la MISMA que firmar/confirmar la devolución
 * (`DevolucionPolicy::confirmar`): el operador con permiso, o el propio
 * colaborador titular confirmando su devolución.
 */
class GuardarIdentidadAcuseDevolucionRequest extends FormRequest
{
    use ReglasArchivoIdentidad;

    public function authorize(): bool
    {
        $devolucion = $this->route('devolucion');

        return $devolucion instanceof Devolucion
            && $devolucion->colaborador !== null
            && $this->user() !== null
            && $this->user()->can('confirmar', $devolucion);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->reglasArchivoIdentidad();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesArchivoIdentidad();
    }
}
