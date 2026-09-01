<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Ruler } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Prenda = {
    id: number;
    nombre: string;
    categoria: string | null;
    codigo_interno: string | null;
    activa: boolean;
    imagen_url: string | null;
    tallas: string[];
    existencias: number;
    tallas_bajo_minimo: number;
};

defineProps<{ prendas: Prenda[]; puedeCrear: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Prendas', href: '/prendas' }] },
});
</script>

<template>
    <Head title="Prendas" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Prendas"
            descripcion="Catálogo de prendas de uniforme de la empresa activa."
        >
            <template #acciones>
                <Button variant="outline" as-child>
                    <Link href="/tallas"><Ruler class="size-4" /> Tallas</Link>
                </Button>
                <Button v-if="puedeCrear" as-child>
                    <Link href="/prendas/crear"
                        ><Plus class="size-4" /> Nueva prenda</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <EstadoVacio
            v-if="!prendas.length"
            titulo="No hay prendas registradas"
            descripcion="Crea la primera prenda para empezar a controlar el inventario."
        >
            <template #acciones>
                <Button v-if="puedeCrear" size="sm" as-child>
                    <Link href="/prendas/crear">Crear primera prenda</Link>
                </Button>
            </template>
        </EstadoVacio>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <div
                v-for="p in prendas"
                :key="p.id"
                class="flex flex-col overflow-hidden rounded-xl border"
            >
                <div class="bg-muted flex h-36 items-center justify-center">
                    <img
                        v-if="p.imagen_url"
                        :src="p.imagen_url"
                        :alt="p.nombre"
                        class="h-full w-full object-cover"
                    />
                    <span v-else class="text-muted-foreground text-xs"
                        >Sin imagen</span
                    >
                </div>
                <div class="flex flex-1 flex-col gap-2 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ p.nombre }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ p.codigo_interno ?? '—' }}
                            </p>
                        </div>
                        <Badge :variant="p.activa ? 'default' : 'secondary'">
                            {{ p.activa ? 'Activa' : 'Inactiva' }}
                        </Badge>
                    </div>

                    <div class="flex flex-wrap gap-1">
                        <span
                            v-for="t in p.tallas"
                            :key="t"
                            class="bg-muted rounded px-1.5 py-0.5 text-[11px]"
                            >{{ t }}</span
                        >
                        <span
                            v-if="!p.tallas.length"
                            class="text-muted-foreground text-xs"
                            >Sin tallas asignadas</span
                        >
                    </div>

                    <div
                        class="mt-auto flex items-center justify-between text-sm"
                    >
                        <span
                            ><span class="font-semibold">{{
                                p.existencias
                            }}</span>
                            <span class="text-muted-foreground">
                                en stock</span
                            ></span
                        >
                        <span
                            v-if="p.tallas_bajo_minimo"
                            class="text-xs text-amber-600"
                            >{{ p.tallas_bajo_minimo }} talla(s) al mínimo</span
                        >
                    </div>

                    <div class="flex gap-2 pt-1">
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="`/prendas/${p.id}`"
                                >Ver inventario</Link
                            >
                        </Button>
                        <Button size="sm" variant="ghost" as-child>
                            <Link :href="`/prendas/${p.id}/editar`"
                                >Editar</Link
                            >
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
