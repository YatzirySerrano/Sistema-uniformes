{{--
    Glosario breve de los KPIs del bloque de arriba — sólo lo incluyen
    `entregas.blade.php`/`inventario.blade.php` (nunca `listado-generico`
    ni `roles-permisos`), así que no afecta la presentación de otros
    módulos. Mismo texto que `DESCRIPCION_KPI` en `Reportes/Index.vue`
    (duplicado a propósito: son dos runtimes distintos, PHP y Vue).
--}}
@if (($glosario ?? []) !== [])
    <p class="glosario-kpis">
        @foreach ($glosario as $etiqueta => $descripcion)
            <b>{{ $etiqueta }}:</b> {{ $descripcion }}@if (! $loop->last) &nbsp;·&nbsp; @endif
        @endforeach
    </p>
@endif
