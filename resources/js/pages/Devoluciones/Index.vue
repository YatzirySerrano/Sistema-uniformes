<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import { Button } from '@/components/ui/button';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Devolucion = {
    id: number;
    folio: string;
    empresa: string | null;
    colaborador: string;
    sucursal: string;
    registrada_por: string;
    fecha: string;
    renglones: number;
};

const props = defineProps<{
    devoluciones: Paginado<Devolucion>;
    filtros: { empresa_id?: number | null };
    empresasAutorizadas: EmpresaAutorizada[];
    puedeCrear: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Devoluciones', href: '/devoluciones' }] },
});

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

watch(empresaSeleccionada, (e) => {
    router.get('/devoluciones', e ? { empresa_id: e.id } : {}, {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
});

const vista = useVistaPreferida('devoluciones', 'tabla');
</script>

<template>
    <Head title="Devoluciones" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Devoluciones"
            descripcion="Registra los activos o prendas que un colaborador regresa a un almacén y su condición. Solo los reutilizables reingresan al inventario."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/devoluciones/exportar"
                    :filtros="filtros"
                />
                <Button v-if="puedeCrear" as-child>
                    <Link href="/devoluciones/crear"
                        ><Plus class="size-4" /> Nueva devolución</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap items-center gap-2">
            <label
                v-if="empresasAutorizadas.length > 1"
                class="flex w-fit items-center gap-1.5 text-sm"
            >
                <span class="text-muted-foreground">Empresa</span>
                <BuscadorAsync
                    v-model="empresaSeleccionada"
                    :buscar="buscarEmpresas"
                    :etiqueta="(e) => String(e.nombre_comercial)"
                    placeholder="Todas las empresas"
                    placeholder-busqueda="Buscar empresa…"
                    class="w-56"
                />
            </label>
            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <EstadoVacio
            v-if="!devoluciones.data.length"
            titulo="No hay devoluciones"
            descripcion="Registra una devolución cuando un colaborador entregue activos."
        />

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="d in devoluciones.data"
                :key="d.id"
                class="flex flex-col gap-2 rounded-xl border p-4"
            >
                <p class="font-medium">{{ d.folio }}</p>
                <p class="text-muted-foreground text-sm">{{ d.colaborador }}</p>
                <p class="text-muted-foreground text-sm">{{ d.sucursal }}</p>
                <div
                    class="text-muted-foreground mt-auto flex items-center justify-between text-xs"
                >
                    <span>{{ d.fecha }}</span>
                    <span>{{ d.renglones }} renglón(es)</span>
                </div>
                <p class="text-muted-foreground text-xs">
                    Registró: {{ d.registrada_por }}
                </p>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Folio</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Colaborador</th>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Renglones
                        </th>
                        <th class="px-3 py-2 font-medium">Registró</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="d in devoluciones.data"
                        :key="d.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2 font-medium">{{ d.folio }}</td>
                        <td class="px-3 py-2">{{ d.empresa }}</td>
                        <td class="px-3 py-2">{{ d.colaborador }}</td>
                        <td class="px-3 py-2">{{ d.sucursal }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ d.fecha }}
                        </td>
                        <td class="px-3 py-2 text-right">{{ d.renglones }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ d.registrada_por }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="devoluciones.links" :total="devoluciones.total" />
    </div>
</template>
