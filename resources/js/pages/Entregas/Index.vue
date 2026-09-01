<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Paginado } from '@/types/sistema';

type Entrega = {
    id: number;
    folio: string;
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
    filtros: { buscar?: string; sucursal_id?: number; estado?: string };
    sucursales: { id: number; nombre: string }[];
    estados: { valor: string; etiqueta: string }[];
    puedeCrear: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Entregas', href: '/entregas' }] },
});

const buscar = ref(props.filtros.buscar ?? '');
const sucursalId = ref(props.filtros.sucursal_id ?? '');
const estado = ref(props.filtros.estado ?? '');

let t: ReturnType<typeof setTimeout>;
watch([buscar, sucursalId, estado], () => {
    clearTimeout(t);
    t = setTimeout(() => {
        router.get(
            '/entregas',
            {
                buscar: buscar.value || undefined,
                sucursal_id: sucursalId.value || undefined,
                estado: estado.value || undefined,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }, 300);
});

function variante(estado: string) {
    return estado === 'pendiente_firma' ? 'secondary' : 'default';
}
</script>

<template>
    <Head title="Entregas" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Entregas de uniformes"
            descripcion="Registro de dotaciones entregadas a colaboradores."
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
            <select
                v-model="sucursalId"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todas las sucursales</option>
                <option v-for="s in sucursales" :key="s.id" :value="s.id">
                    {{ s.nombre }}
                </option>
            </select>
            <select
                v-model="estado"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todos los estados</option>
                <option v-for="e in estados" :key="e.valor" :value="e.valor">
                    {{ e.etiqueta }}
                </option>
            </select>
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
