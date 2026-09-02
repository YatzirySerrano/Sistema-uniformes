<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Button } from '@/components/ui/button';
import type { Paginado } from '@/types/sistema';

type Devolucion = {
    id: number;
    folio: string;
    colaborador: string;
    sucursal: string;
    registrada_por: string;
    fecha: string;
    renglones: number;
};

defineProps<{ devoluciones: Paginado<Devolucion>; puedeCrear: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Devoluciones', href: '/devoluciones' }] },
});
</script>

<template>
    <Head title="Devoluciones" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Devoluciones"
            descripcion="Activos devueltas por los colaboradores. Solo las reutilizables reingresan al inventario."
        >
            <template #acciones>
                <Button v-if="puedeCrear" as-child>
                    <Link href="/devoluciones/crear"
                        ><Plus class="size-4" /> Nueva devolución</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

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
