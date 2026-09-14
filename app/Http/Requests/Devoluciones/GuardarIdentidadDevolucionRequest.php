<?php

namespace App\Http\Requests\Devoluciones;

use App\Http\Requests\Concerns\ReglasArchivoIdentidad;
use App\Models\Devolucion;
use App\Models\EntregaUniforme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Captura de una identificación oficial FALTANTE durante el WIZARD de una
 * devolución nueva (todavía no existe el registro `Devolucion`, igual que en
 * Entregas). El colaborador se resuelve SIEMPRE desde la ENTREGA REAL que
 * origina la devolución (`EntregaUniforme->colaborador`) — nunca desde un
 * colaborador_id suelto — para que este endpoint no sirva como acceso lateral
 * a la identificación de cualquier colaborador de una empresa autorizada.
 * Autorización de MÍNIMO PRIVILEGIO — el mismo criterio que consultarla en
 * este mismo paso (`DevolucionController::autorizarConsultaIdentidad`): basta
 * poder registrar devoluciones y tener acceso a la empresa de esa entrega. NO
 * concede administración del expediente; el reemplazo de una INE ya existente
 * sigue viviendo en el módulo de expediente.
 */
class GuardarIdentidadDevolucionRequest extends FormRequest
{
    use ReglasArchivoIdentidad;

    public function authorize(): bool
    {
        $entrega = $this->route('entrega');
        $usuario = $this->user();

        return $entrega instanceof EntregaUniforme
            && $entrega->colaborador !== null
            && $usuario !== null
            && $usuario->can('create', Devolucion::class)
            && $usuario->puedeAccederEmpresa($entrega->empresa_id);
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
