<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet, Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Paginado } from '@/types/sistema';

type Colaborador = {
    id: number;
    numero_empleado: string;
    nombre_completo: string;
    puesto: string | null;
    area: string | null;
    activo: boolean;
    sucursal: { nombre: string } | null;
};

const props = defineProps<{
    colaboradores: Paginado<Colaborador>;
    filtros: { buscar?: string; sucursal_id?: number; estado?: string };
    sucursales: { id: number; nombre: string }[];
    puedeCrear: boolean;
    puedeImportar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Colaboradores', href: '/colaboradores' }],
    },
});

const buscar = ref(props.filtros.buscar ?? '');
const sucursalId = ref(props.filtros.sucursal_id ?? '');
const estado = ref(props.filtros.estado ?? 'activos');

let t: ReturnType<typeof setTimeout>;
watch([buscar, sucursalId, estado], () => {
    clearTimeout(t);
    t = setTimeout(() => {
        router.get(
            '/colaboradores',
            {
                buscar: buscar.value || undefined,
                sucursal_id: sucursalId.value || undefined,
                estado: estado.value,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }, 300);
});

function limpiar() {
    buscar.value = '';
    sucursalId.value = '';
    estado.value = 'todos';
}

// Registrar colaborador conserva la sucursal filtrada actual (llegada desde
// una sucursal específica o elegida en el filtro) como preselección.
const hrefNuevoColaborador = computed(() =>
    sucursalId.value
        ? `/colaboradores/crear?sucursal_id=${sucursalId.value}`
        : '/colaboradores/crear',
);
</script>

<template>
    <Head title="Colaboradores" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Colaboradores"
            descripcion="Personal registrado de la empresa activa."
        >
            <template #acciones>
                <Button v-if="puedeImportar" variant="outline" as-child>
                    <Link href="/colaboradores/importar">
                        <FileSpreadsheet class="size-4" /> Importar desde Excel
                    </Link>
                </Button>
                <Button v-if="puedeCrear" as-child>
                    <Link :href="hrefNuevoColaborador">
                        <Plus class="size-4" /> Nuevo colaborador
                    </Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                />
                <Input
                    v-model="buscar"
                    placeholder="Buscar por nombre o número de empleado"
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
                <option value="activos">Activos</option>
                <option value="inactivos">Inactivos</option>
                <option value="todos">Todos</option>
            </select>
        </div>

        <EstadoVacio
            v-if="!colaboradores.data.length"
            titulo="No hay colaboradores"
            descripcion="No encontramos colaboradores con los filtros actuales."
        >
            <template #acciones>
                <Button variant="outline" size="sm" @click="limpiar"
                    >Limpiar filtros</Button
                >
                <Button v-if="puedeCrear" size="sm" as-child>
                    <Link :href="hrefNuevoColaborador"
                        >Registrar colaborador</Link
                    >
                </Button>
            </template>
        </EstadoVacio>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">N.º empleado</th>
                        <th class="px-3 py-2 font-medium">Nombre</th>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Puesto / Área</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="c in colaboradores.data"
                        :key="c.id"
                        class="border-t"
                    >
                        <td class="px-3 py-2 font-mono">
                            {{ c.numero_empleado }}
                        </td>
                        <td class="px-3 py-2">{{ c.nombre_completo }}</td>
                        <td class="px-3 py-2">
                            {{ c.sucursal?.nombre ?? '—' }}
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{
                                [c.puesto, c.area]
                                    .filter(Boolean)
                                    .join(' · ') || '—'
                            }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="c.activo ? 'default' : 'secondary'"
                            >
                                {{ c.activo ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Link
                                :href="`/colaboradores/${c.id}/editar`"
                                class="text-primary text-sm hover:underline"
                                >Editar</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="colaboradores.links" :total="colaboradores.total" />
    </div>
</template>
