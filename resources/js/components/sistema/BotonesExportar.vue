<script setup lang="ts">
import { Download, FileSpreadsheet } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * "Exportar Excel" / "Exportar PDF" para un listado, respetando los filtros
 * activos: navega (descarga normal del navegador, no una visita Inertia) a
 * `${endpoint}?<filtros>&formato=xlsx|pdf`. El backend resuelve esa URL con
 * la MISMA consulta filtrada que ve la pantalla — nunca una aparte.
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
        <Button variant="outline" size="sm" as-child>
            <a :href="url('xlsx')">
                <FileSpreadsheet class="size-4" /> Exportar Excel
            </a>
        </Button>
        <Button variant="outline" size="sm" as-child>
            <a :href="url('pdf')"> <Download class="size-4" /> Exportar PDF </a>
        </Button>
    </div>
</template>
