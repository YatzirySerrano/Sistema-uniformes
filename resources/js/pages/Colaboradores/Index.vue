<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet, Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormularioColaborador from '@/components/colaboradores/FormularioColaborador.vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/composables/useInitials';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Colaborador = {
    id: number;
    numero_empleado: string;
    nombre_completo: string;
    puesto: string | null;
    area: string | null;
    activo: boolean;
    foto_url: string | null;
    sucursal: { nombre: string } | null;
    empresa?: { id: number; nombre_comercial: string | null } | null;
};

const { getInitials } = useInitials();

const props = defineProps<{
    colaboradores: Paginado<Colaborador>;
    filtros: {
        buscar?: string;
        empresa_id?: number | null;
        sucursal_id?: number;
        estado?: string;
    };
    empresasAutorizadas: EmpresaAutorizada[];
    sucursales: { id: number; nombre: string }[];
    puedeCrear: boolean;
    puedeImportar: boolean;
    puedeVerEliminados: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Colaboradores', href: '/colaboradores' }],
    },
});

const buscar = ref(props.filtros.buscar ?? '');
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
const sucursalSeleccionada = ref<{ id: number; nombre: string } | null>(
    props.sucursales.find((s) => s.id === props.filtros.sucursal_id) ?? null,
);
const sucursalId = computed(() => sucursalSeleccionada.value?.id ?? '');
const estado = ref(props.filtros.estado ?? 'todos');
// Resincroniza los filtros si el backend resuelve una empresa/sucursal
// distinta a la que ya tenía este ref local (p. ej. al llegar desde el
// acceso directo de Empresas u otra página sin remontar el componente) —
// nunca se queda con un valor obsoleto ni "inventa" la primera empresa.
watch(
    () => props.filtros.empresa_id,
    (nuevoId) => {
        if (nuevoId !== (empresaSeleccionada.value?.id ?? null)) {
            empresaSeleccionada.value =
                props.empresasAutorizadas.find((e) => e.id === nuevoId) ?? null;
        }
    },
);
watch(
    () => props.filtros.sucursal_id,
    (nuevoId) => {
        if (nuevoId !== (sucursalSeleccionada.value?.id ?? null)) {
            sucursalSeleccionada.value =
                props.sucursales.find((s) => s.id === nuevoId) ?? null;
        }
    },
);

// "Eliminados" (internamente `activo = false`) sólo se ofrece a quien puede
// desactivar colaboradores — el backend además lo ignora si se fuerza por
// URL (ni siquiera dentro de "Todos").
const opcionesEstado = computed(() => [
    { valor: 'todos', etiqueta: 'Todos' },
    { valor: 'activos', etiqueta: 'Activos' },
    ...(props.puedeVerEliminados
        ? [{ valor: 'inactivos', etiqueta: 'Eliminados' }]
        : []),
]);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

async function buscarSucursales(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.sucursales.filter((s) => s.nombre.toLowerCase().includes(t));
}

// Cambiar de empresa invalida la sucursal elegida (pertenece a la empresa
// anterior): se limpia en vez de conservarla incompatible.
watch(empresaId, () => {
    sucursalSeleccionada.value = null;
});

let t: ReturnType<typeof setTimeout>;
watch([buscar, empresaId, sucursalId, estado], () => {
    clearTimeout(t);
    t = setTimeout(() => {
        router.get(
            '/colaboradores',
            {
                buscar: buscar.value || undefined,
                empresa_id: empresaId.value || undefined,
                sucursal_id: sucursalId.value || undefined,
                estado: estado.value,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }, 300);
});

function limpiar() {
    buscar.value = '';
    empresaSeleccionada.value = null;
    sucursalSeleccionada.value = null;
    estado.value = 'todos';
}

// Registrar colaborador conserva la sucursal filtrada actual (llegada desde
// una sucursal específica o elegida en el filtro) como preselección — ahora
// vía diálogo, ya no navega a una página aparte.
const sucursalPreseleccionadaNuevo = computed(() =>
    sucursalSeleccionada.value ? { ...sucursalSeleccionada.value } : null,
);

const modalNuevo = ref(false);
const claveFormularioNuevo = ref(0);

function abrirNuevoColaborador(): void {
    claveFormularioNuevo.value++;
    modalNuevo.value = true;
}

function alGuardarNuevo(): void {
    modalNuevo.value = false;
}

const vista = useVistaPreferida('colaboradores', 'tabla');
</script>

<template>
    <Head title="Colaboradores" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Colaboradores"
            descripcion="Personal registrado por empresa. Usa el filtro de empresa para acotar el listado y elegir sucursal."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/colaboradores/exportar"
                    :filtros="filtros"
                />
                <Button v-if="puedeImportar" variant="outline" as-child>
                    <Link href="/colaboradores/importar">
                        <FileSpreadsheet class="size-4" /> Importar desde Excel
                    </Link>
                </Button>
                <Button v-if="puedeCrear" @click="abrirNuevoColaborador">
                    <Plus class="size-4" /> Nuevo colaborador
                </Button>
            </template>
        </EncabezadoPagina>

        <div
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <div class="relative min-w-0 flex-1">
                <Search
                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                />
                <Input
                    v-model="buscar"
                    placeholder="Buscar por nombre o número de empleado"
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
                class="w-full sm:w-auto sm:min-w-[12rem]"
            />
            <BuscadorAsync
                v-model="sucursalSeleccionada"
                :buscar="buscarSucursales"
                :etiqueta="(s) => String(s.nombre)"
                :disabled="!empresaId"
                :placeholder="
                    empresaId ? 'Todas las sucursales' : 'Elige una empresa'
                "
                placeholder-busqueda="Buscar sucursal…"
                class="w-full sm:w-auto sm:min-w-[12rem]"
            />
            <div class="w-full sm:w-auto sm:min-w-[9rem]">
                <SelectSimple v-model="estado" :opciones="opcionesEstado" />
            </div>
            <SelectorVista v-model="vista" />
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
                <Button
                    v-if="puedeCrear"
                    size="sm"
                    @click="abrirNuevoColaborador"
                >
                    Registrar colaborador
                </Button>
            </template>
        </EstadoVacio>

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <Link
                v-for="c in colaboradores.data"
                :key="c.id"
                :href="`/colaboradores/${c.id}`"
                class="hover:border-primary/20 flex flex-col gap-2 rounded-xl border p-4 transition-colors"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <Avatar class="size-8 shrink-0">
                            <AvatarImage
                                v-if="c.foto_url"
                                :src="c.foto_url"
                                :alt="c.nombre_completo"
                            />
                            <AvatarFallback class="text-xs">
                                {{ getInitials(c.nombre_completo) }}
                            </AvatarFallback>
                        </Avatar>
                        <p class="min-w-0 truncate font-medium">
                            {{ c.nombre_completo }}
                        </p>
                    </div>
                    <Badge :variant="c.activo ? 'success' : 'secondary'">
                        {{ c.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
                </div>
                <p class="text-muted-foreground font-mono text-xs">
                    {{ c.numero_empleado }}
                </p>
                <p class="text-muted-foreground text-sm">
                    {{ c.sucursal?.nombre ?? '—' }}
                </p>
                <p class="text-muted-foreground text-sm">
                    {{ [c.puesto, c.area].filter(Boolean).join(' · ') || '—' }}
                </p>
            </Link>
        </div>

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
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2 font-mono">
                            {{ c.numero_empleado }}
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="`/colaboradores/${c.id}`"
                                class="flex items-center gap-2 hover:underline"
                            >
                                <Avatar class="size-6 shrink-0">
                                    <AvatarImage
                                        v-if="c.foto_url"
                                        :src="c.foto_url"
                                        :alt="c.nombre_completo"
                                    />
                                    <AvatarFallback class="text-[10px]">
                                        {{ getInitials(c.nombre_completo) }}
                                    </AvatarFallback>
                                </Avatar>
                                {{ c.nombre_completo }}
                            </Link>
                        </td>
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
                                :variant="c.activo ? 'success' : 'secondary'"
                            >
                                {{ c.activo ? 'Activo' : 'Eliminado' }}
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

        <Dialog v-model:open="modalNuevo">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Nuevo colaborador</DialogTitle>
                    <DialogDescription>
                        El colaborador pertenece a una empresa / razón social y
                        a una de sus sucursales.
                    </DialogDescription>
                </DialogHeader>
                <FormularioColaborador
                    :key="claveFormularioNuevo"
                    :colaborador="null"
                    :empresas-autorizadas="empresasAutorizadas"
                    :sucursal-preseleccionada="sucursalPreseleccionadaNuevo"
                    :empresa-preseleccionada-id="empresaId || null"
                    @guardado="alGuardarNuevo"
                    @cancelar="modalNuevo = false"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
