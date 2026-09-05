<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Input } from '@/components/ui/input';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

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
    empresasAutorizadas: EmpresaAutorizada[];
    modulos: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Auditoría', href: '/auditoria' }] },
});

const f = ref({
    empresa_id: props.filtros.empresa_id ?? '',
    modulo: props.filtros.modulo ?? '',
    buscar: props.filtros.buscar ?? '',
    desde: String(props.filtros.desde ?? ''),
    hasta: String(props.filtros.hasta ?? ''),
});

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === f.value.empresa_id) ?? null,
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

watch(empresaSeleccionada, (e) => {
    f.value.empresa_id = e?.id ?? '';
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
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/auditoria/exportar"
                    :filtros="filtros"
                />
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap gap-2">
            <BuscadorAsync
                v-if="empresasAutorizadas.length > 1"
                v-model="empresaSeleccionada"
                :buscar="buscarEmpresas"
                :etiqueta="(e) => String(e.nombre_comercial)"
                placeholder="Todas las empresas"
                placeholder-busqueda="Buscar empresa…"
                class="w-56"
            />
            <div class="w-52">
                <SelectSimple
                    v-model="f.modulo"
                    :opciones="[
                        { valor: '', etiqueta: 'Todos los módulos' },
                        ...modulos.map((m) => ({ valor: m, etiqueta: m })),
                    ]"
                />
            </div>
            <Input
                v-model="f.buscar"
                placeholder="Buscar en descripción o usuario"
                class="max-w-xs"
            />
            <div class="w-40">
                <DatePicker v-model="f.desde" placeholder="Desde" />
            </div>
            <div class="w-40">
                <DatePicker v-model="f.hasta" placeholder="Hasta" />
            </div>
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
