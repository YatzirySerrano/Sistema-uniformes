<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Paginado } from '@/types/sistema';

type Empresa = {
    id: number;
    codigo: string;
    nombre_comercial: string;
    razon_social: string | null;
    activa: boolean;
    sucursales: number;
    colaboradores: number;
    color_principal: string;
};

defineProps<{ empresas: Paginado<Empresa>; puedeCrear: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Empresas', href: '/empresas' }] },
});
</script>

<template>
    <Head title="Empresas" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Empresas"
            descripcion="Organizaciones administradas en la plataforma."
        >
            <template #acciones>
                <Button v-if="puedeCrear" as-child>
                    <Link href="/empresas/crear"
                        ><Plus class="size-4" /> Nueva empresa</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="e in empresas.data"
                :key="e.id"
                class="flex flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-2">
                        <span
                            class="size-3 rounded-full"
                            :style="{ background: e.color_principal }"
                        />
                        <div>
                            <p class="font-medium">{{ e.nombre_comercial }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ e.codigo }}
                            </p>
                        </div>
                    </div>
                    <Badge :variant="e.activa ? 'default' : 'secondary'">{{
                        e.activa ? 'Activa' : 'Inactiva'
                    }}</Badge>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ e.razon_social ?? '—' }}
                </p>
                <div class="text-muted-foreground flex gap-4 text-xs">
                    <span>{{ e.sucursales }} sucursal(es)</span>
                    <span>{{ e.colaboradores }} colaborador(es)</span>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    as-child
                    class="mt-auto w-fit"
                >
                    <Link :href="`/empresas/${e.id}/editar`">Editar</Link>
                </Button>
            </div>
        </div>

        <Paginacion :links="empresas.links" :total="empresas.total" />
    </div>
</template>
