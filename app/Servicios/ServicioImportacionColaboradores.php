<?php

namespace App\Servicios;

use App\Excepciones\SimulacionImportacionColaboradores;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Colaboradores\GuardarColaboradorRequest;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Soporte\GeneradorNumeroEmpleado;
use App\Soporte\NormalizadorNombre;
use App\Soporte\ServicioGeneradorCodigos;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Analiza e importa colaboradores desde un archivo Excel/CSV. La empresa
 * SIEMPRE es la elegida en el formulario: ninguna celda puede determinarla.
 * La sucursal indicada debe pertenecer a esa empresa. El `numero_empleado`
 * NUNCA viene del archivo — se genera con `App\Soporte\GeneradorNumeroEmpleado`
 * (misma fuente de verdad que el alta manual), dentro de la misma transacción
 * que crea al colaborador.
 *
 * `analizar()` y `importar()` comparten `procesar()`, que SIEMPRE ejecuta la
 * resolución/creación real dentro de una transacción: en análisis (o si la
 * confirmación detecta errores de último momento) se fuerza un rollback
 * lanzando `SimulacionImportacionColaboradores`, así el consecutivo que
 * reserva el generador — y cualquier área nueva que se haya creado (ver
 * `resolverOCrearArea()`) — nunca se consume/persiste salvo en una
 * confirmación exitosa. Todo o nada: si queda un solo error o duplicado, no
 * se crea ningún colaborador ni ningún área huérfana (mismo patrón que
 * `App\Servicios\ServicioImportacionMaestra`).
 */
class ServicioImportacionColaboradores
{
    use CreaConCodigoUnico;
    use ReconciliaSecuenciaCodigo;

    private const DISCO_TEMP = 'local';

    /**
     * Columnas de la plantilla, en el orden de la plantilla descargable.
     * `numero_empleado` NO forma parte del archivo: es autogenerado.
     *
     * @var list<string>
     */
    private const COLUMNAS = ['nombre_completo', 'curp', 'puesto', 'area', 'correo', 'sucursal_codigo'];

    /**
     * @var list<string>
     */
    private const COLUMNAS_OBLIGATORIAS = ['nombre_completo', 'curp', 'sucursal_codigo'];

    /**
     * @var list<string>
     */
    private const COLUMNAS_OPCIONALES = ['puesto', 'area', 'correo'];

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly GeneradorNumeroEmpleado $generadorNumeroEmpleado,
        private readonly ServicioGeneradorCodigos $codigos,
    ) {}

    /**
     * Guarda el archivo temporalmente y devuelve el análisis: reporte de
     * columnas, total de filas, listas para importar, errores y duplicados.
     * No persiste nada (rollback intencional, ver docblock de la clase).
     *
     * @return array<string, mixed>
     */
    public function analizar(UploadedFile $archivo, Empresa $empresa): array
    {
        $token = Str::uuid()->toString().'.'.Str::lower((string) $archivo->getClientOriginalExtension());
        $ruta = 'importaciones/'.$empresa->getKey().'/'.$token;
        Storage::disk(self::DISCO_TEMP)->put($ruta, $archivo->getContent());

        $resultado = $this->procesar($ruta, $empresa, false);
        $resultado['token'] = $token;

        return $resultado;
    }

    /**
     * Reprocesa el archivo referenciado por el token de forma autoritativa
     * (nunca confía en el análisis que ya vio el frontend) y sólo persiste
     * si, tras reprocesar, no queda ningún error ni duplicado pendiente.
     * Devuelve el número de colaboradores creados.
     */
    public function importar(string $token, Empresa $empresa, ?int $usuarioId): int
    {
        $ruta = 'importaciones/'.$empresa->getKey().'/'.basename($token);

        abort_unless(Storage::disk(self::DISCO_TEMP)->exists($ruta), 422, 'El archivo de importación ya no está disponible. Vuelve a cargarlo.');

        $resultado = $this->procesar($ruta, $empresa, true);

        if ($this->hayProblemas($resultado)) {
            abort(422, 'La importación no puede completarse: hay errores o duplicados pendientes. Vuelve a analizar el archivo.');
        }

        Storage::disk(self::DISCO_TEMP)->delete($ruta);

        $this->auditoria->registrar('colaboradores', 'importar', [
            'descripcion' => $resultado['listos'].' colaboradores importados desde Excel para '.$empresa->nombre_comercial
                .'. Números de empleado generados automáticamente.',
            'empresa_id' => $empresa->getKey(),
        ]);

        return $resultado['listos'];
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function hayProblemas(array $resultado): bool
    {
        return $resultado['errores'] !== [] || $resultado['duplicados'] !== [] || $resultado['columnas']['faltantes'] !== [];
    }

    /**
     * Ejecuta `procesarArchivo()` dentro de una transacción. Si es un
     * análisis (`$persistir = false`) o si quedó algún error/duplicado, la
     * transacción SIEMPRE hace rollback — incluida cualquier reserva de
     * consecutivo que haya tomado `GeneradorNumeroEmpleado` para las filas
     * válidas, porque usa su propio `DB::transaction()` anidado (savepoint)
     * dentro de esta misma transacción.
     *
     * @return array<string, mixed>
     */
    private function procesar(string $ruta, Empresa $empresa, bool $persistir): array
    {
        $resultado = null;

        try {
            DB::transaction(function () use ($ruta, $empresa, $persistir, &$resultado): void {
                $resultado = $this->procesarArchivo($ruta, $empresa);

                if (! $persistir || $this->hayProblemas($resultado)) {
                    throw new SimulacionImportacionColaboradores;
                }
            });
        } catch (SimulacionImportacionColaboradores) {
            // Rollback intencional — ver docblock de la clase.
        }

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarArchivo(string $ruta, Empresa $empresa): array
    {
        $hojas = Excel::toArray(new class {}, $ruta, self::DISCO_TEMP);
        $filas = $hojas[0] ?? [];

        if ($filas === []) {
            return [
                'columnas' => $this->analizarColumnas([]),
                'areas' => ['existentes' => [], 'nuevas' => []],
                'total' => 0,
                'listos' => 0,
                'errores' => [$this->error(1, null, null, 'El archivo está vacío.')],
                'duplicados' => [],
                'filas_con_error' => 1,
                'filas_duplicadas' => 0,
            ];
        }

        $encabezados = array_values(array_map(
            fn ($v): string => Str::of((string) $v)->trim()->lower()->replace(' ', '_')->value(),
            array_shift($filas),
        ));

        $columnas = $this->analizarColumnas($encabezados);

        if ($columnas['faltantes'] !== []) {
            return [
                'columnas' => $columnas,
                'areas' => ['existentes' => [], 'nuevas' => []],
                'total' => 0,
                'listos' => 0,
                'errores' => [],
                'duplicados' => [],
                'filas_con_error' => 0,
                'filas_duplicadas' => 0,
            ];
        }

        $sucursales = $empresa->sucursales()->pluck('id', 'codigo')
            ->mapWithKeys(fn ($id, $codigo): array => [Str::lower((string) $codigo) => $id])->all();

        // La CURP es única a nivel PLATAFORMA (no por empresa, a diferencia
        // del número de empleado), así que la búsqueda de duplicados en BD
        // incluye soft-deleted, igual que el constraint único de la columna.
        $existentesCurp = Colaborador::query()->withTrashed()->pluck('curp')
            ->map(fn ($c): string => Str::lower((string) $c))->flip()->all();

        $errores = [];
        $duplicados = [];
        $vistosEnArchivoCurp = [];
        $areasCtx = ['resueltas' => [], 'existentes' => [], 'nuevas' => []];
        $total = 0;
        $listos = 0;

        foreach ($filas as $indice => $fila) {
            $numeroFila = $indice + 2; // +1 por base 0, +1 por encabezado

            if ($this->filaVacia($fila)) {
                continue;
            }

            $total++;

            $datos = [];
            foreach (self::COLUMNAS as $col) {
                $pos = array_search($col, $encabezados, true);
                $valor = $pos !== false && isset($fila[$pos]) ? trim((string) $fila[$pos]) : null;
                $datos[$col] = $valor === '' ? null : $valor;
            }

            if ($datos['curp'] !== null) {
                $datos['curp'] = Str::upper($datos['curp']);
            }

            $erroresFila = $this->validarFila($datos, $numeroFila, $sucursales);

            if ($erroresFila !== []) {
                array_push($errores, ...$erroresFila);

                continue;
            }

            $claveCurp = Str::lower((string) $datos['curp']);

            if (isset($vistosEnArchivoCurp[$claveCurp])) {
                $duplicados[] = $this->error($numeroFila, 'curp', $datos['curp'], 'La CURP se repite en la fila '.$vistosEnArchivoCurp[$claveCurp].' del archivo.');

                continue;
            }

            if (isset($existentesCurp[$claveCurp])) {
                $duplicados[] = $this->error($numeroFila, 'curp', $datos['curp'], 'La CURP ya existe en el sistema.');

                continue;
            }

            $vistosEnArchivoCurp[$claveCurp] = $numeroFila;

            $sucursalId = $sucursales[Str::lower((string) $datos['sucursal_codigo'])];
            [$areaId, $areaNombre] = $this->resolverOCrearArea($empresa, $datos['area'], $areasCtx);

            // El número de empleado NUNCA se toma del archivo: se reserva
            // atómicamente aquí, con la misma fuente de verdad del alta
            // manual (`ColaboradorController::store`).
            $numeroEmpleado = $this->generadorNumeroEmpleado->generar($empresa, $datos['nombre_completo']);

            Colaborador::query()->create([
                'empresa_id' => $empresa->getKey(),
                'sucursal_id' => $sucursalId,
                'numero_empleado' => $numeroEmpleado,
                'nombre_completo' => $datos['nombre_completo'],
                'curp' => $datos['curp'],
                'puesto' => $datos['puesto'],
                'area' => $areaNombre,
                'area_id' => $areaId,
                'correo' => $datos['correo'],
                'activo' => true,
            ]);

            $listos++;
        }

        return [
            'columnas' => $columnas,
            'areas' => [
                'existentes' => array_values($areasCtx['existentes']),
                'nuevas' => array_values($areasCtx['nuevas']),
            ],
            'total' => $total,
            'listos' => $listos,
            'errores' => $errores,
            'duplicados' => $duplicados,
            'filas_con_error' => count(array_unique(array_column($errores, 'fila'))),
            'filas_duplicadas' => count(array_unique(array_column($duplicados, 'fila'))),
        ];
    }

    /**
     * Valida los datos de una fila (formato + existencia de la sucursal en
     * la empresa). No detiene el análisis en el primer error: junta todos
     * los problemas de la fila antes de devolverlos.
     *
     * @param  array<string, string|null>  $datos
     * @param  array<string, int>  $sucursales  código de sucursal (lower) => id
     * @return list<array{fila: int, campo: string|null, valor: mixed, error: string}>
     */
    private function validarFila(array $datos, int $numeroFila, array $sucursales): array
    {
        // `bail` en cada campo: una fila puede tener varios campos con
        // problemas (se reportan todos), pero un mismo campo nunca acumula
        // dos mensajes por reglas encadenadas (p. ej. CURP corta e inválida
        // a la vez) — un solo mensaje por campo, el primero que falle.
        $validador = Validator::make($datos, [
            'nombre_completo' => ['bail', 'required', 'string', 'max:255'],
            // Misma estructura que el alta manual: nunca una segunda regex
            // que pueda divergir de `GuardarColaboradorRequest::REGEX_CURP`.
            'curp' => ['bail', 'required', 'string', 'size:18', 'regex:'.GuardarColaboradorRequest::REGEX_CURP],
            'puesto' => ['bail', 'nullable', 'string', 'max:255'],
            'area' => ['bail', 'nullable', 'string', 'max:255'],
            'correo' => ['bail', 'nullable', 'email', 'max:255'],
            'sucursal_codigo' => ['bail', 'required', 'string'],
        ], [
            'nombre_completo.required' => 'El nombre del colaborador es obligatorio.',
            'curp.required' => 'La CURP es obligatoria.',
            'curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'correo.email' => 'El correo electrónico no es válido.',
            'sucursal_codigo.required' => 'La sucursal es obligatoria.',
        ]);

        $errores = [];

        foreach ($validador->errors()->messages() as $campo => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $errores[] = $this->error($numeroFila, $campo, $datos[$campo] ?? null, $mensaje);
            }
        }

        if ($datos['sucursal_codigo'] !== null && ! $validador->errors()->has('sucursal_codigo')
            && ! isset($sucursales[Str::lower($datos['sucursal_codigo'])])) {
            $errores[] = $this->error($numeroFila, 'sucursal_codigo', $datos['sucursal_codigo'],
                'La sucursal '.$datos['sucursal_codigo'].' no existe en la empresa seleccionada.');
        }

        return $errores;
    }

    /**
     * Reporte de columnas del encabezado: esperadas/obligatorias/opcionales
     * (estructura fija de la plantilla), encontradas (normalizadas), y el
     * cruce correctas/faltantes/adicionales. Una columna faltante —
     * obligatoria u opcional— bloquea la importación completa: se exige la
     * plantilla completa para que todo archivo tenga la misma estructura.
     * Una columna adicional (p. ej. `numero_empleado` de una plantilla
     * vieja) es sólo informativa: nunca se lee para poblar datos.
     *
     * @param  list<string>  $encabezados  ya normalizados (trim+lower+snake_case)
     * @return array<string, mixed>
     */
    private function analizarColumnas(array $encabezados): array
    {
        $encontradas = array_values(array_filter($encabezados, fn (string $c): bool => $c !== ''));

        return [
            'esperadas' => self::COLUMNAS,
            'obligatorias' => self::COLUMNAS_OBLIGATORIAS,
            'opcionales' => self::COLUMNAS_OPCIONALES,
            'encontradas' => $encontradas,
            'correctas' => array_values(array_intersect(self::COLUMNAS, $encontradas)),
            'faltantes' => array_values(array_diff(self::COLUMNAS, $encontradas)),
            'adicionales' => array_values(array_diff($encontradas, self::COLUMNAS)),
        ];
    }

    /**
     * Área opcional: resuelve por nombre normalizado (case/espacio-insensible,
     * vía `NormalizadorNombre` — mismo criterio que `Area::nombre_normalizado`)
     * DENTRO de la empresa; si no existe todavía, la CREA (mismo patrón que
     * `ServicioImportacionMaestra::resolverOCrearArea`, con el mismo generador
     * de código `ARE-XXXX` que usa `AreaController::store`). La creación
     * ocurre dentro de la transacción de `procesar()`: en un análisis esa
     * transacción siempre hace rollback, así que "Analizar archivo" nunca
     * deja un área huérfana — sólo `importar()` la persiste de verdad.
     *
     * `$areasCtx['resueltas']` cachea por nombre normalizado dentro del MISMO
     * archivo, así "Compras" repetido en 40 filas resuelve una sola vez y
     * comparte `area_id`; `existentes`/`nuevas` alimentan el reporte que ve
     * el usuario en la prevalidación.
     *
     * @param  array{resueltas: array<string, Area>, existentes: array<string, string>, nuevas: array<string, string>}  $areasCtx
     * @return array{0: int|null, 1: string|null}
     */
    private function resolverOCrearArea(Empresa $empresa, ?string $nombreArea, array &$areasCtx): array
    {
        if ($nombreArea === null) {
            return [null, null];
        }

        $normalizado = NormalizadorNombre::catalogo($nombreArea);
        $clave = $empresa->getKey().'|'.$normalizado;

        if (isset($areasCtx['resueltas'][$clave])) {
            $area = $areasCtx['resueltas'][$clave];

            return [$area->id, $area->nombre];
        }

        $area = Area::query()->where('empresa_id', $empresa->getKey())
            ->where('nombre_normalizado', $normalizado)
            ->first();

        if ($area !== null) {
            $areasCtx['existentes'][$clave] = $area->nombre;
        } else {
            $area = $this->crearAreaSegura($empresa, $nombreArea, $normalizado);
            $areasCtx['nuevas'][$clave] = $area->nombre;
        }

        $areasCtx['resueltas'][$clave] = $area;

        return [$area->id, $area->nombre];
    }

    /**
     * Crea el área con el mismo generador de código (`ARE-XXXX`) que
     * `AreaController::store()`. Ante una carrera real (dos importaciones
     * concurrentes de la MISMA empresa creando la misma área nueva a la vez),
     * el índice único `areas_empresa_id_nombre_normalizado_unique` la
     * rechaza: se reutiliza la fila que ganó la carrera en vez de duplicar o
     * fallar con un 500. `CreaConCodigoUnico` cubre aparte una eventual
     * colisión del CÓDIGO (evento distinto e independiente).
     */
    private function crearAreaSegura(Empresa $empresa, string $nombreOriginal, string $normalizado): Area
    {
        return $this->crearConCodigoUnico(function () use ($empresa, $nombreOriginal, $normalizado): Area {
            try {
                return Area::query()->create([
                    'empresa_id' => $empresa->getKey(),
                    'nombre' => $this->limpiarNombreArea($nombreOriginal),
                    'codigo' => $this->generarCodigoArea($empresa),
                    'activa' => true,
                ]);
            } catch (QueryException $e) {
                if (! $this->esViolacionNombreAreaDuplicado($e)) {
                    throw $e;
                }

                return Area::query()->where('empresa_id', $empresa->getKey())
                    ->where('nombre_normalizado', $normalizado)
                    ->firstOrFail();
            }
        });
    }

    /**
     * Nombre canónico del área nueva: recorta y colapsa espacios múltiples
     * (mismo colapso que `NormalizadorNombre::catalogo()`), pero conserva las
     * mayúsculas/minúsculas tal como vinieron en la primera aparición válida
     * — nunca se fuerza mayúsculas sólo porque una fila del Excel venga así.
     */
    private function limpiarNombreArea(string $nombreOriginal): string
    {
        return preg_replace('/\s+/u', ' ', trim($nombreOriginal)) ?? trim($nombreOriginal);
    }

    private function generarCodigoArea(Empresa $empresa): string
    {
        return $this->codigos->siguienteConPrefijo($empresa, 'area', 'ARE', semilla: fn (): int => $this->maximoSufijo(
            Area::query()->where('empresa_id', $empresa->getKey())->where('codigo', 'like', 'ARE-%')->pluck('codigo'),
            'ARE-',
        ));
    }

    /**
     * Distingue una violación del índice único de NOMBRE
     * (`areas_empresa_id_nombre_normalizado_unique`) de cualquier otro error
     * de integridad de `areas` (p. ej. el código, que ya cubre
     * `CreaConCodigoUnico` con su propio criterio) — portátil entre
     * MySQL/MariaDB (nombra el índice) y SQLite de testing (nombra
     * tabla.columna), mismo criterio que `EmpresaController::store()` para
     * distinguir el RFC de cualquier otro unique.
     */
    private function esViolacionNombreAreaDuplicado(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && (str_contains($e->getMessage(), 'areas_empresa_id_nombre_normalizado_unique')
                || str_contains($e->getMessage(), 'areas.nombre_normalizado'));
    }

    /**
     * @param  list<mixed>  $fila
     */
    private function filaVacia(array $fila): bool
    {
        foreach ($fila as $valor) {
            if (trim((string) $valor) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{fila: int, campo: string|null, valor: mixed, error: string}
     */
    private function error(int $fila, ?string $campo, mixed $valor, string $mensaje): array
    {
        return ['fila' => $fila, 'campo' => $campo, 'valor' => $valor, 'error' => $mensaje];
    }
}
