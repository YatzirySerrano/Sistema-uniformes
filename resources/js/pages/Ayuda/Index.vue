<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Boxes,
    Building,
    ClipboardList,
    Compass,
    FileBarChart2,
    LayoutGrid,
    Network,
    Package,
    Palette,
    QrCode,
    Ruler,
    ScrollText,
    ShieldCheck,
    Store,
    Tags,
    Undo2,
    UserCog,
    Users,
    Warehouse,
} from '@lucide/vue';
import { programarTourPendiente } from '@/composables/useTourGuiado';
import { cn } from '@/lib/utils';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});

type Modulo = {
    tourId: string;
    titulo: string;
    href: string;
    icono: unknown;
    descripcion: string;
    tono: string;
};

type Seccion = { titulo: string; modulos: Modulo[] };

const TONO_ORGANIZACION = 'bg-blue-500/10 text-blue-600 dark:text-blue-400';
const TONO_INVENTARIO = 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400';
const TONO_OPERACION =
    'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
const TONO_ANALISIS = 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
const TONO_ADMIN = 'bg-slate-500/10 text-slate-600 dark:text-slate-400';

const secciones: Seccion[] = [
    {
        titulo: 'Panel',
        modulos: [
            {
                tourId: 'dashboard',
                titulo: 'Dashboard',
                href: '/dashboard',
                icono: LayoutGrid,
                descripcion:
                    'Resumen operativo: KPIs, gráficas de actividad, entregas recientes y alertas de inventario.',
                tono: TONO_ORGANIZACION,
            },
        ],
    },
    {
        titulo: 'Organización',
        modulos: [
            {
                tourId: 'empresas',
                titulo: 'Empresas',
                href: '/empresas',
                icono: Building,
                descripcion:
                    'Cada razón social independiente, con su propia marca y colaboradores.',
                tono: TONO_ORGANIZACION,
            },
            {
                tourId: 'sucursales',
                titulo: 'Sucursales',
                href: '/sucursales',
                icono: Store,
                descripcion:
                    'El destino/contexto del colaborador — no una dimensión de inventario.',
                tono: TONO_ORGANIZACION,
            },
            {
                tourId: 'colaboradores',
                titulo: 'Colaboradores',
                href: '/colaboradores',
                icono: Users,
                descripcion:
                    'Registra al personal y accede a su expediente digital y su historial.',
                tono: TONO_ORGANIZACION,
            },
            {
                tourId: 'areas',
                titulo: 'Áreas / Departamentos',
                href: '/areas',
                icono: Network,
                descripcion:
                    'Organiza a tus colaboradores por área o departamento dentro de cada empresa.',
                tono: TONO_ORGANIZACION,
            },
        ],
    },
    {
        titulo: 'Inventario y activos',
        modulos: [
            {
                tourId: 'activos',
                titulo: 'Activos',
                href: '/activos',
                icono: Package,
                descripcion:
                    'El catálogo de todo lo que la empresa puede entregar. Aquí también agregas existencias.',
                tono: TONO_INVENTARIO,
            },
            {
                tourId: 'almacenes',
                titulo: 'Almacenes',
                href: '/almacenes',
                icono: Warehouse,
                descripcion:
                    'Un almacén puede abastecer a varias empresas a la vez.',
                tono: TONO_INVENTARIO,
            },
            {
                tourId: 'unidades',
                titulo: 'Unidades',
                href: '/activos/unidades',
                icono: QrCode,
                descripcion:
                    'Activos con seguimiento individual, cada uno con su propio código y QR.',
                tono: TONO_INVENTARIO,
            },
            {
                tourId: 'conjuntos',
                titulo: 'Conjuntos',
                href: '/conjuntos',
                icono: Boxes,
                descripcion:
                    'Agrupa varios activos como una sola plantilla de entrega, sin stock propio.',
                tono: TONO_INVENTARIO,
            },
            {
                tourId: 'movimientos',
                titulo: 'Movimientos',
                href: '/inventario/movimientos',
                icono: ArrowLeftRight,
                descripcion:
                    'El historial completo de cada entrada, salida, entrega y devolución.',
                tono: TONO_INVENTARIO,
            },
            {
                tourId: 'catalogos',
                titulo: 'Tipos y categorías',
                href: '/activos-catalogos',
                icono: Tags,
                descripcion: 'Catálogos globales de la plataforma.',
                tono: TONO_INVENTARIO,
            },
            {
                tourId: 'tallas',
                titulo: 'Variantes / tallas',
                href: '/tallas',
                icono: Ruler,
                descripcion:
                    'Catálogo global de variantes, habilitables por empresa.',
                tono: TONO_INVENTARIO,
            },
        ],
    },
    {
        titulo: 'Operación',
        modulos: [
            {
                tourId: 'entregas',
                titulo: 'Entregas',
                href: '/entregas',
                icono: ClipboardList,
                descripcion:
                    'Registra qué activos recibe cada colaborador. Requiere doble firma para concretarse.',
                tono: TONO_OPERACION,
            },
            {
                tourId: 'devoluciones',
                titulo: 'Devoluciones',
                href: '/devoluciones',
                icono: Undo2,
                descripcion:
                    'Registra qué activos regresa un colaborador. El inventario se actualiza al firmar.',
                tono: TONO_OPERACION,
            },
        ],
    },
    {
        titulo: 'Análisis',
        modulos: [
            {
                tourId: 'reportes',
                titulo: 'Reportes',
                href: '/reportes',
                icono: FileBarChart2,
                descripcion: 'Genera reportes en Excel o PDF.',
                tono: TONO_ANALISIS,
            },
            {
                tourId: 'auditoria',
                titulo: 'Auditoría',
                href: '/auditoria',
                icono: ScrollText,
                descripcion:
                    'Bitácora de todo lo que ocurre en el sistema, sin posibilidad de edición.',
                tono: TONO_ANALISIS,
            },
        ],
    },
    {
        titulo: 'Administración',
        modulos: [
            {
                tourId: 'usuarios',
                titulo: 'Usuarios',
                href: '/usuarios',
                icono: UserCog,
                descripcion: 'Administra las cuentas del sistema.',
                tono: TONO_ADMIN,
            },
            {
                tourId: 'roles',
                titulo: 'Roles y permisos',
                href: '/roles',
                icono: ShieldCheck,
                descripcion:
                    'Crea roles personalizados y decide qué puede hacer cada uno.',
                tono: TONO_ADMIN,
            },
            {
                tourId: 'configuracion',
                titulo: 'Configuración',
                href: '/configuracion',
                icono: Palette,
                descripcion: 'Personalización visual global del sistema.',
                tono: TONO_ADMIN,
            },
        ],
    },
];

function irYGuiar(modulo: Modulo): void {
    programarTourPendiente(modulo.href, modulo.tourId);
    router.visit(modulo.href);
}
</script>

<template>
    <Head title="Ayuda" />

    <div class="flex w-full flex-col gap-8 p-4">
        <div
            class="from-primary/10 via-primary/5 flex flex-col items-center gap-6 rounded-2xl bg-gradient-to-br to-transparent p-6 text-center sm:p-10"
        >
            <svg
                viewBox="0 0 220 140"
                class="text-primary h-32 w-auto sm:h-40"
                fill="none"
                aria-hidden="true"
            >
                <rect
                    x="14"
                    y="40"
                    width="46"
                    height="46"
                    rx="6"
                    class="fill-current opacity-15"
                />
                <rect
                    x="34"
                    y="60"
                    width="46"
                    height="46"
                    rx="6"
                    class="fill-current opacity-25"
                />
                <rect
                    x="60"
                    y="20"
                    width="46"
                    height="46"
                    rx="6"
                    class="fill-current opacity-35"
                />
                <rect
                    x="92"
                    y="52"
                    width="98"
                    height="72"
                    rx="10"
                    class="fill-background stroke-current"
                    stroke-width="3"
                />
                <line
                    x1="108"
                    y1="70"
                    x2="166"
                    y2="70"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                />
                <line
                    x1="108"
                    y1="84"
                    x2="166"
                    y2="84"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    opacity="0.6"
                />
                <line
                    x1="108"
                    y1="98"
                    x2="150"
                    y2="98"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    opacity="0.6"
                />
                <circle cx="176" cy="108" r="14" class="fill-current" />
                <path
                    d="M170 108l4 4 8-8"
                    stroke="var(--color-background)"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    fill="none"
                />
            </svg>

            <div class="max-w-xl space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Guía del sistema
                </h1>
                <p
                    class="text-muted-foreground text-sm text-pretty sm:text-base"
                >
                    Elige un módulo para ver de qué se trata. "Ver cómo
                    funciona" te lleva ahí mismo y arranca un recorrido guiado
                    que resalta, sobre la propia pantalla, para qué sirve cada
                    parte.
                </p>
            </div>
        </div>

        <div
            v-for="(seccion, indiceSeccion) in secciones"
            :key="seccion.titulo"
            class="animate-in fade-in slide-in-from-bottom-2 fill-mode-both space-y-3"
            :style="{ animationDelay: `${indiceSeccion * 60}ms` }"
        >
            <h2
                class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
            >
                {{ seccion.titulo }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="modulo in seccion.modulos"
                    :key="modulo.tourId"
                    class="hover:border-primary/20 flex flex-col gap-3 rounded-xl border p-4 transition-[border-color,box-shadow] duration-200 hover:shadow-sm"
                >
                    <div class="flex items-start gap-3">
                        <span
                            :class="
                                cn(
                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                    modulo.tono,
                                )
                            "
                        >
                            <component :is="modulo.icono" class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium">{{ modulo.titulo }}</p>
                            <p
                                class="text-muted-foreground text-sm text-pretty"
                            >
                                {{ modulo.descripcion }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-auto flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="text-primary inline-flex items-center gap-1.5 text-sm font-medium hover:underline"
                            @click="irYGuiar(modulo)"
                        >
                            <Compass class="size-3.5" /> Ver cómo funciona
                        </button>
                        <Link
                            :href="modulo.href"
                            class="text-muted-foreground ml-auto text-xs hover:underline"
                        >
                            Ir directo
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
