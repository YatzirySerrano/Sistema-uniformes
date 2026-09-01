<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Boxes,
    Building,
    ClipboardList,
    FileBarChart2,
    LayoutGrid,
    Package,
    Palette,
    ScrollText,
    ShieldCheck,
    Shirt,
    Store,
    Undo2,
    UserCog,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import SelectorEmpresa from '@/components/sistema/SelectorEmpresa.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermisos } from '@/composables/usePermisos';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const { puede } = usePermisos();
const { isCurrentOrParentUrl } = useCurrentUrl();

type Enlace = {
    titulo: string;
    href: string;
    icono: unknown;
    visible: boolean;
};
type Grupo = { titulo: string | null; enlaces: Enlace[] };

const grupos = computed<Grupo[]>(() =>
    [
        {
            titulo: null,
            enlaces: [
                {
                    titulo: 'Panel',
                    href: '/dashboard',
                    icono: LayoutGrid,
                    visible: true,
                },
            ],
        },
        {
            titulo: 'Personal',
            enlaces: [
                {
                    titulo: 'Colaboradores',
                    href: '/colaboradores',
                    icono: Users,
                    visible: puede('colaboradores.ver'),
                },
            ],
        },
        {
            titulo: 'Uniformes',
            enlaces: [
                {
                    titulo: 'Prendas',
                    href: '/prendas',
                    icono: Shirt,
                    visible: puede('prendas.ver'),
                },
                {
                    titulo: 'Inventario',
                    href: '/inventario',
                    icono: Boxes,
                    visible: puede('inventario.ver'),
                },
                {
                    titulo: 'Movimientos',
                    href: '/inventario/movimientos',
                    icono: ArrowLeftRight,
                    visible: puede('inventario.ver'),
                },
            ],
        },
        {
            titulo: 'Operación',
            enlaces: [
                {
                    titulo: 'Entregas',
                    href: '/entregas',
                    icono: ClipboardList,
                    visible: puede('entregas.ver'),
                },
                {
                    titulo: 'Devoluciones',
                    href: '/devoluciones',
                    icono: Undo2,
                    visible: puede('devoluciones.ver'),
                },
            ],
        },
        {
            titulo: null,
            enlaces: [
                {
                    titulo: 'Reportes',
                    href: '/reportes',
                    icono: FileBarChart2,
                    visible: puede('reportes.ver'),
                },
            ],
        },
        {
            titulo: 'Administración',
            enlaces: [
                {
                    titulo: 'Empresas',
                    href: '/empresas',
                    icono: Building,
                    visible: puede([
                        'empresas.ver',
                        'configuracion-empresa.ver',
                    ]),
                },
                {
                    titulo: 'Sucursales',
                    href: '/sucursales',
                    icono: Store,
                    visible: puede('sucursales.ver'),
                },
                {
                    titulo: 'Usuarios',
                    href: '/usuarios',
                    icono: UserCog,
                    visible: puede('usuarios.ver'),
                },
                {
                    titulo: 'Roles y permisos',
                    href: '/roles',
                    icono: ShieldCheck,
                    visible: puede('roles.ver'),
                },
                {
                    titulo: 'Personalización',
                    href: '/personalizacion',
                    icono: Palette,
                    visible: puede('configuracion-empresa.ver'),
                },
            ],
        },
        {
            titulo: null,
            enlaces: [
                {
                    titulo: 'Auditoría',
                    href: '/auditoria',
                    icono: ScrollText,
                    visible: puede('auditoria.ver'),
                },
                {
                    titulo: 'Mis entregas',
                    href: '/portal/mis-entregas',
                    icono: Package,
                    visible: true,
                },
            ],
        },
    ]
        .map((g) => ({ ...g, enlaces: g.enlaces.filter((e) => e.visible) }))
        .filter((g) => g.enlaces.length > 0),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SelectorEmpresa />
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup
                v-for="(grupo, i) in grupos"
                :key="i"
                class="px-2 py-0"
            >
                <SidebarGroupLabel v-if="grupo.titulo">{{
                    grupo.titulo
                }}</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="enlace in grupo.enlaces"
                        :key="enlace.href"
                    >
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentOrParentUrl(enlace.href)"
                            :tooltip="enlace.titulo"
                        >
                            <Link :href="enlace.href">
                                <component :is="enlace.icono" />
                                <span>{{ enlace.titulo }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
