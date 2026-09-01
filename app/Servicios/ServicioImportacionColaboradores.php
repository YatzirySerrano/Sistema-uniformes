<?php

namespace App\Servicios;

use App\Models\Colaborador;
use App\Models\Empresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Analiza e importa colaboradores desde un archivo Excel/CSV. La empresa SIEMPRE
 * es la empresa activa: ninguna celda puede determinarla. La sucursal indicada
 * debe pertenecer a esa empresa.
 */
class ServicioImportacionColaboradores
{
    private const COLUMNAS = ['numero_empleado', 'nombre_completo', 'puesto', 'area', 'correo', 'sucursal_codigo'];

    private const DISCO_TEMP = 'local';

    public function __construct(private readonly ServicioAuditoria $auditoria) {}

    /**
     * Guarda el archivo temporalmente y devuelve el análisis fila por fila:
     * claves token, total, validos, duplicados, errores e importados.
     *
     * @return array<string, mixed>
     */
    public function analizar(UploadedFile $archivo, Empresa $empresa): array
    {
        $token = Str::uuid()->toString().'.'.$archivo->getClientOriginalExtension();
        $ruta = 'importaciones/'.$empresa->getKey().'/'.$token;
        Storage::disk(self::DISCO_TEMP)->put($ruta, $archivo->getContent());

        $resultado = $this->procesar($ruta, $empresa, false, null);
        $resultado['token'] = $token;

        return $resultado;
    }

    /**
     * Reprocesa el archivo referenciado por el token e importa las filas
     * válidas en una transacción. Devuelve el número de colaboradores creados.
     */
    public function importar(string $token, Empresa $empresa, ?int $usuarioId): int
    {
        $ruta = 'importaciones/'.$empresa->getKey().'/'.basename($token);

        abort_unless(Storage::disk(self::DISCO_TEMP)->exists($ruta), 422, 'El archivo de importación ya no está disponible. Vuelve a cargarlo.');

        $resultado = $this->procesar($ruta, $empresa, true, $usuarioId);

        Storage::disk(self::DISCO_TEMP)->delete($ruta);

        $this->auditoria->registrar('colaboradores', 'importar', [
            'descripcion' => $resultado['importados'].' colaboradores importados desde Excel.',
        ]);

        return $resultado['importados'];
    }

    /**
     * @return array<string, mixed>
     */
    private function procesar(string $ruta, Empresa $empresa, bool $persistir, ?int $usuarioId): array
    {
        $hojas = Excel::toArray(new class {}, $ruta, self::DISCO_TEMP);
        $filas = $hojas[0] ?? [];

        if ($filas === []) {
            return ['total' => 0, 'validos' => [], 'duplicados' => [], 'errores' => [['fila' => 1, 'errores' => ['El archivo está vacío.']]], 'importados' => 0];
        }

        $encabezados = array_map(
            fn ($v): string => Str::of((string) $v)->trim()->lower()->replace(' ', '_')->value(),
            array_shift($filas),
        );

        $faltantes = array_diff(['numero_empleado', 'nombre_completo', 'sucursal_codigo'], $encabezados);
        if ($faltantes !== []) {
            return [
                'total' => 0, 'validos' => [], 'duplicados' => [], 'importados' => 0,
                'errores' => [['fila' => 1, 'errores' => ['Faltan columnas obligatorias: '.implode(', ', $faltantes).'. Descarga la plantilla.']]],
            ];
        }

        $sucursales = $empresa->sucursales()->pluck('id', 'codigo')
            ->mapWithKeys(fn ($id, $codigo): array => [Str::lower((string) $codigo) => $id])->all();

        $existentes = $empresa->colaboradores()->withTrashed()->pluck('numero_empleado')
            ->map(fn ($n): string => Str::lower((string) $n))->flip()->all();

        $validos = [];
        $duplicados = [];
        $errores = [];
        $vistosEnArchivo = [];
        $importados = 0;
        $aInsertar = [];

        foreach ($filas as $indice => $fila) {
            $numeroFila = $indice + 2; // +1 por base 0, +1 por encabezado

            if ($this->filaVacia($fila)) {
                continue;
            }

            $datos = [];
            foreach (self::COLUMNAS as $col) {
                $pos = array_search($col, $encabezados, true);
                $datos[$col] = $pos !== false && isset($fila[$pos]) ? trim((string) $fila[$pos]) : null;
            }

            $erroresFila = [];

            $validador = Validator::make($datos, [
                'numero_empleado' => ['required', 'string', 'max:60'],
                'nombre_completo' => ['required', 'string', 'max:255'],
                'puesto' => ['nullable', 'string', 'max:255'],
                'area' => ['nullable', 'string', 'max:255'],
                'correo' => ['nullable', 'email', 'max:255'],
                'sucursal_codigo' => ['required', 'string'],
            ], [
                'numero_empleado.required' => 'El número de empleado es obligatorio.',
                'nombre_completo.required' => 'El nombre del colaborador es obligatorio.',
                'correo.email' => 'El correo electrónico no es válido.',
                'sucursal_codigo.required' => 'La sucursal es obligatoria.',
            ]);

            foreach ($validador->errors()->all() as $mensaje) {
                $erroresFila[] = $mensaje;
            }

            $sucursalId = null;
            if ($datos['sucursal_codigo'] !== null && $datos['sucursal_codigo'] !== '') {
                $sucursalId = $sucursales[Str::lower($datos['sucursal_codigo'])] ?? null;
                if ($sucursalId === null) {
                    $erroresFila[] = 'La sucursal '.$datos['sucursal_codigo'].' no existe en esta empresa.';
                }
            }

            $claveNum = Str::lower((string) $datos['numero_empleado']);

            if ($datos['numero_empleado'] !== null && isset($vistosEnArchivo[$claveNum])) {
                $duplicados[] = ['fila' => $numeroFila, 'datos' => $datos, 'motivo' => 'El número de empleado se repite en la fila '.$vistosEnArchivo[$claveNum].' del archivo.'];

                continue;
            }

            if ($datos['numero_empleado'] !== null && isset($existentes[$claveNum])) {
                $duplicados[] = ['fila' => $numeroFila, 'datos' => $datos, 'motivo' => 'El número de empleado ya existe en el sistema.'];

                continue;
            }

            if ($erroresFila !== []) {
                $errores[] = ['fila' => $numeroFila, 'errores' => $erroresFila];

                continue;
            }

            $vistosEnArchivo[$claveNum] = $numeroFila;
            $validos[] = ['fila' => $numeroFila, 'datos' => $datos];

            $aInsertar[] = [
                'empresa_id' => $empresa->getKey(),
                'sucursal_id' => $sucursalId,
                'numero_empleado' => $datos['numero_empleado'],
                'nombre_completo' => $datos['nombre_completo'],
                'puesto' => $datos['puesto'] ?: null,
                'area' => $datos['area'] ?: null,
                'correo' => $datos['correo'] ?: null,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($persistir && $aInsertar !== []) {
            $importados = DB::transaction(function () use ($aInsertar): int {
                foreach (array_chunk($aInsertar, 500) as $lote) {
                    Colaborador::query()->insert($lote);
                }

                return count($aInsertar);
            });
        }

        return [
            'total' => count($validos) + count($duplicados) + count($errores),
            'validos' => $validos,
            'duplicados' => $duplicados,
            'errores' => $errores,
            'importados' => $importados,
        ];
    }

    /**
     * @param  array<int, mixed>  $fila
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
}
