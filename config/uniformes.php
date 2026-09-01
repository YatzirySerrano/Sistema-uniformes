<?php

return [

    /*
    | Zona horaria de presentación. Los timestamps se guardan en UTC y la
    | interfaz los muestra en esta zona.
    */
    'zona_horaria' => env('UNIFORMES_ZONA_HORARIA', 'America/Mexico_City'),

    /*
    | Permitir existencias negativas de inventario. Por defecto NO se permite:
    | una entrega que dejaría el saldo por debajo de cero se rechaza.
    */
    'permitir_stock_negativo' => (bool) env('UNIFORMES_PERMITIR_STOCK_NEGATIVO', false),

    /*
    | Tamaño de página por defecto para los listados paginados en backend.
    */
    'por_pagina' => (int) env('UNIFORMES_POR_PAGINA', 20),

    /*
    | Límite de filas admitidas en una importación de colaboradores.
    */
    'importacion_max_filas' => (int) env('UNIFORMES_IMPORTACION_MAX_FILAS', 5000),
];
