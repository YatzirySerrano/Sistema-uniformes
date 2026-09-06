<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Entrega = {
    id: number;
    folio: string;
    empresa: string | null;
    colaborador: string;
    numero_empleado: string;
    sucursal: string;
    encargado: string;
    estado: string;
    estado_etiqueta: string;
    fecha_entrega: string;
    renglones: number;
};

const props = defineProps<{
    entregas: Paginado<Entrega>;
    filtros: { buscar?: string; empresa_id?: number | null; estado?: string };
    empresasAutorizadas: EmpresaAutorizada[];
    estados: { valor: string; etiqueta: string }[];
    puedeCrear: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Entregas', href: '/entregas' }] },
});

const buscar = ref(props.filtros.buscar ?? '');
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
const estado = ref(props.filtros.estado ?? '');

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

let t: ReturnType<typeof setTimeout>;
watch([buscar, empresaId, estado], () => {
    clearTimeout(t);
    t = setTimeout(() => {
        router.get(
            '/entregas',
            {
                buscar: buscar.value || undefined,
                empresa_id: empresaId.value || undefined,
                estado: estado.value || undefined,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }, 300);
});

function variante(estado: string) {
    return estado === 'pendiente_firma' ? 'secondary' : 'default';
}

const vista = useVistaPreferida('entregas', 'tabla');
</script>

<template>
    <Head title="Entregas" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Entregas de uniformes"
            descripcion="Registra la entrega de uniformes y activos a los colaboradores, con firma de recepción y comprobante."
        >
            <template #acciones>
                <Button v-if="puedeCrear" as-child>
                    <Link href="/entregas/crear"
                        ><Plus class="size-4" /> Nueva entrega</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
                <Search
                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                />
                <Input
                    v-model="buscar"
                    placeholder="Buscar por folio o colaborador"
                    class="pl-8"
                />
            </div>
            <BuscadorAsync
                v-if="empresasAutorizadas.length > 1"
                v-model="empresaSeleccionada"
                :buscar="buscarEmpresas"
                :etiqueta="(e) => String(e.nombre_comercial)"
                placeholder="Todas las empresas"
                placeholder-busqueda="Buscar empresa…"
                class="w-56"
            />
            <div class="w-48">
                <SelectSimple
                    v-model="estado"
                    :opciones="[
                        { valor: '', etiqueta: 'Todos los estados' },
                        ...estados.map((e) => ({
                            valor: e.valor,
                            etiqueta: e.etiqueta,
                        })),
                    ]"
                />
            </div>

            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <EstadoVacio
            v-if="!entregas.data.length"
            titulo="No hay entregas"
            descripcion="Registra la primera entrega para un colaborador."
        >
            <template #acciones>
                <Button v-if="puedeCrear" size="sm" as-child>
                    <Link href="/entregas/crear">Nueva entrega</Link>
                </Button>
            </template>
        </EstadoVacio>

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <Link
                v-for="e in entregas.data"
                :key="e.id"
                :href="`/entregas/${e.id}`"
                class="hover:border-primary/40 flex flex-col gap-2 rounded-xl border p-4 transition-colors"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="font-medium">{{ e.folio }}</p>
                    <Badge :variant="variante(e.estado)">{{
                        e.estado_etiqueta
                    }}</Badge>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ e.colaborador }}
                    <span class="text-xs">· {{ e.numero_empleado }}</span>
                </p>
                <p class="text-muted-foreground text-sm">{{ e.sucursal }}</p>
                <div
                    class="text-muted-foreground mt-auto flex items-center justify-between text-xs"
                >
                    <span>{{ e.fecha_entrega }}</span>
                    <span>{{ e.renglones }} renglón(es)</span>
                </div>
            </Link>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio</th>
                        <th class="px-3 py-2 font-medium">Colaborador</th>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Renglones
                        </th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in entregas.data" :key="e.id" class="border-t">
                        <td class="px-3 py-2 font-medium">{{ e.folio }}</td>
                        <td class="px-3 py-2">
                            {{ e.colaborador }}
                            <span class="text-muted-foreground"
                                >· {{ e.numero_empleado }}</span
                            >
                        </td>
                        <td class="px-3 py-2">{{ e.sucursal }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ e.fecha_entrega }}
                        </td>
                        <td class="px-3 py-2 text-right">{{ e.renglones }}</td>
                        <td class="px-3 py-2">
                            <Badge :variant="variante(e.estado)">{{
                                e.estado_etiqueta
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Link
                                :href="`/entregas/${e.id}`"
                                class="text-primary text-sm hover:underline"
                                >Ver</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="entregas.links" :total="entregas.total" />
    </div>
</template>
