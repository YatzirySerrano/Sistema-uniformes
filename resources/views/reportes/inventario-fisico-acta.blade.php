{{--
    Acta PDF de una ronda de inventario físico (Browsershot). Todos los datos
    vienen de `ServicioResumenInventarioFisico::datosActa()`: snapshot y
    registros de la RONDA, nunca el estado actual del inventario. Los dos
    bloques (unidades identificadas / artículos por cantidad) se pintan de
    forma independiente: uno vacío nunca oculta al otro.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contexto->titulo }}</title>
    @include('reportes._estilos', ['colorPrincipal' => \App\Soporte\PaletaGraficas::principal()])
    <style>
        .ficha { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 16px; border: 1px solid var(--borde); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
        .ficha .etiqueta { font-size: 8px; color: var(--texto-tenue); margin: 0; }
        .ficha .valor { font-size: 10px; font-weight: 600; margin: 0; }
        .ficha .ancho { grid-column: 1 / -1; }
        .bloque { margin-bottom: 16px; }
        .resumen-bloque { font-size: 9px; color: var(--texto-suave); margin: -4px 0 8px; }
        .num { text-align: right !important; font-variant-numeric: tabular-nums; }
        .cierre { border: 1px solid var(--borde); border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; page-break-inside: avoid; }
        .cierre p { margin: 0 0 3px; }
        .hash { font-family: monospace; font-size: 7.5px; color: var(--texto-tenue); word-break: break-all; }
    </style>
</head>
<body>
    @include('reportes._encabezado')

    <div class="ficha">
        <div><p class="etiqueta">Folio</p><p class="valor">{{ $ronda['folio'] }}</p></div>
        <div><p class="etiqueta">Ronda</p><p class="valor">{{ $ronda['nombre'] }}</p></div>
        <div><p class="etiqueta">Estado</p><p class="valor">{{ $ronda['estado'] }}</p></div>
        <div><p class="etiqueta">Empresa</p><p class="valor">{{ $ronda['empresa'] ?? '—' }}</p></div>
        <div><p class="etiqueta">Alcance</p><p class="valor">{{ $ronda['almacen'] !== null ? 'Almacén '.$ronda['almacen'] : 'Toda la empresa' }}</p></div>
        <div><p class="etiqueta">Iniciada por</p><p class="valor">{{ $ronda['responsable'] ?? '—' }}</p></div>
        <div><p class="etiqueta">Inicio</p><p class="valor">{{ $ronda['iniciado_en'] ?? '—' }}</p></div>
        <div><p class="etiqueta">Cierre</p><p class="valor">{{ $ronda['finalizado_en'] ?? 'En proceso' }}</p></div>
        @if ($ronda['observaciones'])
            <div class="ancho"><p class="etiqueta">Observaciones</p><p class="valor">{{ $ronda['observaciones'] }}</p></div>
        @endif
    </div>

    <div class="bloque">
        <h2 class="seccion">Unidades identificadas (QR)</h2>
        <p class="resumen-bloque">
            Esperadas: <b>{{ $contadores['esperados'] }}</b> ·
            Encontradas: <b>{{ $contadores['encontrados_esperados'] }}</b> ·
            Faltantes / pendientes: <b>{{ $contadores['pendientes'] }}</b> ·
            No esperadas: <b>{{ $contadores['no_esperados'] }}</b>
        </p>
        @if (count($unidades) === 0)
            <p class="vacio">Esta ronda no incluye unidades identificadas.</p>
        @else
            <table class="datos">
                <thead>
                    <tr>
                        <th>Resultado</th>
                        <th>Código</th>
                        <th>Activo</th>
                        <th>Escaneada en</th>
                        <th>Escaneada por</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unidades as $u)
                        <tr>
                            <td>{{ $u['clasificacion'] }}</td>
                            <td>{{ $u['codigo'] ?? '—' }}</td>
                            <td>{{ $u['activo'] ?? '—' }}</td>
                            <td>{{ $u['escaneado_en'] ?? '—' }}</td>
                            <td>{{ $u['escaneado_por'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="bloque">
        <h2 class="seccion">Artículos por cantidad</h2>
        <p class="resumen-bloque">
            Renglones: <b>{{ $contadores['cantidad_renglones'] }}</b> ·
            Coinciden: <b>{{ $contadores['cantidad_coinciden'] }}</b> ·
            Con diferencia: <b>{{ $contadores['cantidad_con_diferencia'] }}</b> ·
            No fue posible verificar: <b>{{ $contadores['cantidad_no_verificables'] }}</b> ·
            Sin verificar: <b>{{ $contadores['cantidad_pendientes'] }}</b> ·
            Esperado total: <b>{{ $contadores['cantidad_esperada_total'] }}</b> ·
            Contado total: <b>{{ $contadores['cantidad_contada_total'] }}</b>
            @if ($contadores['cantidad_custodia_renglones'] > 0)
                · Bajo custodia: <b>{{ $contadores['cantidad_custodia_renglones'] }}</b> renglón(es)
            @endif
        </p>
        @if (count($existencias) === 0)
            <p class="vacio">Esta ronda no incluye artículos por cantidad.</p>
        @else
            <table class="datos">
                <thead>
                    <tr>
                        <th>Almacén / custodio</th>
                        <th>Finalidad</th>
                        <th>Activo</th>
                        <th>Talla / variante</th>
                        <th class="num">Esperado</th>
                        <th class="num">Contado</th>
                        <th class="num">Diferencia</th>
                        <th>Resultado</th>
                        <th>Verificado por</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($existencias as $e)
                        <tr>
                            <td>{{ $e['ubicacion'] }}</td>
                            <td>{{ $e['finalidad'] ?? 'No aplica' }}</td>
                            <td>{{ $e['activo'] ?? '—' }}</td>
                            <td>{{ $e['talla'] ?? 'Sin variante' }}</td>
                            <td class="num">{{ $e['cantidad_esperada'] }}</td>
                            <td class="num">{{ $e['no_verificable'] ? '—' : ($e['cantidad_contada'] ?? 'Sin verificar') }}</td>
                            <td class="num">
                                @if ($e['diferencia'] === null)
                                    —
                                @else
                                    {{ $e['diferencia'] > 0 ? '+' : '' }}{{ $e['diferencia'] }}
                                @endif
                            </td>
                            <td>
                                {{ $e['resultado'] }}
                                @if ($e['no_verificable'])
                                    <br>Motivo: {{ $e['motivo_no_verificable'] ?? '—' }}
                                @endif
                            </td>
                            <td>
                                {{ $e['verificada_por'] ?? '—' }}
                                @if ($e['verificada_en'])
                                    <br>{{ $e['verificada_en'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="cierre">
        <h2 class="seccion">Cierre y firma</h2>
        @if ($firma)
            <p>Firmado por <b>{{ $firma['nombre_firmante'] }}</b> el {{ $firma['aceptado_en'] }}.</p>
            <p>{{ $firma['texto_aceptado'] }}</p>
            <p class="hash">Huella de la firma (SHA-256): {{ $firma['hash_firma'] }}</p>
        @else
            <p class="vacio">La ronda aún no tiene firma de cierre.</p>
        @endif
    </div>

    <div class="cierre">
        <h2 class="seccion">Correcciones de inventario</h2>
        @switch($correcciones['estado'])
            @case('aplicadas')
                <p>Correcciones <b>aplicadas</b>
                    @if ($correcciones['aplicadas_en']) el {{ $correcciones['aplicadas_en'] }}@endif
                    @if ($correcciones['aplicadas_por']) por {{ $correcciones['aplicadas_por'] }}@endif
                    — {{ $correcciones['total_aplicadas'] }} movimiento(s) de ajuste.</p>
                @break
            @case('sin_diferencias')
                <p>Sin diferencias: no hay correcciones que aplicar.</p>
                @break
            @default
                <p>{{ $correcciones['total_diferencias'] }} renglón(es) de almacén con diferencia <b>pendientes de aplicar</b>.</p>
        @endswitch
        @if ($correcciones['diferencias_custodia'] > 0)
            <p>{{ $correcciones['diferencias_custodia'] }} diferencia(s) de custodia <b>a revisar</b>: no se aplican al inventario ni cambian la custodia.</p>
        @endif
    </div>
</body>
</html>
