<?php

namespace App\Servicios;

use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\EstadoUnidadActivo;
use App\Enums\TipoControlActivo;
use App\Excepciones\ExcepcionDeNegocio;
use App\Excepciones\SimulacionImportacionMaestra;
use App\Http\Controllers\Concerns\CreaConCodigoUnico;
use App\Http\Controllers\Concerns\ReconciliaSecuenciaCodigo;
use App\Http\Requests\Concerns\NormalizaEntrada;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\CategoriaActivo;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Soporte\GeneradorCodigoEmpresa;
use App\Soporte\GeneradorNumeroEmpleado;
use App\Soporte\NormalizadorNombre;
use App\Soporte\ServicioGeneradorCodigos;
use App\Soporte\ServicioGeneradorCodigosGlobal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use Throwable;

/**
 * Importación única de la base de datos maestra (carga inicial de plataforma)
 * desde `BD_MAESTRA_REAL_2026.xlsx`: 10 hojas fijas, resueltas por llaves
 * naturales (nunca IDs del Excel), reutilizando los mismos generadores de
 * código y reglas de validación que el resto del sistema.
 *
 * Flujo en dos fases sobre la MISMA lógica de resolución/creación:
 * `prevalidar()` ejecuta todo dentro de una transacción que SIEMPRE hace
 * rollback (dry run real, no una simulación aparte que pueda desincronizarse
 * de la lógica real); `confirmar()` ejecuta lo mismo y sólo confirma si no
 * quedó ningún error pendiente.
 */
final class ServicioImportacionMaestra
{
    use CreaConCodigoUnico;
    use NormalizaEntrada;
    use ReconciliaSecuenciaCodigo;

    private const DISCO_TEMP = 'local';

    private const CARPETA_TEMP = 'importaciones-maestras';

    /**
     * Orden de procesamiento = orden de dependencias = orden de las hojas en
     * el archivo real. Si el orden de hojas del maestro cambiara alguna vez,
     * ajustar aquí (y sólo aquí).
     *
     * @var list<string>
     */
    private const HOJAS = [
        'EMPRESAS', 'SUCURSALES', 'CONTRATOS', 'SERVICIOS', 'COLABORADORES',
        'TALLAS', 'ACTIVOS', 'ACTIVO_TALLA', 'ALMACENES', 'UNIDADES_ACTIVO',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ENCABEZADOS = [
        'EMPRESAS' => ['nombre_comercial', 'razon_social', 'rfc', 'telefono', 'correo', 'direccion', 'activa'],
        'SUCURSALES' => ['empresa', 'nombre', 'direccion', 'telefono', 'activa'],
        'CONTRATOS' => ['empresa', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin', 'activo'],
        'SERVICIOS' => ['empresa', 'contrato', 'sucursal', 'nombre', 'direccion', 'activo'],
        'COLABORADORES' => ['empresa', 'sucursal', 'nombre_completo', 'curp', 'puesto', 'area', 'servicio', 'correo', 'activo'],
        'TALLAS' => ['valor'],
        'ACTIVOS' => ['empresa', 'nombre', 'categoria', 'tipo_control', 'activo'],
        'ACTIVO_TALLA' => ['empresa', 'activo', 'talla'],
        'ALMACENES' => ['nombre', 'direccion', 'telefono', 'correo', 'empresas_abastecidas', 'activo'],
        'UNIDADES_ACTIVO' => [
            'empresa', 'activo', 'almacen', 'estado', 'condicion', 'colaborador', 'colaborador_curp',
            'marca', 'modelo', 'imei', 'numero_telefonico', 'operador', 'plan', 'observaciones',
        ],
    ];

    // Misma estructura que App\Http\Requests\Empresas\GuardarEmpresaRequest
    // (pública ahí para que el propio EmpresaController la reutilice); se
    // duplica aquí siguiendo la misma convención ya usada en
    // App\Servicios\ServicioImportacionColaboradores para la CURP.
    private const REGEX_RFC = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/';

    // Misma estructura que App\Http\Requests\Colaboradores\GuardarColaboradorRequest
    // (sin verificar contra RENAPO ni recalcular el dígito verificador).
    private const REGEX_CURP = '/^[A-Z][AEIOU][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM]'
        .'(AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QO|QR|SL|SP|SR|TC|TL|TS|VZ|YN|ZS|NE)'
        .'[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$/';

    public function __construct(
        private readonly ServicioAuditoria $auditoria,
        private readonly GeneradorCodigoEmpresa $generadorCodigoEmpresa,
        private readonly GeneradorNumeroEmpleado $generadorNumeroEmpleado,
        private readonly ServicioGeneradorCodigos $codigos,
        private readonly ServicioGeneradorCodigosGlobal $codigosGlobales,
        private readonly RegistrarUnidadesActivo $registrarUnidades,
    ) {}

    /**
     * La importación maestra es exclusiva para una base vacía: si ya existe
     * al menos una Empresa, toda tabla objetivo (directa o indirectamente
     * dependiente de Empresa) puede tener información real que no se debe
     * arriesgar con un alta masiva pensada para arranque de plataforma.
     */
    public function baseNoVacia(): bool
    {
        return Empresa::query()->exists();
    }

    /**
     * Guarda el archivo temporalmente y ejecuta TODA la resolución/creación
     * real dentro de una transacción que siempre hace rollback (dry run
     * fidedigno: usa la misma lógica que `confirmar()`, nunca una copia
     * simplificada que pueda desincronizarse).
     *
     * @return array<string, mixed>
     */
    public function prevalidar(UploadedFile $archivo): array
    {
        $token = Str::uuid()->toString().'.xlsx';
        $ruta = self::CARPETA_TEMP.'/'.$token;
        Storage::disk(self::DISCO_TEMP)->put($ruta, $archivo->getContent());

        $resultado = $this->procesar($ruta, false, null);
        $resultado['token'] = $token;

        return $resultado;
    }

    /**
     * Reprocesa el archivo referenciado por el token y persiste si, y sólo
     * si, no queda ningún error pendiente (defensivo ante cambios en la BD
     * entre `prevalidar()` y `confirmar()`). Borra el temporal y registra UNA
     * entrada de auditoría resumen sólo cuando la importación se confirma.
     *
     * @return array<string, mixed>
     */
    public function confirmar(string $token, ?int $usuarioId): array
    {
        $ruta = self::CARPETA_TEMP.'/'.basename($token);

        abort_unless(
            Storage::disk(self::DISCO_TEMP)->exists($ruta),
            422,
            'El archivo de importación ya no está disponible. Vuelve a cargarlo y prevalidarlo.',
        );

        $inicio = microtime(true);
        $resultado = $this->procesar($ruta, true, $usuarioId);
        $duracionMs = (int) round((microtime(true) - $inicio) * 1000);

        if ($resultado['errores'] !== []) {
            abort(422, 'La importación no puede completarse: hay errores pendientes. Vuelve a prevalidar el archivo.');
        }

        Storage::disk(self::DISCO_TEMP)->delete($ruta);

        $this->auditoria->registrar('datos', 'importacion_maestra', [
            'descripcion' => 'Importación maestra completada ('.$duracionMs.' ms): '.$this->resumenTexto($resultado['conteos']),
            'valores_nuevos' => $resultado['conteos'],
        ]);

        return ['resumen' => $resultado['resumen'], 'conteos' => $resultado['conteos'], 'duracion_ms' => $duracionMs];
    }

    /**
     * @return array<string, mixed>
     */
    private function procesar(string $ruta, bool $persistir, ?int $usuarioId): array
    {
        $resultado = null;

        try {
            DB::transaction(function () use ($ruta, $persistir, $usuarioId, &$resultado): void {
                $resultado = $this->procesarTodasLasHojas($ruta, $usuarioId);

                if (! $persistir || $resultado['errores'] !== []) {
                    // Dry run, o confirmación con errores de último momento:
                    // nunca se persiste una importación incompleta.
                    throw new SimulacionImportacionMaestra;
                }
            });
        } catch (SimulacionImportacionMaestra) {
            // Rollback intencional — ver docblock de la clase.
        }

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarTodasLasHojas(string $ruta, ?int $usuarioId): array
    {
        $hojas = Excel::toArray(new class {}, $ruta, self::DISCO_TEMP);

        $errores = [];

        if (count($hojas) < count(self::HOJAS)) {
            $errores[] = $this->error(self::HOJAS[0], 1, null, null,
                'El archivo no tiene las '.count(self::HOJAS).' hojas esperadas ('.implode(', ', self::HOJAS).').');

            return ['resumen' => [], 'errores' => $errores, 'conteos' => []];
        }

        $ctx = [
            'empresas' => [],
            'sucursales' => [],
            'contratos' => [],
            'servicios' => [],
            'servicios_ambiguos' => [],
            'servicios_por_contrato' => [],
            'areas' => [],
            'tallas' => [],
            'categorias' => [],
            'activos' => [],
            'almacenes' => [],
            'colaboradores_por_curp' => [],
            'colaboradores_por_nombre' => [],
        ];

        $resumen = [];
        $conteos = [];

        foreach (self::HOJAS as $indice => $hoja) {
            $filas = $this->leerFilas($hojas, $indice, $hoja, $errores);
            $erroresAntes = count($errores);

            $creadas = match ($hoja) {
                'EMPRESAS' => $this->procesarEmpresas($filas, $ctx, $errores),
                'SUCURSALES' => $this->procesarSucursales($filas, $ctx, $errores),
                'CONTRATOS' => $this->procesarContratos($filas, $ctx, $errores),
                'SERVICIOS' => $this->procesarServicios($filas, $ctx, $errores),
                'COLABORADORES' => $this->procesarColaboradores($filas, $ctx, $errores),
                'TALLAS' => $this->procesarTallas($filas, $ctx, $errores),
                'ACTIVOS' => $this->procesarActivos($filas, $ctx, $errores),
                'ACTIVO_TALLA' => $this->procesarActivoTalla($filas, $ctx, $errores),
                'ALMACENES' => $this->procesarAlmacenes($filas, $ctx, $errores),
                'UNIDADES_ACTIVO' => $this->procesarUnidadesActivo($filas, $ctx, $errores, $usuarioId),
            };

            $conteos[$hoja] = $creadas;
            $resumen[$hoja] = [
                'total' => count($filas),
                'validos' => $creadas,
                'errores' => count($errores) - $erroresAntes,
            ];
        }

        return ['resumen' => $resumen, 'errores' => $errores, 'conteos' => $conteos];
    }

    // =========================================================================
    // EMPRESAS
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarEmpresas(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;
        $rfcVistos = [];

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $nombre = $this->limpiar($datos['nombre_comercial'] ?? null);
            $rfc = $datos['rfc'] !== null && $datos['rfc'] !== '' ? Str::upper(trim((string) $datos['rfc'])) : null;

            if ($nombre === null) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'nombre_comercial', $datos['nombre_comercial'] ?? null, 'El nombre comercial es obligatorio.');

                continue;
            }

            if ($rfc === null) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'rfc', $datos['rfc'] ?? null, 'El RFC es obligatorio.');

                continue;
            }

            if (! preg_match(self::REGEX_RFC, $rfc)) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'rfc', $rfc, 'El RFC no tiene un formato válido (por ejemplo: ABC010203XYZ).');

                continue;
            }

            $claveRfc = Str::lower($rfc);

            if (isset($rfcVistos[$claveRfc])) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'rfc', $rfc, 'El RFC se repite en la fila '.$rfcVistos[$claveRfc].' del archivo.');

                continue;
            }

            if (Empresa::withTrashed()->where('rfc', $rfc)->exists()) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'rfc', $rfc, 'Ya existe una empresa registrada con este RFC.');

                continue;
            }

            $telefono = $this->soloDigitos($datos['telefono'] ?? null);

            if ($telefono !== null && strlen($telefono) !== 10) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'telefono', $datos['telefono'], 'El teléfono debe contener 10 dígitos.');

                continue;
            }

            $correo = $this->limpiar($datos['correo'] ?? null);

            if ($correo !== null && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = $this->error('EMPRESAS', $numeroFila, 'correo', $correo, 'El correo no tiene un formato válido.');

                continue;
            }

            $rfcVistos[$claveRfc] = $numeroFila;

            $empresa = $this->crearConCodigoUnico(fn () => Empresa::query()->create([
                'nombre_comercial' => $nombre,
                'razon_social' => $this->limpiar($datos['razon_social'] ?? null),
                'rfc' => $rfc,
                'telefono' => $telefono,
                'correo' => $correo,
                'direccion' => $this->limpiar($datos['direccion'] ?? null),
                'activa' => $this->interpretarBooleano($datos['activa'] ?? null, true),
                'codigo' => $this->generadorCodigoEmpresa->generar($nombre),
            ]));

            $ctx['empresas'][NormalizadorNombre::catalogo($nombre)] = $empresa;
            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // SUCURSALES
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarSucursales(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('SUCURSALES', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombre = $this->limpiar($datos['nombre'] ?? null);

            if ($nombre === null) {
                $errores[] = $this->error('SUCURSALES', $numeroFila, 'nombre', $datos['nombre'] ?? null, 'El nombre de la sucursal es obligatorio.');

                continue;
            }

            $telefono = $this->soloDigitos($datos['telefono'] ?? null);

            if ($telefono !== null && strlen($telefono) !== 10) {
                $errores[] = $this->error('SUCURSALES', $numeroFila, 'telefono', $datos['telefono'], 'El teléfono debe contener 10 dígitos.');

                continue;
            }

            $clave = $empresa->id.'|'.NormalizadorNombre::catalogo($nombre);

            if (isset($ctx['sucursales'][$clave])) {
                $errores[] = $this->error('SUCURSALES', $numeroFila, 'nombre', $nombre, 'Esta sucursal ya se importó antes para la misma empresa.');

                continue;
            }

            $sucursal = $this->crearConCodigoUnico(fn () => Sucursal::query()->create([
                'empresa_id' => $empresa->id,
                'nombre' => $nombre,
                'direccion' => $this->limpiar($datos['direccion'] ?? null),
                'telefono' => $telefono,
                'activa' => $this->interpretarBooleano($datos['activa'] ?? null, true),
                'codigo' => $this->codigos->siguienteConPrefijo($empresa, 'sucursal', 'SUC', semilla: fn (): int => $this->maximoSufijo(
                    Sucursal::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'SUC-%')->pluck('codigo'),
                    'SUC-',
                )),
            ]));

            $ctx['sucursales'][$clave] = $sucursal;
            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // CONTRATOS
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarContratos(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('CONTRATOS', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombre = $this->limpiar($datos['nombre'] ?? null);

            if ($nombre === null) {
                $errores[] = $this->error('CONTRATOS', $numeroFila, 'nombre', $datos['nombre'] ?? null, 'El nombre del contrato es obligatorio.');

                continue;
            }

            $fechaInicio = $this->parseFecha($datos['fecha_inicio'] ?? null);
            $fechaFin = $this->parseFecha($datos['fecha_fin'] ?? null);

            if ($fechaFin !== null && $fechaInicio !== null && $fechaFin < $fechaInicio) {
                $errores[] = $this->error('CONTRATOS', $numeroFila, 'fecha_fin', $datos['fecha_fin'] ?? null, 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                continue;
            }

            $clave = $empresa->id.'|'.NormalizadorNombre::catalogo($nombre);

            if (isset($ctx['contratos'][$clave])) {
                $errores[] = $this->error('CONTRATOS', $numeroFila, 'nombre', $nombre, 'Este contrato ya se importó antes para la misma empresa.');

                continue;
            }

            $contrato = $this->crearConCodigoUnico(fn () => Contrato::query()->create([
                'empresa_id' => $empresa->id,
                'nombre' => $nombre,
                'descripcion' => $this->limpiar($datos['descripcion'] ?? null),
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'activo' => $this->interpretarBooleano($datos['activo'] ?? null, true),
                'codigo' => $this->codigos->siguienteConPrefijo($empresa, 'contrato', 'CON', semilla: fn (): int => $this->maximoSufijo(
                    Contrato::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'CON-%')->pluck('codigo'),
                    'CON-',
                )),
            ]));

            $ctx['contratos'][$clave] = $contrato;
            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // SERVICIOS
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarServicios(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('SERVICIOS', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombre = $this->limpiar($datos['nombre'] ?? null);

            if ($nombre === null) {
                $errores[] = $this->error('SERVICIOS', $numeroFila, 'nombre', $datos['nombre'] ?? null, 'El nombre del servicio es obligatorio.');

                continue;
            }

            $nombreContrato = $this->limpiar($datos['contrato'] ?? null);
            $contrato = $nombreContrato !== null
                ? ($ctx['contratos'][$empresa->id.'|'.NormalizadorNombre::catalogo($nombreContrato)] ?? null)
                : null;

            if ($contrato === null) {
                $errores[] = $this->error('SERVICIOS', $numeroFila, 'contrato', $datos['contrato'] ?? null,
                    'El contrato «'.($datos['contrato'] ?? '').'» no existe en esta empresa o no se ha importado todavía.');

                continue;
            }

            $nombreSucursal = $this->limpiar($datos['sucursal'] ?? null);
            $sucursal = $nombreSucursal !== null
                ? ($ctx['sucursales'][$empresa->id.'|'.NormalizadorNombre::catalogo($nombreSucursal)] ?? null)
                : null;

            if ($sucursal === null) {
                $errores[] = $this->error('SERVICIOS', $numeroFila, 'sucursal', $datos['sucursal'] ?? null,
                    'La sucursal «'.($datos['sucursal'] ?? '').'» no existe en esta empresa o no se ha importado todavía.');

                continue;
            }

            $claveContrato = $contrato->id.'|'.NormalizadorNombre::catalogo($nombre);

            if (isset($ctx['servicios_por_contrato'][$claveContrato])) {
                $errores[] = $this->error('SERVICIOS', $numeroFila, 'nombre', $nombre, 'Este servicio ya se importó antes para el mismo contrato.');

                continue;
            }

            $servicio = $this->crearConCodigoUnico(fn () => Servicio::query()->create([
                'contrato_id' => $contrato->id,
                'sucursal_id' => $sucursal->id,
                'nombre' => $nombre,
                'direccion' => $this->limpiar($datos['direccion'] ?? null),
                'activo' => $this->interpretarBooleano($datos['activo'] ?? null, true),
                'codigo' => $this->codigosGlobales->siguiente('servicio', 'SER', semilla: fn (): int => $this->maximoSufijo(
                    Servicio::query()->where('codigo', 'like', 'SER-%')->pluck('codigo'),
                    'SER-',
                )),
            ]));

            $ctx['servicios_por_contrato'][$claveContrato] = $servicio;

            // Un mismo nombre de servicio puede repetirse en dos contratos
            // distintos de la misma empresa: la primera vez se registra como
            // resolución válida por nombre; la segunda lo marca AMBIGUO (no
            // hay columna en COLABORADORES para desambiguar por contrato).
            $claveGlobal = $empresa->id.'|'.NormalizadorNombre::catalogo($nombre);

            if (isset($ctx['servicios'][$claveGlobal])) {
                $ctx['servicios_ambiguos'][$claveGlobal] = true;
            } else {
                $ctx['servicios'][$claveGlobal] = $servicio;
            }

            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // COLABORADORES
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarColaboradores(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;
        $curpVistas = [];
        $curpsExistentes = Colaborador::query()->withTrashed()->pluck('curp')
            ->map(fn ($c): string => Str::upper((string) $c))->flip()->all();

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombreCompleto = $this->limpiar($datos['nombre_completo'] ?? null);

            if ($nombreCompleto === null) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'nombre_completo', $datos['nombre_completo'] ?? null, 'El nombre del colaborador es obligatorio.');

                continue;
            }

            $curp = $datos['curp'] !== null && $datos['curp'] !== '' ? Str::upper(trim((string) $datos['curp'])) : null;

            if ($curp === null) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'curp', $datos['curp'] ?? null, 'La CURP es obligatoria.');

                continue;
            }

            if (strlen($curp) !== 18 || ! preg_match(self::REGEX_CURP, $curp)) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'curp', $curp, 'La CURP no tiene un formato válido.');

                continue;
            }

            if (isset($curpVistas[$curp])) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'curp', $curp, 'La CURP se repite en la fila '.$curpVistas[$curp].' del archivo.');

                continue;
            }

            if (isset($curpsExistentes[$curp])) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'curp', $curp, 'Ya existe un colaborador registrado con esta CURP.');

                continue;
            }

            $nombreSucursal = $this->limpiar($datos['sucursal'] ?? null);
            $sucursal = $nombreSucursal !== null
                ? ($ctx['sucursales'][$empresa->id.'|'.NormalizadorNombre::catalogo($nombreSucursal)] ?? null)
                : null;

            if ($sucursal === null) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'sucursal', $datos['sucursal'] ?? null,
                    'La sucursal «'.($datos['sucursal'] ?? '').'» no existe en esta empresa o no se ha importado todavía.');

                continue;
            }

            $correo = $this->limpiar($datos['correo'] ?? null);

            if ($correo !== null && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = $this->error('COLABORADORES', $numeroFila, 'correo', $correo, 'El correo no tiene un formato válido.');

                continue;
            }

            [$areaId, $nombreAreaFinal] = $this->resolverOCrearArea($datos['area'] ?? null, $empresa, $ctx);

            $nombreServicio = $this->limpiar($datos['servicio'] ?? null);
            $servicioId = null;

            if ($nombreServicio !== null) {
                $claveServicio = $empresa->id.'|'.NormalizadorNombre::catalogo($nombreServicio);

                if (isset($ctx['servicios_ambiguos'][$claveServicio])) {
                    $errores[] = $this->error('COLABORADORES', $numeroFila, 'servicio', $nombreServicio,
                        'Hay más de un servicio con ese nombre en esta empresa (en distintos contratos); no se puede determinar cuál usar.');

                    continue;
                }

                $servicio = $ctx['servicios'][$claveServicio] ?? null;

                if ($servicio === null) {
                    $errores[] = $this->error('COLABORADORES', $numeroFila, 'servicio', $nombreServicio,
                        'El servicio «'.$nombreServicio.'» no existe en esta empresa o no se ha importado todavía.');

                    continue;
                }

                $servicioId = $servicio->id;
            }

            $numeroEmpleado = $this->generadorNumeroEmpleado->generar($empresa, $nombreCompleto);

            $colaborador = Colaborador::query()->create([
                'empresa_id' => $empresa->id,
                'sucursal_id' => $sucursal->id,
                'numero_empleado' => $numeroEmpleado,
                'nombre_completo' => $nombreCompleto,
                'curp' => $curp,
                'puesto' => $this->limpiar($datos['puesto'] ?? null),
                'area' => $nombreAreaFinal,
                'area_id' => $areaId,
                'servicio_actual_id' => $servicioId,
                'correo' => $correo,
                'activo' => $this->interpretarBooleano($datos['activo'] ?? null, true),
            ]);

            $curpVistas[$curp] = $numeroFila;
            $ctx['colaboradores_por_curp'][$curp] = $colaborador;
            $claveNombre = $empresa->id.'|'.NormalizadorNombre::catalogo($nombreCompleto);
            $ctx['colaboradores_por_nombre'][$claveNombre][] = $colaborador;
            $creadas++;
        }

        return $creadas;
    }

    /**
     * Área opcional: resuelve por nombre (case/espacio-insensible) dentro de
     * la empresa o la crea con el mismo generador de código que
     * `AreaController::store()`. Cachea en `$ctx` para no duplicar si dos
     * filas de este mismo archivo usan la misma área nueva.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{0: int|null, 1: string|null}
     */
    private function resolverOCrearArea(mixed $nombreCrudo, Empresa $empresa, array &$ctx): array
    {
        $nombre = $this->limpiar($nombreCrudo);

        if ($nombre === null) {
            return [null, null];
        }

        $clave = $empresa->id.'|'.NormalizadorNombre::catalogo($nombre);
        $area = $ctx['areas'][$clave] ?? null;

        if ($area === null) {
            $area = Area::query()->where('empresa_id', $empresa->id)
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [Str::lower($nombre)])
                ->first();
        }

        if ($area === null) {
            $area = $this->crearConCodigoUnico(fn () => Area::query()->create([
                'empresa_id' => $empresa->id,
                'nombre' => $nombre,
                'activa' => true,
                'codigo' => $this->codigos->siguienteConPrefijo($empresa, 'area', 'ARE', semilla: fn (): int => $this->maximoSufijo(
                    Area::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'ARE-%')->pluck('codigo'),
                    'ARE-',
                )),
            ]));
        }

        $ctx['areas'][$clave] = $area;

        return [$area->id, $area->nombre];
    }

    // =========================================================================
    // TALLAS
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarTallas(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;
        $maxOrden = (int) Talla::query()->max('orden');

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $valor = $this->limpiar($datos['valor'] ?? null);

            if ($valor === null) {
                $errores[] = $this->error('TALLAS', $numeroFila, 'valor', $datos['valor'] ?? null, 'El nombre de la variante / talla es obligatorio.');

                continue;
            }

            $clave = NormalizadorNombre::catalogo($valor);

            if (isset($ctx['tallas'][$clave])) {
                $errores[] = $this->error('TALLAS', $numeroFila, 'valor', $valor, 'Esta variante / talla ya se importó antes (o está repetida en el archivo).');

                continue;
            }

            if (Talla::existeNombre($valor)) {
                $errores[] = $this->error('TALLAS', $numeroFila, 'valor', $valor, 'Ya existe una variante / talla con ese nombre.');

                continue;
            }

            $maxOrden++;

            $talla = Talla::query()->create([
                'valor' => $valor,
                'orden' => $maxOrden,
                'activa' => true,
            ]);

            $ctx['tallas'][$clave] = $talla;
            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // ACTIVOS
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarActivos(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('ACTIVOS', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombre = $this->limpiar($datos['nombre'] ?? null);

            if ($nombre === null) {
                $errores[] = $this->error('ACTIVOS', $numeroFila, 'nombre', $datos['nombre'] ?? null, 'El nombre del activo es obligatorio.');

                continue;
            }

            $tipoControlTexto = $this->limpiar($datos['tipo_control'] ?? null);
            $tipoControl = $tipoControlTexto !== null ? TipoControlActivo::tryFrom(Str::lower($tipoControlTexto)) : TipoControlActivo::Cantidad;

            if ($tipoControlTexto !== null && $tipoControl === null) {
                $errores[] = $this->error('ACTIVOS', $numeroFila, 'tipo_control', $tipoControlTexto, 'El tipo de control debe ser "cantidad" o "individual".');

                continue;
            }

            $clave = $empresa->id.'|'.NormalizadorNombre::catalogo($nombre);

            if (isset($ctx['activos'][$clave])) {
                $errores[] = $this->error('ACTIVOS', $numeroFila, 'nombre', $nombre, 'Este activo ya se importó antes para la misma empresa.');

                continue;
            }

            $categoria = $this->resolverOCrearCategoria($datos['categoria'] ?? null, $ctx);

            $activo = $this->crearConCodigoUnico(fn () => Activo::query()->create([
                'empresa_id' => $empresa->id,
                'categoria_id' => $categoria?->id,
                'categoria' => $categoria?->nombre,
                'nombre' => $nombre,
                'tipo_control' => $tipoControl,
                'activo' => $this->interpretarBooleano($datos['activo'] ?? null, true),
                'codigo' => $this->codigos->siguienteConPrefijo($empresa, 'activo', 'ACT', semilla: fn (): int => $this->maximoSufijo(
                    Activo::query()->where('empresa_id', $empresa->id)->where('codigo', 'like', 'ACT-%')->pluck('codigo'),
                    'ACT-',
                )),
            ]));

            $ctx['activos'][$clave] = $activo;
            $creadas++;
        }

        return $creadas;
    }

    /**
     * Categoría opcional del catálogo GLOBAL: resuelve por nombre normalizado
     * o la crea (nunca lleva `codigo` al crearse, igual que
     * `CategoriaActivoController::crear()`).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function resolverOCrearCategoria(mixed $nombreCrudo, array &$ctx): ?CategoriaActivo
    {
        $nombre = $this->limpiar($nombreCrudo);

        if ($nombre === null) {
            return null;
        }

        $clave = NormalizadorNombre::catalogo($nombre);
        $categoria = $ctx['categorias'][$clave] ?? null;

        if ($categoria === null) {
            $categoria = CategoriaActivo::query()->where('nombre_normalizado', $clave)->first();
        }

        if ($categoria === null) {
            $categoria = CategoriaActivo::query()->create([
                'nombre' => $nombre,
                'activa' => true,
            ]);
        }

        return $ctx['categorias'][$clave] = $categoria;
    }

    // =========================================================================
    // ACTIVO_TALLA
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarActivoTalla(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('ACTIVO_TALLA', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombreActivo = $this->limpiar($datos['activo'] ?? null);
            $activo = $nombreActivo !== null
                ? ($ctx['activos'][$empresa->id.'|'.NormalizadorNombre::catalogo($nombreActivo)] ?? null)
                : null;

            if ($activo === null) {
                $errores[] = $this->error('ACTIVO_TALLA', $numeroFila, 'activo', $datos['activo'] ?? null,
                    'El activo «'.($datos['activo'] ?? '').'» no existe en esta empresa o no se ha importado todavía.');

                continue;
            }

            $nombreTalla = $this->limpiar($datos['talla'] ?? null);
            $talla = $nombreTalla !== null ? ($ctx['tallas'][NormalizadorNombre::catalogo($nombreTalla)] ?? null) : null;

            if ($talla === null) {
                $errores[] = $this->error('ACTIVO_TALLA', $numeroFila, 'talla', $datos['talla'] ?? null,
                    'La variante / talla «'.($datos['talla'] ?? '').'» no existe o no se ha importado todavía.');

                continue;
            }

            $activo->tallas()->syncWithoutDetaching([$talla->id]);
            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // ALMACENES
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarAlmacenes(array $filas, array &$ctx, array &$errores): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $nombre = $this->limpiar($datos['nombre'] ?? null);

            if ($nombre === null) {
                $errores[] = $this->error('ALMACENES', $numeroFila, 'nombre', $datos['nombre'] ?? null, 'El nombre del almacén es obligatorio.');

                continue;
            }

            $clave = NormalizadorNombre::catalogo($nombre);

            if (isset($ctx['almacenes'][$clave])) {
                $errores[] = $this->error('ALMACENES', $numeroFila, 'nombre', $nombre, 'Este almacén ya se importó antes (o está repetido en el archivo).');

                continue;
            }

            // Lista separada por ";" (no ",": la razón social/dirección de una
            // empresa puede legítimamente contener comas).
            $nombresEmpresas = array_values(array_filter(
                array_map('trim', explode(';', (string) ($datos['empresas_abastecidas'] ?? ''))),
                fn (string $n): bool => $n !== '',
            ));

            $empresaIds = [];
            $noResueltas = [];

            foreach ($nombresEmpresas as $nombreEmpresa) {
                $empresa = $this->resolverEmpresa($nombreEmpresa, $ctx);

                if ($empresa === null) {
                    $noResueltas[] = $nombreEmpresa;
                } else {
                    $empresaIds[] = $empresa->id;
                }
            }

            if ($noResueltas !== []) {
                $errores[] = $this->error('ALMACENES', $numeroFila, 'empresas_abastecidas', $datos['empresas_abastecidas'] ?? null,
                    'No se encontraron las siguientes empresas: '.implode(', ', $noResueltas).'.');

                continue;
            }

            if ($empresaIds === []) {
                $errores[] = $this->error('ALMACENES', $numeroFila, 'empresas_abastecidas', $datos['empresas_abastecidas'] ?? null,
                    'Indica al menos una empresa abastecida (varias, separadas por ";").');

                continue;
            }

            $telefono = $this->soloDigitos($datos['telefono'] ?? null);

            if ($telefono !== null && strlen($telefono) !== 10) {
                $errores[] = $this->error('ALMACENES', $numeroFila, 'telefono', $datos['telefono'], 'El teléfono debe contener 10 dígitos.');

                continue;
            }

            $correo = $this->limpiar($datos['correo'] ?? null);

            if ($correo !== null && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = $this->error('ALMACENES', $numeroFila, 'correo', $correo, 'El correo no tiene un formato válido.');

                continue;
            }

            $almacen = $this->crearConCodigoUnico(fn () => Almacen::query()->create([
                'nombre' => $nombre,
                'direccion' => $this->limpiar($datos['direccion'] ?? null),
                'telefono' => $telefono,
                'correo' => $correo,
                'activo' => $this->interpretarBooleano($datos['activo'] ?? null, true),
                'codigo' => $this->codigosGlobales->siguiente('almacen', 'ALM', semilla: fn (): int => $this->maximoSufijo(
                    Almacen::query()->where('codigo', 'like', 'ALM-%')->pluck('codigo'),
                    'ALM-',
                )),
            ]));

            $almacen->empresas()->attach($empresaIds);

            $ctx['almacenes'][$clave] = $almacen;
            $creadas++;
        }

        return $creadas;
    }

    // =========================================================================
    // UNIDADES_ACTIVO
    // =========================================================================

    /**
     * @param  array<int, array{fila: int, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function procesarUnidadesActivo(array $filas, array &$ctx, array &$errores, ?int $usuarioId): int
    {
        $creadas = 0;

        foreach ($filas as ['fila' => $numeroFila, 'datos' => $datos]) {
            $empresa = $this->resolverEmpresa($datos['empresa'] ?? null, $ctx);

            if ($empresa === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'empresa', $datos['empresa'] ?? null, $this->mensajeEmpresaNoResuelta($datos['empresa'] ?? null));

                continue;
            }

            $nombreActivo = $this->limpiar($datos['activo'] ?? null);
            $activo = $nombreActivo !== null
                ? ($ctx['activos'][$empresa->id.'|'.NormalizadorNombre::catalogo($nombreActivo)] ?? null)
                : null;

            if ($activo === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'activo', $datos['activo'] ?? null,
                    'El activo «'.($datos['activo'] ?? '').'» no existe en esta empresa o no se ha importado todavía.');

                continue;
            }

            if ($activo->tipo_control !== TipoControlActivo::SeguimientoIndividual) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'activo', $nombreActivo,
                    'Este activo no usa seguimiento individual; no puede tener unidades identificadas.');

                continue;
            }

            $nombreAlmacen = $this->limpiar($datos['almacen'] ?? null);
            $almacen = $nombreAlmacen !== null ? ($ctx['almacenes'][NormalizadorNombre::catalogo($nombreAlmacen)] ?? null) : null;

            if ($almacen === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'almacen', $datos['almacen'] ?? null,
                    'El almacén «'.($datos['almacen'] ?? '').'» no existe o no se ha importado todavía.');

                continue;
            }

            if (! $almacen->abasteceEmpresa($empresa->id)) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'almacen', $nombreAlmacen, 'El almacén «'.$nombreAlmacen.'» no abastece a esta empresa.');

                continue;
            }

            $condicionTexto = $this->limpiar($datos['condicion'] ?? null);
            $condicion = $condicionTexto !== null ? CondicionUnidadActivo::tryFrom(Str::lower($condicionTexto)) : CondicionUnidadActivo::Funcionando;

            if ($condicionTexto !== null && $condicion === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'condicion', $condicionTexto,
                    'La condición debe ser una de: '.implode(', ', array_column(CondicionUnidadActivo::cases(), 'value')).'.');

                continue;
            }

            $colaboradorCurp = $this->limpiar($datos['colaborador_curp'] ?? null);
            $colaboradorNombre = $this->limpiar($datos['colaborador'] ?? null);
            $seIntentoResolverColaborador = $colaboradorCurp !== null || $colaboradorNombre !== null;

            $colaborador = $this->resolverColaborador($colaboradorCurp, $colaboradorNombre, $empresa, $ctx, $numeroFila, $errores);

            if ($seIntentoResolverColaborador && $colaborador === null) {
                continue;
            }

            $estadoTexto = $this->limpiar($datos['estado'] ?? null);
            $estadoSolicitado = $estadoTexto !== null ? EstadoUnidadActivo::tryFrom(Str::lower($estadoTexto)) : null;

            if ($estadoTexto !== null && $estadoSolicitado === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'estado', $estadoTexto,
                    'El estado debe ser uno de: '.implode(', ', array_column(EstadoUnidadActivo::cases(), 'value')).'.');

                continue;
            }

            if ($estadoSolicitado === EstadoUnidadActivo::Asignada && $colaborador === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'estado', $estadoTexto, 'El estado "asignada" requiere indicar un colaborador.');

                continue;
            }

            if ($estadoSolicitado !== null && $estadoSolicitado !== EstadoUnidadActivo::Asignada && $colaborador !== null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'estado', $estadoTexto,
                    'No puede indicarse un colaborador si el estado es "'.$estadoSolicitado->value.'".');

                continue;
            }

            $estadoFinal = $estadoSolicitado ?? ($colaborador !== null ? EstadoUnidadActivo::Asignada : EstadoUnidadActivo::EnAlmacen);

            $especificacion = array_filter([
                'marca' => $this->limpiar($datos['marca'] ?? null),
                'modelo' => $this->limpiar($datos['modelo'] ?? null),
                'imei' => $this->limpiar($datos['imei'] ?? null),
                'numero_telefonico' => $this->limpiar($datos['numero_telefonico'] ?? null),
                'operador' => $this->limpiar($datos['operador'] ?? null),
                'plan' => $this->limpiar($datos['plan'] ?? null),
            ], fn ($valor): bool => $valor !== null);

            try {
                $unidades = $this->registrarUnidades->ejecutar(
                    empresa: $empresa,
                    activo: $activo,
                    almacen: $almacen,
                    cantidad: 1,
                    motivo: 'Carga inicial de base de datos maestra',
                    realizadoPor: $usuarioId,
                    cargaInicial: true,
                    especificaciones: $especificacion === [] ? [] : [$especificacion],
                );
            } catch (ExcepcionDeNegocio $e) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, null, null, $e->getMessage());

                continue;
            }

            // `RegistrarUnidadesActivo` siempre crea la unidad como "en
            // almacén, funcionando" (alta nueva real): aquí se ajusta al
            // estado HISTÓRICO que trae el maestro sin generar una
            // entrega/movimiento nuevo — es carga de datos, no una operación
            // de negocio nueva.
            $unidades->first()->update([
                'condicion' => $condicion,
                'estado' => $estadoFinal,
                'colaborador_id' => $colaborador?->id,
                'observaciones' => $this->limpiar($datos['observaciones'] ?? null),
            ]);

            $creadas++;
        }

        return $creadas;
    }

    /**
     * Resuelve el colaborador de una unidad: CURP autoritativa si viene;
     * si no, por nombre (0 coincidencias → error, más de 1 → error pidiendo
     * CURP). Ambos vacíos → `null` sin error (unidad queda sin asignar).
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>  $errores
     */
    private function resolverColaborador(
        ?string $curp,
        ?string $nombre,
        Empresa $empresa,
        array $ctx,
        int $numeroFila,
        array &$errores,
    ): ?Colaborador {
        if ($curp !== null) {
            $curpNormalizada = Str::upper($curp);
            $colaborador = $ctx['colaboradores_por_curp'][$curpNormalizada] ?? null;

            if ($colaborador === null) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'colaborador_curp', $curp, 'No se encontró ningún colaborador con esa CURP en esta importación.');

                return null;
            }

            if ($colaborador->empresa_id !== $empresa->id) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'colaborador_curp', $curp, 'La CURP indicada pertenece a un colaborador de otra empresa.');

                return null;
            }

            return $colaborador;
        }

        if ($nombre !== null) {
            $clave = $empresa->id.'|'.NormalizadorNombre::catalogo($nombre);
            $candidatos = $ctx['colaboradores_por_nombre'][$clave] ?? [];

            if (count($candidatos) === 0) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'colaborador', $nombre, 'No se encontró ningún colaborador con ese nombre en esta empresa.');

                return null;
            }

            if (count($candidatos) > 1) {
                $errores[] = $this->error('UNIDADES_ACTIVO', $numeroFila, 'colaborador', $nombre,
                    'Hay más de un colaborador con ese nombre en esta empresa; indica «colaborador_curp» para desambiguar.');

                return null;
            }

            return $candidatos[0];
        }

        return null;
    }

    // =========================================================================
    // Utilidades compartidas
    // =========================================================================

    /**
     * Lee una hoja por posición (el orden de hojas del archivo real es fijo,
     * igual que `self::HOJAS`), valida su encabezado contra
     * `self::ENCABEZADOS` y devuelve las filas no vacías indexadas por número
     * de fila real del Excel (base 1 + fila de encabezado).
     *
     * @param  array<int, array<int, mixed>>  $hojas
     * @param  array<int, array<string, mixed>>  $errores
     * @return array<int, array{fila: int, datos: array<string, mixed>}>
     */
    private function leerFilas(array $hojas, int $indice, string $hoja, array &$errores): array
    {
        $crudo = $hojas[$indice] ?? [];

        if ($crudo === []) {
            return [];
        }

        $encabezadosCrudos = array_shift($crudo);
        $encabezados = array_map(
            fn ($v): string => Str::of((string) $v)->trim()->lower()->replace(' ', '_')->value(),
            $encabezadosCrudos ?? [],
        );

        $esperado = self::ENCABEZADOS[$hoja];
        $faltantes = array_diff($esperado, $encabezados);

        if ($faltantes !== []) {
            $errores[] = $this->error($hoja, 1, null, null, 'Faltan columnas obligatorias: '.implode(', ', $faltantes).'.');

            return [];
        }

        $filas = [];

        foreach ($crudo as $indiceFila => $fila) {
            if ($this->filaVacia($fila)) {
                continue;
            }

            $datos = [];

            foreach ($esperado as $columna) {
                $pos = array_search($columna, $encabezados, true);
                $valor = $pos !== false ? ($fila[$pos] ?? null) : null;
                $datos[$columna] = is_string($valor) ? trim($valor) : $valor;
            }

            // +1 por índice base 0, +1 por la fila de encabezado ya retirada.
            $filas[] = ['fila' => $indiceFila + 2, 'datos' => $datos];
        }

        return $filas;
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
     * @param  array<string, mixed>  $ctx
     */
    private function resolverEmpresa(mixed $nombreCrudo, array $ctx): ?Empresa
    {
        $nombre = $this->limpiar($nombreCrudo);

        if ($nombre === null) {
            return null;
        }

        return $ctx['empresas'][NormalizadorNombre::catalogo($nombre)] ?? null;
    }

    private function mensajeEmpresaNoResuelta(mixed $nombreCrudo): string
    {
        $nombre = $this->limpiar($nombreCrudo);

        return $nombre === null
            ? 'La empresa es obligatoria.'
            : 'La empresa «'.$nombre.'» no existe o no se ha importado todavía.';
    }

    private function interpretarBooleano(mixed $valor, bool $porDefecto): bool
    {
        if ($valor === null || $valor === '') {
            return $porDefecto;
        }

        if (is_bool($valor)) {
            return $valor;
        }

        $texto = Str::lower(trim((string) $valor));

        return match ($texto) {
            '1', 'si', 'sí', 'true', 'verdadero', 'activo', 'activa' => true,
            '0', 'no', 'false', 'falso', 'inactivo', 'inactiva' => false,
            default => $porDefecto,
        };
    }

    private function parseFecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_numeric($valor)) {
            try {
                return FechaExcel::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $valor)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, int>  $conteos
     */
    private function resumenTexto(array $conteos): string
    {
        $partes = [];

        foreach ($conteos as $hoja => $creadas) {
            $partes[] = $creadas.' '.Str::lower(str_replace('_', ' ', $hoja));
        }

        return implode(', ', $partes).'.';
    }

    /**
     * @return array{hoja: string, fila: int, campo: string|null, valor: mixed, error: string}
     */
    private function error(string $hoja, int $fila, ?string $campo, mixed $valor, string $mensaje): array
    {
        return [
            'hoja' => $hoja,
            'fila' => $fila,
            'campo' => $campo,
            'valor' => is_scalar($valor) || $valor === null ? $valor : (string) json_encode($valor),
            'error' => $mensaje,
        ];
    }
}
