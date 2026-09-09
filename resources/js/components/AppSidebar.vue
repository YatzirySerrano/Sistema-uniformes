<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Boxes,
    Building,
    QrCode,
    ClipboardList,
    Compass,
    FileBarChart2,
    LayoutGrid,
    Network,
    Package,
    Palette,
    Ruler,
    ScanLine,
    ScrollText,
    ShieldCheck,
    Store,
    Tags,
    Undo2,
    UserCog,
    Users,
    Warehouse,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
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
            titulo: 'Organización',
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
                    titulo: 'Colaboradores',
                    href: '/colaboradores',
                    icono: Users,
                    visible: puede('colaboradores.ver'),
                },
                {
                    titulo: 'Áreas / Departamentos',
                    href: '/areas',
                    icono: Network,
                    visible: puede('areas.ver'),
                },
            ],
        },
        {
            titulo: 'Inventario y activos',
            enlaces: [
                {
                    titulo: 'Activos',
                    href: '/activos',
                    icono: Package,
                    visible: puede('activos.ver'),
                },
                {
                    titulo: 'Almacenes',
                    href: '/almacenes',
                    icono: Warehouse,
                    visible: puede('almacenes.ver'),
                },
                {
                    titulo: 'Unidades',
                    href: '/activos/unidades',
                    icono: QrCode,
                    visible: puede('unidades-activo.ver'),
                },
                {
                    titulo: 'Conjuntos',
                    href: '/conjuntos',
                    icono: Boxes,
                    visible: puede('conjuntos.ver'),
                },
                {
                    titulo: 'Movimientos',
                    href: '/inventario/movimientos',
                    icono: ArrowLeftRight,
                    visible: puede('inventario.ver'),
                },
                {
                    titulo: 'Inventario físico',
                    href: '/inventarios-fisicos',
                    icono: ScanLine,
                    visible: puede('inventario-fisico.ver'),
                },
                {
                    titulo: 'Tipos y categorías',
                    href: '/activos-catalogos',
                    icono: Tags,
                    visible: puede('activos.ver'),
                },
                {
                    titulo: 'Variantes / tallas',
                    href: '/tallas',
                    icono: Ruler,
                    visible: puede('activos.ver'),
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
            titulo: 'Análisis',
            enlaces: [
                {
                    titulo: 'Reportes',
                    href: '/reportes',
                    icono: FileBarChart2,
                    visible: puede('reportes.ver'),
                },
                {
                    titulo: 'Auditoría',
                    href: '/auditoria',
                    icono: ScrollText,
                    visible: puede('auditoria.ver'),
                },
            ],
        },
        {
            titulo: 'Administración',
            enlaces: [
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
                    titulo: 'Configuración',
                    href: '/configuracion',
                    icono: Palette,
                    visible: puede([
                        'configuracion.ver',
                        'configuracion.administrar',
                    ]),
                },
            ],
        },
        {
            titulo: null,
            enlaces: [
                {
                    titulo: 'Ayuda',
                    href: '/ayuda',
                    icono: Compass,
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
    <Sidebar collapsible="icon" variant="inset" data-tour="menu-lateral">
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

        <SidebarFooter data-tour="menu-usuario">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
