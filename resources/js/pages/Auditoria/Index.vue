<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Input } from '@/components/ui/input';
import type { Paginado } from '@/types/sistema';

type Registro = {
    id: number;
    fecha: string;
    usuario: string | null;
    modulo: string;
    accion: string;
    descripcion: string | null;
    entidad: string | null;
    motivo: string | null;
    ip: string | null;
};

const props = defineProps<{
    registros: Paginado<Registro>;
    filtros: Record<string, string | number | undefined>;
    empresasAutorizadas: import('@/types/sistema').EmpresaAutorizada[];
    modulos: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Auditoría', href: '/auditoria' }] },
});

const f = ref({
    empresa_id: props.filtros.empresa_id ?? '',
    modulo: props.filtros.modulo ?? '',
    buscar: props.filtros.buscar ?? '',
    desde: props.filtros.desde ?? '',
    hasta: props.filtros.hasta ?? '',
});

let t: ReturnType<typeof setTimeout>;
watch(
    f,
    () => {
        clearTimeout(t);
        t = setTimeout(() => {
            router.get(
                '/auditoria',
                { ...f.value },
                {
                    preserveState: true,
                    replace: true,
                    preserveScroll: true,
                },
            );
        }, 300);
    },
    { deep: true },
);

function fecha(iso: string) {
    return new Date(iso).toLocaleString('es-MX');
}
</script>

<template>
    <Head title="Auditoría" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Bitácora de auditoría"
            descripcion="Registro append-only de acciones relevantes del sistema."
        />

        <div class="flex flex-wrap gap-2">
            <select
                v-if="empresasAutorizadas.length > 1"
                v-model="f.empresa_id"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                aria-label="Filtrar por empresa"
            >
                <option value="">Todas las empresas</option>
                <option
                    v-for="e in empresasAutorizadas"
                    :key="e.id"
                    :value="e.id"
                >
                    {{ e.nombre_comercial }}
                </option>
            </select>
            <select
                v-model="f.modulo"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todos los módulos</option>
                <option v-for="m in modulos" :key="m" :value="m">
                    {{ m }}
                </option>
            </select>
            <Input
                v-model="f.buscar"
                placeholder="Buscar en descripción o usuario"
                class="max-w-xs"
            />
            <input
                v-model="f.desde"
                type="date"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            />
            <input
                v-model="f.hasta"
                type="date"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            />
        </div>

        <EstadoVacio
            v-if="!registros.data.length"
            titulo="Sin registros"
            descripcion="No hay eventos de auditoría con estos filtros."
        />

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 font-medium">Usuario</th>
                        <th class="px-3 py-2 font-medium">Módulo / Acción</th>
                        <th class="px-3 py-2 font-medium">Descripción</th>
                        <th class="px-3 py-2 font-medium">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in registros.data"
                        :key="r.id"
                        class="border-t align-top"
                    >
                        <td
                            class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                        >
                            {{ fecha(r.fecha) }}
                        </td>
                        <td class="px-3 py-2">{{ r.usuario ?? 'Sistema' }}</td>
                        <td class="px-3 py-2">
                            <span class="font-medium">{{ r.modulo }}</span>
                            <span class="text-muted-foreground"
                                >/{{ r.accion }}</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            {{ r.descripcion }}
                            <span
                                v-if="r.entidad"
                                class="text-muted-foreground block text-xs"
                                >{{ r.entidad }}</span
                            >
                            <span v-if="r.motivo" class="block text-xs italic"
                                >Motivo: {{ r.motivo }}</span
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ r.ip ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="registros.links" :total="registros.total" />
    </div>
</template>
