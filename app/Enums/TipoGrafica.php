<?php

namespace App\Enums;

/**
 * Tipo de gráfica soportado por los reportes ejecutivos (Excel nativo vía
 * PhpSpreadsheet y PDF vía ApexCharts vivo en `App\Soporte\SerieGraficaReporte`).
 */
enum TipoGrafica: string
{
    case Barras = 'barras';
    case Dona = 'dona';
    case Linea = 'linea';
}
