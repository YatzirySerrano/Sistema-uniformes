<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Package, Pencil, Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { EmpresaAutorizada } from '@/types/sistema';

type Conjunto = {
    id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
    activo: boolean;
    empresa: { id: number; nombre_comercial: string | null };
    componentes_count: number;
};

const props = defineProps<{
    conjuntos: Conjunto[];
    empresasAutorizadas: EmpresaAutorizada[];
    filtros: {
        buscar: string;
        empresa_id: number | null;
        estado: '' | 'activos' | 'inactivos';
    };
    permisos: {
        crear: boolean;
        editar: boolean;
        administrar: boolean;
        verEliminados: boolean;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conjuntos', href: '/conjuntos' }] },
});

const buscar = ref(props.filtros.buscar);
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');
const estado = ref(props.filtros.estado);
// Resincroniza el filtro si el backend resuelve una empresa distinta a la
// que ya tenía este ref local — nunca se queda con un valor obsoleto ni
// "inventa" la primera empresa de la lista.
watch(
    () => props.filtros.empresa_id,
    (nuevoId) => {
        if (nuevoId !== (empresaSeleccionada.value?.id ?? null)) {
            empresaSeleccionada.value =
                props.empresasAutorizadas.find((e) => e.id === nuevoId) ?? null;
        }
    },
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const hayFiltros = computed(
    () => buscar.value !== '' || empresaId.value !== '' || estado.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, empresaId, estado], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/conjuntos',
            {
                buscar: buscar.value || undefined,
                empresa_id: empresaId.value || undefined,
                estado: estado.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['conjuntos', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    empresaSeleccionada.value = null;
    estado.value = '';
}

// "Eliminados" (internamente `activo = false`) sólo se ofrece a quien puede
// administrar conjuntos — el backend además lo ignora si se fuerza por URL.
const opcionesEstado = computed(() => [
    { valor: '', etiqueta: 'Todos' },
    { valor: 'activos', etiqueta: 'Activos' },
    ...(props.permisos.verEliminados
        ? [{ valor: 'inactivos', etiqueta: 'Eliminados' }]
        : []),
]);

const confirmando = ref<Conjunto | null>(null);
const procesandoEstado = ref(false);

function alternarEstado(c: Conjunto): void {
    if (c.activo) {
        confirmando.value = c; // eliminar => confirmación
    } else {
        // restaurar es seguro: sin confirmación
        router.post(`/conjuntos/${c.id}/estado`, {}, { preserveScroll: true });
    }
}

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/conjuntos/${confirmando.value.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                confirmando.value = null;
            },
        },
    );
}

const vista = useVistaPreferida('conjuntos');
</script>

<template>
    <Head title="Conjuntos" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Conjuntos"
            descripcion="Agrupaciones de activos que se entregan juntos (un uniforme completo, un kit de cómputo…). No tienen existencia propia: su disponibilidad se calcula desde el stock real de cada componente."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/conjuntos/exportar"
                    :filtros="filtros"
                />
                <Button v-if="permisos.crear" as-child>
                    <Link href="/conjuntos/crear">
                        <Plus class="size-4" /> Nuevo conjunto
                    </Link>
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[380px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por nombre o código"
                    aria-label="Buscar conjuntos"
                />
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label
                    v-if="empresasAutorizadas.length > 1"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Empresa</span>
                    <BuscadorAsync
                        v-model="empresaSeleccionada"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        placeholder="Todas"
                        placeholder-busqueda="Buscar empresa…"
                        class="w-56"
                    />
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <div class="w-36">
                        <SelectSimple
                            v-model="estado"
                            :opciones="opcionesEstado"
                        />
                    </div>
                </label>

                <Button
                    v-if="hayFiltros"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="limpiarFiltros"
                >
                    <X class="size-3.5" /> Limpiar filtros
                </Button>

                <SelectorVista v-model="vista" class="ml-auto" />
            </div>
        </div>

        <EstadoVacio
            v-if="!conjuntos.length"
            titulo="No hay conjuntos para mostrar"
            :descripcion="
                hayFiltros
                    ? 'Ningún conjunto coincide con la búsqueda o los filtros aplicados.'
                    : 'Crea el primer conjunto para agrupar activos que se entregan juntos.'
            "
        >
            <template v-if="permisos.crear && !hayFiltros" #acciones>
                <Button as-child>
                    <Link href="/conjuntos/crear">Crear primer conjunto</Link>
                </Button>
            </template>
        </EstadoVacio>

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="c in conjuntos"
                :key="c.id"
                role="button"
                tabindex="0"
                :aria-label="`Ver detalle de ${c.nombre}`"
                class="group focus-visible:ring-ring hover:border-primary/20 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                @click="router.visit(`/conjuntos/${c.id}`)"
                @keydown.enter="router.visit(`/conjuntos/${c.id}`)"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="bg-muted/60 flex size-11 shrink-0 items-center justify-center rounded-lg border"
                        >
                            <Package class="text-muted-foreground size-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ c.nombre }}</p>
                            <p
                                class="text-muted-foreground truncate font-mono text-xs"
                            >
                                {{ c.codigo ?? '—' }}
                            </p>
                        </div>
                    </div>
                    <Badge :variant="c.activo ? 'success' : 'secondary'">
                        {{ c.activo ? 'Activo' : 'Eliminado' }}
                    </Badge>
                </div>

                <Badge variant="outline" class="w-fit gap-1">
                    <Boxes class="size-3" /> {{ c.componentes_count }}
                    {{
                        c.componentes_count === 1 ? 'componente' : 'componentes'
                    }}
                </Badge>

                <p
                    v-if="c.descripcion"
                    class="text-muted-foreground line-clamp-2 text-xs"
                >
                    {{ c.descripcion }}
                </p>

                <div class="mt-auto flex flex-wrap gap-2 pt-1">
                    <Button
                        v-if="permisos.editar"
                        variant="ghost"
                        size="sm"
                        as-child
                        @click.stop
                    >
                        <Link :href="`/conjuntos/${c.id}/editar`">
                            <Pencil class="size-3.5" /> Editar
                        </Link>
                    </Button>
                    <Button
                        v-if="permisos.administrar"
                        variant="ghost"
                        size="sm"
                        @click.stop="alternarEstado(c)"
                    >
                        {{ c.activo ? 'Eliminar' : 'Restaurar' }}
                    </Button>
                </div>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Conjunto</th>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Componentes</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="c in conjuntos"
                        :key="c.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">
                            <p class="font-medium">{{ c.nombre }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ c.codigo ?? '—' }}
                            </p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ c.empresa.nombre_comercial ?? '—' }}
                        </td>
                        <td class="px-3 py-2">{{ c.componentes_count }}</td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="c.activo ? 'success' : 'secondary'"
                            >
                                {{ c.activo ? 'Activo' : 'Eliminado' }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="`/conjuntos/${c.id}`"
                                        >Ver</Link
                                    >
                                </Button>
                                <Button
                                    v-if="permisos.editar"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="`/conjuntos/${c.id}/editar`"
                                        >Editar</Link
                                    >
                                </Button>
                                <Button
                                    v-if="permisos.administrar"
                                    variant="ghost"
                                    size="sm"
                                    @click="alternarEstado(c)"
                                >
                                    {{ c.activo ? 'Eliminar' : 'Restaurar' }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog
            :open="confirmando !== null"
            @update:open="
                (v) => {
                    if (!v) confirmando = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Eliminar el conjunto
                        <span v-if="confirmando">{{ confirmando.nombre }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción lo retirará de los listados y nuevas
                        entregas. Los registros históricos no se eliminarán y
                        podrás restaurarlo cuando quieras.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEstado"
                        @click="confirmando = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoEstado"
                        @click="confirmarEstado"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
