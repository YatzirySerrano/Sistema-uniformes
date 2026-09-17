<?php

namespace App\Soporte;

/**
 * Una tarjeta KPI ya resuelta para pintarse en un export (Excel/PDF):
 * etiqueta + valor (la fuente de siempre, `ContextoExportacion::$kpis`) más
 * una descripción breve OPCIONAL (`ContextoExportacion::$kpiDescripciones`).
 * `ContextoExportacion::kpisResueltos()` es la única fábrica — nunca se
 * construye a mano fuera de ahí, así el mapeo etiqueta→descripción vive en
 * un solo lugar.
 */
final readonly class KpiExportacion
{
    public function __construct(
        public string $etiqueta,
        public string|int $valor,
        public ?string $descripcion = null,
    ) {}
}
