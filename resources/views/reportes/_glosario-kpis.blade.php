{{--
    Glosario breve de los KPIs del bloque de arriba — sólo lo incluyen
    `entregas.blade.php`/`inventario.blade.php` (nunca `listado-generico`
    ni `roles-permisos`), así que no afecta la presentación de otros
    módulos. Lee `$contexto->kpiDescripciones` directamente (misma fuente
    que las tarjetas del Excel vía `ContextoExportacion::kpisResueltos()`,
    armada en `ReporteController::descripcionesKpis{Entregas,Inventario}()`
    — ni un texto duplicado a mano en el controller para el PDF). Mismo
    contenido que `DESCRIPCION_KPI` en `Reportes/Index.vue` (ahí sí
    duplicado a propósito: es un runtime distinto, Vue en vez de PHP).
--}}
@if ($contexto->kpiDescripciones !== [])
    <p class="glosario-kpis">
        @foreach ($contexto->kpiDescripciones as $etiqueta => $descripcion)
            <b>{{ $etiqueta }}:</b> {{ $descripcion }}@if (! $loop->last) &nbsp;·&nbsp; @endif
        @endforeach
    </p>
@endif
