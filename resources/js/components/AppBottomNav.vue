<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ClipboardList, LayoutGrid, Menu, Package, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermisos } from '@/composables/usePermisos';

// Menú inferior fijo, sólo en móvil (`md:hidden`): la navegación principal
// del sistema al alcance del pulgar, sin tener que subir hasta el sidebar de
// arriba cada vez. Deliberadamente corto (máximo 5 accesos) para no saturar
// — el resto de las secciones se abren desde "Más", que reutiliza el MISMO
// sidebar de escritorio (como panel deslizable), nunca un menú duplicado.
const { puede } = usePermisos();
const { isCurrentOrParentUrl } = useCurrentUrl();
const { toggleSidebar } = useSidebar();

const enlaces = computed(() =>
    [
        {
            titulo: 'Panel',
            href: '/dashboard',
            icono: LayoutGrid,
            visible: true,
        },
        {
            titulo: 'Colaboradores',
            href: '/colaboradores',
            icono: Users,
            visible: puede('colaboradores.ver'),
        },
        {
            titulo: 'Activos',
            href: '/activos',
            icono: Package,
            visible: puede('activos.ver'),
        },
        {
            titulo: 'Entregas',
            href: '/entregas',
            icono: ClipboardList,
            visible: puede('entregas.ver'),
        },
    ].filter((e) => e.visible),
);
</script>

<template>
    <nav
        class="bg-background/95 fixed inset-x-0 bottom-0 z-40 flex items-stretch border-t backdrop-blur-sm md:hidden"
        style="padding-bottom: env(safe-area-inset-bottom)"
        aria-label="Navegación principal"
    >
        <Link
            v-for="enlace in enlaces"
            :key="enlace.href"
            :href="enlace.href"
            class="flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[11px]"
            :class="
                isCurrentOrParentUrl(enlace.href)
                    ? 'text-primary'
                    : 'text-muted-foreground'
            "
        >
            <component :is="enlace.icono" class="size-5" />
            {{ enlace.titulo }}
        </Link>
        <button
            type="button"
            class="text-muted-foreground flex flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[11px]"
            aria-label="Ver más secciones"
            @click="toggleSidebar"
        >
            <Menu class="size-5" />
            Más
        </button>
    </nav>
</template>
