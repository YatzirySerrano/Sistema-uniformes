<?php

namespace App\Servicios;

use App\Excepciones\SimulacionImportacionColaboradores;
use App\Http\Requests\Colaboradores\GuardarColaboradorRequest;
use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Soporte\GeneradorNumeroEmpleado;
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
 * Área, sucursal y servicio se resuelven SÓLO por código exacto (sin
 * distinguir mayúsculas) y SIEMPRE dentro de la empresa destino: nunca por
 * nombre, nunca globalmente, nunca se crean. El servicio se liga con la misma
 * relación que el resto del sistema (`colaboradores.servicio_actual_id`, ver
 * `CambiarServicioColaboradorRequest`): activo, contrato activo y de la misma
 * empresa. La sucursal del colaborador NO tiene que ser la sucursal
 * responsable del servicio — el flujo manual tampoco lo exige.
 *
 * `analizar()` y `importar()` comparten `procesar()`, que SIEMPRE ejecuta la
 * resolución/creación real dentro de una transacción: en análisis (o si la
 * confirmación detecta errores de último momento) se fuerza un rollback
 * lanzando `SimulacionImportacionColaboradores`, así el consecutivo que
 * reserva el generador nunca se consume salvo en una confirmación exitosa.
 * La confirmación reprocesa el archivo completo (nunca confía en ids del
 * análisis previo), así que un área/sucursal/servicio que cambió entre
 * ambos pasos se vuelve a detectar. Todo o nada: si queda un solo error o
 * duplicado, no se crea ningún colaborador (mismo patrón que
 * `App\Servicios\ServicioImportacionMaestra`).
 */
class ServicioImportacionColaboradores
{
    private const DISCO_TEMP = 'local';

    /**
     * Columnas de la plantilla, en el orden de la plantilla descargable.
     * `numero_empleado` NO forma parte del archivo: es autogenerado.
     *
     * @var list<string>
     */
    public const COLUMNAS = ['nombre_completo', 'curp', 'puesto', 'area_codigo', 'correo', 'sucursal_codigo', 'servicio_codigo'];

    /**
     * @var list<string>
     */
    private const COLUMNAS_OBLIGATORIAS = ['nombre_completo', 'curp', 'area_codigo', 'sucursal_codigo', 'servicio_codigo'];

    /**
     * @var list<string>
     */
    private const COLUMNAS_OPCIONALES = ['puesto', 'correo'];

    /**
     * Columnas que no existían en la plantilla anterior (que traía `area`
     * como texto libre): si falta alguna, el archivo viene de una plantilla
     * desactualizada.
     *
     * @var list<string>
     */
    private const COLUMNAS_NUEVAS = ['area_codigo', 'servicio_codigo'];

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly GeneradorNumeroEmpleado $generadorNumeroEmpleado,
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

        try {
            $resultado = $this->procesar($ruta, $empresa, true);
        } catch (QueryException $e) {
            // Carrera real: otra alta registró la misma CURP entre el
            // reprocesamiento y el INSERT. El índice único de BD es la
            // autoridad final; se responde en español, nunca un 500.
            if ($e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'curp')) {
                throw $e;
            }

            abort(422, 'Otra captura registró una de estas CURP mientras se importaba. Vuelve a analizar el archivo.');
        }

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
                'areas' => ['existentes' => []],
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
                'areas' => ['existentes' => []],
                'total' => 0,
                'listos' => 0,
                'errores' => [],
                'duplicados' => [],
                'filas_con_error' => 0,
                'filas_duplicadas' => 0,
            ];
        }

        $catalogos = $this->catalogosDeEmpresa($empresa);

        // La CURP es única a nivel PLATAFORMA (no por empresa, a diferencia
        // del número de empleado), así que la búsqueda de duplicados en BD
        // incluye soft-deleted, igual que el constraint único de la columna.
        $existentesCurp = Colaborador::query()->withTrashed()->pluck('curp')
            ->map(fn ($c): string => Str::lower((string) $c))->flip()->all();

        $errores = [];
        $duplicados = [];
        $vistosEnArchivoCurp = [];
        $areasUsadas = [];
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

            $erroresFila = $this->validarFila($datos, $numeroFila, $catalogos);
            array_push($errores, ...$erroresFila);

            // La duplicidad de CURP es independiente de los demás campos: se
            // revisa aunque la fila ya tenga otro error (servicio, área,
            // correo…), para reportar todo en una sola pasada. Sólo se omite
            // si la propia CURP no pasó su validación de formato.
            $duplicadoFila = null;

            if (! in_array('curp', array_column($erroresFila, 'campo'), true)) {
                $claveCurp = Str::lower((string) $datos['curp']);

                if (isset($vistosEnArchivoCurp[$claveCurp])) {
                    $duplicadoFila = $this->error($numeroFila, 'curp', $datos['curp'], 'La CURP se repite en la fila '.$vistosEnArchivoCurp[$claveCurp].' del archivo.');
                } elseif (isset($existentesCurp[$claveCurp])) {
                    $duplicadoFila = $this->error($numeroFila, 'curp', $datos['curp'], 'La CURP ya existe en el sistema.');
                } else {
                    $vistosEnArchivoCurp[$claveCurp] = $numeroFila;
                }
            }

            if ($duplicadoFila !== null) {
                $duplicados[] = $duplicadoFila;
            }

            if ($erroresFila !== [] || $duplicadoFila !== null) {
                continue;
            }

            $area = $catalogos['areas'][Str::lower((string) $datos['area_codigo'])];
            $sucursal = $catalogos['sucursales'][Str::lower((string) $datos['sucursal_codigo'])];
            $servicio = $catalogos['servicios'][Str::lower((string) $datos['servicio_codigo'])];
            $areasUsadas[$area->id] = $area->nombre;

            // El número de empleado NUNCA se toma del archivo: se reserva
            // atómicamente aquí, con la misma fuente de verdad del alta
            // manual (`ColaboradorController::store`).
            $numeroEmpleado = $this->generadorNumeroEmpleado->generar($empresa, $datos['nombre_completo']);

            Colaborador::query()->create([
                'empresa_id' => $empresa->getKey(),
                'sucursal_id' => $sucursal->id,
                'numero_empleado' => $numeroEmpleado,
                'nombre_completo' => $datos['nombre_completo'],
                'curp' => $datos['curp'],
                'puesto' => $datos['puesto'],
                // `area` es el espejo de texto de `area_id` (mismo criterio
                // que `ColaboradorController`): el nombre del catálogo.
                'area' => $area->nombre,
                'area_id' => $area->id,
                // Misma relación que "Cambiar servicio" / "Asignar
                // colaboradores" y que `ServicioImportacionMaestra`.
                'servicio_actual_id' => $servicio->id,
                'correo' => $datos['correo'],
                'activo' => true,
            ]);

            $listos++;
        }

        return [
            'columnas' => $columnas,
            'areas' => [
                'existentes' => array_values($areasUsadas),
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
     * Áreas, sucursales y servicios de la empresa destino, indexados por
     * código en minúsculas. Se leen dentro de la transacción de `procesar()`
     * con candado compartido: en la confirmación, ninguno puede desactivarse
     * ni cambiar de empresa entre la validación y el alta de los
     * colaboradores. Los servicios se acotan a la empresa por su contrato
     * (`servicios` no tiene `empresa_id` propio).
     *
     * @return array{areas: array<string, Area>, sucursales: array<string, Sucursal>, servicios: array<string, Servicio>}
     */
    private function catalogosDeEmpresa(Empresa $empresa): array
    {
        $porCodigo = fn ($modelos): array => $modelos
            ->keyBy(fn ($modelo): string => Str::lower((string) $modelo->codigo))->all();

        return [
            'areas' => $porCodigo(Area::query()->where('empresa_id', $empresa->getKey())->sharedLock()->get()),
            'sucursales' => $porCodigo(Sucursal::query()->where('empresa_id', $empresa->getKey())->sharedLock()->get()),
            'servicios' => $porCodigo(Servicio::query()->with('contrato')
                ->whereHas('contrato', fn ($q) => $q->where('empresa_id', $empresa->getKey()))
                ->sharedLock()->get()),
        ];
    }

    /**
     * Valida los datos de una fila (formato + existencia de área, sucursal y
     * servicio en la empresa destino). No detiene el análisis en el primer
     * error: junta todos los problemas de la fila antes de devolverlos.
     *
     * @param  array<string, string|null>  $datos
     * @param  array{areas: array<string, Area>, sucursales: array<string, Sucursal>, servicios: array<string, Servicio>}  $catalogos
     * @return list<array{fila: int, campo: string|null, valor: mixed, error: string}>
     */
    private function validarFila(array $datos, int $numeroFila, array $catalogos): array
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
            'area_codigo' => ['bail', 'required', 'string'],
            'correo' => ['bail', 'nullable', 'email', 'max:255'],
            'sucursal_codigo' => ['bail', 'required', 'string'],
            'servicio_codigo' => ['bail', 'required', 'string'],
        ], [
            'nombre_completo.required' => 'El nombre del colaborador es obligatorio.',
            'curp.required' => 'La CURP es obligatoria.',
            'curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'area_codigo.required' => 'El código de área es obligatorio.',
            'correo.email' => 'El correo electrónico no es válido.',
            'sucursal_codigo.required' => 'La sucursal es obligatoria.',
            'servicio_codigo.required' => 'El código de servicio es obligatorio.',
        ]);

        $errores = [];

        foreach ($validador->errors()->messages() as $campo => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $errores[] = $this->error($numeroFila, $campo, $datos[$campo] ?? null, $mensaje);
            }
        }

        $resolver = function (string $campo, array $catalogo, callable $mensaje) use ($datos, $validador, $numeroFila, &$errores): void {
            $codigo = $datos[$campo];

            if ($codigo === null || $validador->errors()->has($campo)) {
                return;
            }

            $problema = $mensaje($catalogo[Str::lower($codigo)] ?? null, $codigo);

            if ($problema !== null) {
                $errores[] = $this->error($numeroFila, $campo, $codigo, $problema);
            }
        };

        $resolver('area_codigo', $catalogos['areas'], fn (?Area $area, string $codigo): ?string => match (true) {
            $area === null => 'Área con código «'.$codigo.'» no encontrada en la empresa seleccionada.',
            ! $area->activa => 'El área «'.$codigo.'» está inactiva.',
            default => null,
        });

        $resolver('sucursal_codigo', $catalogos['sucursales'], fn (?Sucursal $sucursal, string $codigo): ?string => match (true) {
            $sucursal === null => 'Sucursal con código «'.$codigo.'» no encontrada en la empresa seleccionada.',
            ! $sucursal->activa => 'La sucursal «'.$codigo.'» está inactiva.',
            default => null,
        });

        $resolver('servicio_codigo', $catalogos['servicios'], fn (?Servicio $servicio, string $codigo): ?string => match (true) {
            // El código de servicio es único a nivel plataforma: si existe
            // pero no está en la empresa destino, es de otra empresa (nunca
            // se dice de cuál).
            $servicio === null && Servicio::query()->where('codigo', $codigo)->exists() => 'El servicio «'.$codigo.'» pertenece a otra empresa.',
            $servicio === null => 'Servicio con código «'.$codigo.'» no encontrado en la empresa seleccionada.',
            ! $servicio->activo => 'El servicio «'.$codigo.'» está inactivo.',
            ! $servicio->contrato->activo => 'El contrato del servicio «'.$codigo.'» está inactivo.',
            default => null,
        });

        return $errores;
    }

    /**
     * Reporte de columnas del encabezado: esperadas/obligatorias/opcionales
     * (estructura fija de la plantilla), encontradas (normalizadas), y el
     * cruce correctas/faltantes/adicionales. Una columna faltante —
     * obligatoria u opcional— bloquea la importación completa: se exige la
     * plantilla completa para que todo archivo tenga la misma estructura.
     * Una columna adicional (p. ej. `numero_empleado` o `area` de una
     * plantilla vieja) es sólo informativa: nunca se lee para poblar datos.
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
            // Archivo de la plantilla anterior (traía `area` como texto, sin
            // `area_codigo`/`servicio_codigo`): nunca se intenta resolver el
            // área por nombre para "salvarlo"; se pide la plantilla actual.
            'desactualizada' => $encontradas !== [] && array_diff(self::COLUMNAS_NUEVAS, $encontradas) !== [],
        ];
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
