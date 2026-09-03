<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Button } from '@/components/ui/button';
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

const empresaId = ref(props.filtros.empresa_id ?? '');
watch(empresaId, (id) => {
    router.get('/devoluciones', id ? { empresa_id: id } : {}, {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
});
</script>

<template>
    <Head title="Devoluciones" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Devoluciones"
            descripcion="Registra los activos o prendas que un colaborador regresa a un almacén y su condición. Solo los reutilizables reingresan al inventario."
        >
            <template #acciones>
                <Button v-if="puedeCrear" as-child>
                    <Link href="/devoluciones/crear"
                        ><Plus class="size-4" /> Nueva devolución</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <label
            v-if="empresasAutorizadas.length > 1"
            class="flex w-fit items-center gap-1.5 text-sm"
        >
            <span class="text-muted-foreground">Empresa</span>
            <select
                v-model="empresaId"
                class="border-input bg-background h-9 rounded-md border px-2.5 text-sm"
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
        </label>

        <EstadoVacio
            v-if="!devoluciones.data.length"
            titulo="No hay devoluciones"
            descripcion="Registra una devolución cuando un colaborador entregue activos."
        />

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
                        class="border-t"
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
