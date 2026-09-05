<script setup lang="ts">
import { FileSpreadsheet, FileText } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * "Exportar Excel" / "Exportar PDF" para un listado, respetando los filtros
 * activos: navega (descarga normal del navegador, no una visita Inertia) a
 * `${endpoint}?<filtros>&formato=xlsx|pdf`. El backend resuelve esa URL con
 * la MISMA consulta filtrada que ve la pantalla — nunca una aparte.
 *
 * Colores semánticos fijos (verde/rojo), independientes de la
 * personalización visual global: son una convención universal para
 * Excel/PDF, no branding de la aplicación.
 */
const props = defineProps<{
    endpoint: string;
    filtros?: Record<string, string | number | boolean | null | undefined>;
}>();

function url(formato: 'xlsx' | 'pdf'): string {
    const params = new URLSearchParams();
    for (const [clave, valor] of Object.entries(props.filtros ?? {})) {
        if (valor !== null && valor !== undefined && valor !== '') {
            params.set(clave, String(valor));
        }
    }
    params.set('formato', formato);

    return `${props.endpoint}?${params.toString()}`;
}
</script>

<template>
    <div class="flex items-center gap-2">
        <Button
            variant="outline"
            size="sm"
            as-child
            class="border-emerald-600/30 text-emerald-700 hover:border-emerald-600/50 hover:bg-emerald-50 hover:text-emerald-800 dark:text-emerald-400 dark:hover:bg-emerald-950/40 dark:hover:text-emerald-300"
        >
            <a :href="url('xlsx')" aria-label="Exportar este listado a Excel">
                <FileSpreadsheet class="size-4" /> Excel
            </a>
        </Button>
        <Button
            variant="outline"
            size="sm"
            as-child
            class="border-red-600/30 text-red-700 hover:border-red-600/50 hover:bg-red-50 hover:text-red-800 dark:text-red-400 dark:hover:bg-red-950/40 dark:hover:text-red-300"
        >
            <a :href="url('pdf')" aria-label="Exportar este listado a PDF">
                <FileText class="size-4" /> PDF
            </a>
        </Button>
    </div>
</template>
