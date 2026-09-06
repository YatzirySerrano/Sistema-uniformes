<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Package, Pencil, Warehouse } from '@lucide/vue';
import { ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
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

type Componente = {
    activo: string | null;
    codigo: string | null;
    control: 'cantidad' | 'individual' | null;
    cantidad_requerida: number;
    talla: string | null;
    talla_libre: boolean;
};

type Conjunto = {
    id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
    activo: boolean;
    empresa: { id: number; nombre_comercial: string | null };
    componentes: Componente[];
};

type AlmacenDisponibilidad = {
    id: number;
    nombre: string;
    disponibilidad: number;
};

const props = defineProps<{
    conjunto: Conjunto;
    almacenes: AlmacenDisponibilidad[];
    permisos: { editar: boolean; administrar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Conjuntos', href: '/conjuntos' },
            { title: '', href: '#' },
        ],
    },
});

const modalEstado = ref(false);
const procesandoEstado = ref(false);

function alternarEstado(): void {
    if (props.conjunto.activo) {
        modalEstado.value = true; // eliminar => confirmación
    } else {
        // restaurar es seguro: sin confirmación
        router.post(
            `/conjuntos/${props.conjunto.id}/estado`,
            {},
            { preserveScroll: true },
        );
    }
}

function confirmarEstado(): void {
    procesandoEstado.value = true;
    router.post(
        `/conjuntos/${props.conjunto.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                modalEstado.value = false;
            },
        },
    );
}

function variantePara(c: Componente): string {
    if (c.talla_libre) return 'Se elige al entregar';
    return c.talla ?? 'Sin variante';
}
</script>

<template>
    <Head :title="conjunto.nombre" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            :titulo="conjunto.nombre"
            :descripcion="conjunto.descripcion ?? undefined"
        >
            <template #acciones>
                <Button v-if="permisos.editar" variant="outline" as-child>
                    <Link :href="`/conjuntos/${conjunto.id}/editar`">
                        <Pencil class="size-4" /> Editar
                    </Link>
                </Button>
                <Button
                    v-if="permisos.administrar"
                    variant="outline"
                    @click="alternarEstado"
                >
                    {{ conjunto.activo ? 'Eliminar' : 'Restaurar' }}
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap items-center gap-2">
            <Badge :variant="conjunto.activo ? 'success' : 'secondary'">
                {{ conjunto.activo ? 'Activo' : 'Eliminado' }}
            </Badge>
            <Badge variant="outline">{{
                conjunto.empresa.nombre_comercial
            }}</Badge>
            <span
                v-if="conjunto.codigo"
                class="text-muted-foreground font-mono text-xs"
            >
                {{ conjunto.codigo }}
            </span>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section
                class="min-w-0 space-y-3 rounded-xl border p-4 lg:col-span-2"
            >
                <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                    <Boxes class="size-4" /> Componentes
                </h2>

                <EstadoVacio
                    v-if="!conjunto.componentes.length"
                    titulo="Sin componentes"
                    descripcion="Este conjunto todavía no tiene activos asociados."
                />

                <div v-else class="divide-y rounded-lg border">
                    <div
                        v-for="(c, i) in conjunto.componentes"
                        :key="i"
                        class="flex items-center gap-3 p-3"
                    >
                        <span
                            class="bg-muted/60 flex size-9 shrink-0 items-center justify-center rounded-lg border"
                        >
                            <Package class="text-muted-foreground size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ c.activo ?? 'Activo eliminado' }}
                            </p>
                            <p class="text-muted-foreground truncate text-xs">
                                {{ c.codigo ?? '—' }} · {{ variantePara(c) }}
                            </p>
                        </div>
                        <Badge variant="outline">
                            {{ c.cantidad_requerida }}
                            {{
                                c.cantidad_requerida === 1
                                    ? 'unidad'
                                    : 'unidades'
                            }}
                        </Badge>
                    </div>
                </div>
            </section>

            <section class="min-w-0 space-y-3 rounded-xl border p-4">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                    <Warehouse class="size-4" /> Disponibilidad por almacén
                </h2>
                <p class="text-muted-foreground text-xs">
                    Calculada en vivo a partir del stock real de cada componente
                    en ese almacén. Este conjunto no tiene existencia propia.
                </p>

                <EstadoVacio
                    v-if="!almacenes.length"
                    titulo="Sin almacenes"
                    descripcion="Ningún almacén activo abastece a esta empresa."
                />

                <div v-else class="space-y-2">
                    <div
                        v-for="a in almacenes"
                        :key="a.id"
                        class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                    >
                        <span class="truncate">{{ a.nombre }}</span>
                        <Badge
                            :variant="
                                a.disponibilidad > 0 ? 'success' : 'secondary'
                            "
                        >
                            {{ a.disponibilidad }}
                            {{
                                a.disponibilidad === 1
                                    ? 'disponible'
                                    : 'disponibles'
                            }}
                        </Badge>
                    </div>
                </div>
            </section>
        </div>

        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>¿Eliminar este conjunto?</DialogTitle>
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
                        @click="modalEstado = false"
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
