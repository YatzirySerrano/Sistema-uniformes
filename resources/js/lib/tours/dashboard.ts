import type { Tour } from './tipos';

export const tourDashboard: Tour = {
    id: 'dashboard',
    titulo: 'Cómo leer el Dashboard',
    pasos: [
        {
            selector: '[data-tour="titulo-dashboard"]',
            titulo: 'Tu resumen operativo',
            texto: 'Aquí ves el estado del sistema para las empresas que tienes autorizadas, en el rango de fechas que elijas abajo.',
        },
        {
            selector: '[data-tour="filtros-dashboard"]',
            titulo: 'Acota lo que ves',
            texto: 'Filtra por empresa, sucursal, almacén y rango de fechas. Todo lo de abajo (KPIs, gráficas, listas) se recalcula automáticamente.',
        },
        {
            selector: '[data-tour="kpis-dashboard"]',
            titulo: 'Números clave',
            texto: 'Un vistazo rápido: colaboradores activos, existencias disponibles, entregas del periodo y activos con stock bajo.',
        },
        {
            selector: '[data-tour="graficas-dashboard"]',
            titulo: 'Actividad y tendencias',
            texto: 'Entregas/devoluciones, movimientos de inventario, unidades por estado y existencias por almacén — pasa el cursor sobre cualquier punto para ver el detalle exacto.',
        },
        {
            selector: '[data-tour="entregas-recientes-dashboard"]',
            titulo: 'Entregas recientes',
            texto: 'Las últimas entregas registradas, con su estado (Pendiente de firma, Firmada, Corregida o Anulada). Haz clic en cualquiera para ver el detalle completo.',
        },
        {
            selector: '[data-tour="existencias-bajas-dashboard"]',
            titulo: 'Alertas de inventario',
            texto: 'Activos que ya llegaron o están cerca de su mínimo configurado, ordenados por qué tan crítico es el faltante.',
        },
    ],
};
