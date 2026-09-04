<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Package, Pencil, Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    permisos: { crear: boolean; editar: boolean; administrar: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conjuntos', href: '/conjuntos' }] },
});

const buscar = ref(props.filtros.buscar);
const empresaId = ref<number | ''>(props.filtros.empresa_id ?? '');
const estado = ref(props.filtros.estado);

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
    empresaId.value = '';
    estado.value = '';
}

const claseSelect =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-2.5 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none';

function alternarEstado(c: Conjunto): void {
    router.post(`/conjuntos/${c.id}/estado`, {}, { preserveScroll: true });
}
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
                    <select
                        v-model="empresaId"
                        :class="claseSelect"
                        aria-label="Filtrar por empresa"
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="e in empresasAutorizadas"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.nombre_comercial }}
                        </option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Estado</span>
                    <select
                        v-model="estado"
                        :class="claseSelect"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">Todos</option>
                        <option value="activos">Activos</option>
                        <option value="inactivos">Inactivos</option>
                    </select>
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
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="c in conjuntos"
                :key="c.id"
                role="button"
                tabindex="0"
                :aria-label="`Ver detalle de ${c.nombre}`"
                class="group focus-visible:ring-ring hover:border-primary/40 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
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
                    <Badge :variant="c.activo ? 'default' : 'secondary'">
                        {{ c.activo ? 'Activo' : 'Inactivo' }}
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
                        {{ c.activo ? 'Desactivar' : 'Activar' }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
