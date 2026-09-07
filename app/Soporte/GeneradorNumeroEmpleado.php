<?php

namespace App\Soporte;

use App\Models\Colaborador;
use App\Models\Empresa;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Número de empleado legible y autogenerado: iniciales del colaborador +
 * consecutivo numérico por empresa. Nunca lo captura el usuario — el
 * formulario sólo lo previsualiza; la asignación real y definitiva ocurre
 * al crear el colaborador. Reutiliza `ServicioGeneradorCodigos` (tabla
 * `secuencias_codigo`, ámbito "colaborador") — nunca `MAX()+1`. Los códigos
 * históricos con otro formato (p. ej. "J4659") no se tocan ni se renumeran;
 * sólo alimentan el punto de arranque cuando la secuencia de una empresa se
 * crea por primera vez.
 */
class GeneradorNumeroEmpleado
{
    private const AMBITO = 'colaborador';

    public function __construct(private readonly ServicioGeneradorCodigos $codigos) {}

    /**
     * Reserva atómicamente el siguiente consecutivo y arma el número de
     * empleado definitivo. Única vía autorizada para asignar
     * `numero_empleado` — el valor que mande el cliente nunca se usa.
     */
    public function generar(Empresa $empresa, string $nombreCompleto): string
    {
        $iniciales = $this->iniciales($nombreCompleto);

        for ($intento = 0; $intento < 5; $intento++) {
            $consecutivo = $this->codigos->siguienteNumero(
                $empresa,
                self::AMBITO,
                fn (): int => $this->semillaDesdeHistoricos($empresa->id),
            );
            $candidato = $this->formatear($iniciales, $consecutivo);

            if (! $this->existe($empresa->id, $candidato)) {
                return $candidato;
            }
        }

        throw new RuntimeException('No fue posible generar un número de empleado único para esta empresa.');
    }

    /**
     * Vista previa NO autoritativa (no reserva el consecutivo): estima el
     * siguiente número para que el usuario lo vea antes de guardar. El valor
     * definitivo se calcula de nuevo, atómicamente, en `generar()`.
     */
    public function previsualizar(Empresa $empresa, string $nombreCompleto): string
    {
        $iniciales = $this->iniciales($nombreCompleto);
        $consecutivo = $this->codigos->siguienteNumeroAproximado(
            $empresa,
            self::AMBITO,
            fn (): int => $this->semillaDesdeHistoricos($empresa->id),
        );

        return $this->formatear($iniciales, $consecutivo);
    }

    private function formatear(string $iniciales, int $consecutivo): string
    {
        // El padding a 4 dígitos es sólo el mínimo visual: al superar 9999
        // sigue creciendo (10000, 10001…), nunca trunca ni hace rollover.
        return $iniciales.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }

    private function existe(int $empresaId, string $numeroEmpleado): bool
    {
        return Colaborador::query()
            ->where('empresa_id', $empresaId)
            ->where('numero_empleado', $numeroEmpleado)
            ->exists();
    }

    /**
     * Primera letra del primer nombre + primera letra del último componente
     * del nombre completo (ASCII, mayúsculas, sin acentos). Con una sola
     * palabra usa únicamente esa inicial — nunca inventa una segunda letra.
     */
    private function iniciales(string $nombreCompleto): string
    {
        $palabras = array_values(array_filter(
            preg_split('/\s+/', trim($nombreCompleto)) ?: [],
            fn (string $p): bool => $p !== '',
        ));

        if ($palabras === []) {
            return 'X';
        }

        $primera = $this->primeraLetra($palabras[0]);

        if (count($palabras) === 1) {
            return $primera;
        }

        return $primera.$this->primeraLetra($palabras[count($palabras) - 1]);
    }

    private function primeraLetra(string $palabra): string
    {
        $ascii = Str::upper(Str::ascii($palabra));

        return preg_match('/[A-Z]/', $ascii, $coincidencia) === 1 ? $coincidencia[0] : 'X';
    }

    /**
     * Punto de arranque seguro cuando la secuencia "colaborador" de esta
     * empresa no existe todavía: toma el mayor sufijo numérico de los
     * `numero_empleado` ya registrados (cualquier formato histórico, p. ej.
     * "J4659" → 4659) para no reemitir un consecutivo ya usado. Sólo corre
     * una vez, al crear la fila de `secuencias_codigo`; después el contador
     * se usa tal cual (nunca `MAX()` en cada alta).
     */
    private function semillaDesdeHistoricos(int $empresaId): int
    {
        return Colaborador::query()
            ->where('empresa_id', $empresaId)
            ->pluck('numero_empleado')
            ->reduce(function (int $maximo, string $numero): int {
                if (preg_match('/(\d+)$/', $numero, $coincidencia) === 1) {
                    return max($maximo, (int) $coincidencia[1]);
                }

                return $maximo;
            }, 0);
    }
}
