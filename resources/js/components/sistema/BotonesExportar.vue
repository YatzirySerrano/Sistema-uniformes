<script setup lang="ts">
import { ChevronDown, Download, FileSpreadsheet, FileText } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * "Exportar" (Excel / PDF) para un listado, respetando los filtros activos:
 * navega (descarga normal del navegador, no una visita Inertia) a
 * `${endpoint}?<filtros>&formato=xlsx|pdf`. El backend resuelve esa URL con
 * la MISMA consulta filtrada que ve la pantalla — nunca una aparte.
 *
 * Un solo trigger con menú (en vez de dos botones sueltos) para no repetir
 * el mismo par de acciones en cada `EncabezadoPagina` de los 19 módulos que
 * exportan; los colores semánticos por formato (verde Excel / rojo PDF) se
 * conservan dentro del menú — son una convención universal para Excel/PDF,
 * no branding de la aplicación.
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
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                aria-label="Exportar este listado"
            >
                <Download class="size-4" />
                Exportar
                <ChevronDown class="size-3.5 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuItem
                as-child
                class="cursor-pointer text-emerald-700 focus:text-emerald-800 dark:text-emerald-400 dark:focus:text-emerald-300"
            >
                <a
                    :href="url('xlsx')"
                    aria-label="Exportar este listado a Excel"
                    class="flex w-full items-center"
                >
                    <FileSpreadsheet class="mr-2 size-4" /> Excel
                </a>
            </DropdownMenuItem>
            <DropdownMenuItem
                as-child
                class="cursor-pointer text-red-700 focus:text-red-800 dark:text-red-400 dark:focus:text-red-300"
            >
                <a
                    :href="url('pdf')"
                    aria-label="Exportar este listado a PDF"
                    class="flex w-full items-center"
                >
                    <FileText class="mr-2 size-4" /> PDF
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
