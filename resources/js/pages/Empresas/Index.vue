<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Building2,
    Pencil,
    Plus,
    Search,
    SquareArrowOutUpRight,
    Store,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { EmpresaEditable } from '@/components/empresas/FormularioEmpresa.vue';
import FormularioEmpresa from '@/components/empresas/FormularioEmpresa.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePermisos } from '@/composables/usePermisos';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import type { Paginado } from '@/types/sistema';

type EmpresaTarjeta = EmpresaEditable & {
    sucursales_activas: number;
    colaboradores_activos: number;
    logo_url: string | null;
};

type FiltroTri = '' | 'con' | 'sin';

const props = defineProps<{
    empresas: Paginado<EmpresaTarjeta>;
    filtros: {
        buscar: string;
        estado: '' | 'activas' | 'inactivas';
        sucursales: FiltroTri;
        colaboradores: FiltroTri;
        orden: 'az' | 'za';
    };
    puedeCrear: boolean;
    puedeEditar: boolean;
    puedeVerEliminadas: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Empresas', href: '/empresas' }] },
});

const buscar = ref(props.filtros.buscar);
const estado = ref<'' | 'activas' | 'inactivas'>(props.filtros.estado);
const sucursales = ref<FiltroTri>(props.filtros.sucursales);
const colaboradores = ref<FiltroTri>(props.filtros.colaboradores);
const orden = ref<'az' | 'za'>(props.filtros.orden);

const hayFiltrosActivos = computed(
    () =>
        buscar.value !== '' ||
        estado.value !== '' ||
        sucursales.value !== '' ||
        colaboradores.value !== '' ||
        orden.value !== 'az',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, estado, sucursales, colaboradores, orden], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/empresas',
            {
                buscar: buscar.value || undefined,
                estado: estado.value || undefined,
                sucursales: sucursales.value || undefined,
                colaboradores: colaboradores.value || undefined,
                orden: orden.value === 'az' ? undefined : orden.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['empresas', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    estado.value = '';
    sucursales.value = '';
    colaboradores.value = '';
    orden.value = 'az';
}

// "Eliminadas" (internamente `activa = false`) sólo se ofrece a quien puede
// editar empresas — el backend además la ignora si se fuerza por URL.
const filtrosEstado = computed<
    { valor: '' | 'activas' | 'inactivas'; texto: string }[]
>(() => [
    { valor: '', texto: 'Todas' },
    { valor: 'activas', texto: 'Activas' },
    ...(props.puedeVerEliminadas
        ? ([{ valor: 'inactivas', texto: 'Eliminadas' }] as const)
        : []),
]);

// --- Modal de alta / edición ---
const modalAbierto = ref(false);
const empresaEnEdicion = ref<EmpresaEditable | null>(null);
const logoUrlEnEdicion = ref<string | null>(null);
// Fuerza el remontaje del formulario para reiniciar su estado en cada apertura.
const claveFormulario = ref(0);

function nuevaEmpresa(): void {
    empresaEnEdicion.value = null;
    logoUrlEnEdicion.value = null;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function editarEmpresa(empresa: EmpresaTarjeta): void {
    empresaEnEdicion.value = {
        id: empresa.id,
        codigo: empresa.codigo,
        nombre_comercial: empresa.nombre_comercial,
        razon_social: empresa.razon_social,
        rfc: empresa.rfc,
        telefono: empresa.telefono,
        correo: empresa.correo,
        direccion: empresa.direccion,
        activa: empresa.activa,
    };
    logoUrlEnEdicion.value = empresa.logo_url;
    claveFormulario.value++;
    modalAbierto.value = true;
}

function alGuardar(): void {
    modalAbierto.value = false;
}

// --- Navegación al detalle ---
function verDetalle(empresa: EmpresaTarjeta): void {
    router.visit(`/empresas/${empresa.id}`);
}

const vista = useVistaPreferida('empresas');

// --- KPIs clicables (Sucursales activas / Colaboradores activos) ---
const { puede } = usePermisos();
const puedeVerSucursales = computed(() => puede('sucursales.ver'));
const puedeVerColaboradores = computed(() => puede('colaboradores.ver'));

function hrefSucursalesDe(empresa: EmpresaTarjeta): string {
    return `/sucursales?empresa_id=${empresa.id}`;
}

function hrefColaboradoresDe(empresa: EmpresaTarjeta): string {
    return `/colaboradores?empresa_id=${empresa.id}`;
}
</script>

<template>
    <Head title="Empresas" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Empresas"
            descripcion="Organizaciones que tienes autorizadas en la plataforma."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/empresas/exportar"
                    :filtros="filtros"
                />
                <Button v-if="puedeCrear" @click="nuevaEmpresa">
                    <Plus class="size-4" /> Nueva empresa
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[460px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por nombre, código, razón social o RFC"
                    aria-label="Buscar por nombre, código, razón social o RFC"
                />
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div
                    class="flex gap-1"
                    role="group"
                    aria-label="Filtrar por estado"
                >
                    <Button
                        v-for="f in filtrosEstado"
                        :key="f.valor"
                        type="button"
                        size="sm"
                        :variant="estado === f.valor ? 'default' : 'outline'"
                        @click="estado = f.valor"
                    >
                        {{ f.texto }}
                    </Button>
                </div>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Sucursales</span>
                    <div class="w-44">
                        <SelectSimple
                            v-model="sucursales"
                            :opciones="[
                                { valor: '', etiqueta: 'Todas' },
                                { valor: 'con', etiqueta: 'Con sucursales' },
                                { valor: 'sin', etiqueta: 'Sin sucursales' },
                            ]"
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Colaboradores</span>
                    <div class="w-48">
                        <SelectSimple
                            v-model="colaboradores"
                            :opciones="[
                                { valor: '', etiqueta: 'Todas' },
                                { valor: 'con', etiqueta: 'Con colaboradores' },
                                { valor: 'sin', etiqueta: 'Sin colaboradores' },
                            ]"
                        />
                    </div>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Orden</span>
                    <div class="w-36">
                        <SelectSimple
                            v-model="orden"
                            :opciones="[
                                { valor: 'az', etiqueta: 'Nombre A–Z' },
                                { valor: 'za', etiqueta: 'Nombre Z–A' },
                            ]"
                        />
                    </div>
                </label>

                <Button
                    v-if="hayFiltrosActivos"
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
            v-if="!empresas.data.length"
            titulo="No hay empresas para mostrar"
            :descripcion="
                hayFiltrosActivos
                    ? 'Ninguna empresa coincide con la búsqueda o los filtros aplicados.'
                    : puedeCrear
                      ? 'Registra la primera empresa para empezar a operar.'
                      : 'Aún no tienes empresas asignadas. Solicita acceso a un administrador.'
            "
        >
            <template v-if="hayFiltrosActivos" #acciones>
                <Button variant="outline" @click="limpiarFiltros">
                    <X class="size-4" /> Limpiar filtros
                </Button>
            </template>
            <template v-else-if="puedeCrear" #acciones>
                <Button @click="nuevaEmpresa">
                    <Plus class="size-4" /> Nueva empresa
                </Button>
            </template>
        </EstadoVacio>

        <TooltipProvider v-else-if="vista === 'cards'" :delay-duration="150">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="e in empresas.data"
                    :key="e.id"
                    role="button"
                    tabindex="0"
                    :aria-label="`Ver detalles de ${e.nombre_comercial}`"
                    class="group focus-visible:ring-ring hover:border-primary/20 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="verDetalle(e)"
                    @keydown.enter="verDetalle(e)"
                    @keydown.space.prevent="verDetalle(e)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="bg-muted flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border"
                            >
                                <img
                                    v-if="e.logo_url"
                                    :src="e.logo_url"
                                    :alt="`Logotipo de ${e.nombre_comercial}`"
                                    class="size-full object-contain"
                                />
                                <Building2
                                    v-else
                                    class="text-muted-foreground size-4"
                                />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ e.nombre_comercial }}
                                </p>
                                <p
                                    class="text-muted-foreground font-mono text-xs"
                                >
                                    {{ e.codigo }}
                                </p>
                            </div>
                        </div>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Badge
                                    :variant="
                                        e.activa ? 'success' : 'secondary'
                                    "
                                >
                                    {{ e.activa ? 'Activa' : 'Eliminada' }}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                {{
                                    e.activa
                                        ? 'Estado operativo: la empresa puede operar normalmente.'
                                        : 'Estado operativo: sus operaciones están restringidas hasta reactivarla.'
                                }}
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <p class="text-muted-foreground line-clamp-1 text-sm">
                        {{ e.razon_social ?? 'Sin razón social registrada' }}
                    </p>

                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div
                            v-if="puedeVerSucursales"
                            role="button"
                            tabindex="0"
                            :aria-label="`Ver sucursales activas de ${e.nombre_comercial} en el módulo Sucursales`"
                            class="bg-muted/40 hover:bg-muted/70 hover:border-primary/20 focus-visible:ring-ring rounded-lg border border-transparent px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            @click.stop="router.visit(hrefSucursalesDe(e))"
                            @keydown.enter.stop="
                                router.visit(hrefSucursalesDe(e))
                            "
                            @keydown.space.stop.prevent="
                                router.visit(hrefSucursalesDe(e))
                            "
                        >
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Store class="size-3" />
                                Sucursales activas
                                <span @click.stop>
                                    <AyudaTooltip
                                        texto="Número de sucursales de esta empresa marcadas como activas. Las sucursales inactivas no se cuentan."
                                        etiqueta="Ayuda sobre sucursales activas"
                                    />
                                </span>
                                <ArrowUpRight
                                    class="text-muted-foreground/70 ml-auto size-3.5"
                                />
                            </p>
                            <p class="text-lg font-semibold">
                                {{ e.sucursales_activas }}
                            </p>
                        </div>
                        <div v-else class="bg-muted/40 rounded-lg px-3 py-2">
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Store class="size-3" />
                                Sucursales activas
                                <AyudaTooltip
                                    texto="Número de sucursales de esta empresa marcadas como activas. Las sucursales inactivas no se cuentan."
                                    etiqueta="Ayuda sobre sucursales activas"
                                />
                            </p>
                            <p class="text-lg font-semibold">
                                {{ e.sucursales_activas }}
                            </p>
                        </div>

                        <div
                            v-if="puedeVerColaboradores"
                            role="button"
                            tabindex="0"
                            :aria-label="`Ver colaboradores activos de ${e.nombre_comercial} en el módulo Colaboradores`"
                            class="bg-muted/40 hover:bg-muted/70 hover:border-primary/20 focus-visible:ring-ring rounded-lg border border-transparent px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            @click.stop="router.visit(hrefColaboradoresDe(e))"
                            @keydown.enter.stop="
                                router.visit(hrefColaboradoresDe(e))
                            "
                            @keydown.space.stop.prevent="
                                router.visit(hrefColaboradoresDe(e))
                            "
                        >
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Users class="size-3" />
                                Colaboradores activos
                                <span @click.stop>
                                    <AyudaTooltip
                                        texto="Número de colaboradores con estado activo. No incluye colaboradores inactivos ni dados de baja."
                                        etiqueta="Ayuda sobre colaboradores activos"
                                    />
                                </span>
                                <ArrowUpRight
                                    class="text-muted-foreground/70 ml-auto size-3.5"
                                />
                            </p>
                            <p class="text-lg font-semibold">
                                {{ e.colaboradores_activos }}
                            </p>
                        </div>
                        <div v-else class="bg-muted/40 rounded-lg px-3 py-2">
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Users class="size-3" />
                                Colaboradores activos
                                <AyudaTooltip
                                    texto="Número de colaboradores con estado activo. No incluye colaboradores inactivos ni dados de baja."
                                    etiqueta="Ayuda sobre colaboradores activos"
                                />
                            </p>
                            <p class="text-lg font-semibold">
                                {{ e.colaboradores_activos }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap gap-2 pt-1">
                        <Button
                            variant="outline"
                            size="sm"
                            @click.stop="verDetalle(e)"
                        >
                            <SquareArrowOutUpRight class="size-3.5" />
                            Ver detalles
                        </Button>
                        <Button
                            v-if="puedeEditar"
                            variant="ghost"
                            size="sm"
                            @click.stop="editarEmpresa(e)"
                        >
                            <Pencil class="size-3.5" />
                            Editar
                        </Button>
                    </div>
                </div>
            </div>
        </TooltipProvider>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Empresa</th>
                        <th class="px-3 py-2 font-medium">Razón social</th>
                        <th class="px-3 py-2 font-medium">
                            Sucursales activas
                        </th>
                        <th class="px-3 py-2 font-medium">
                            Colaboradores activos
                        </th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in empresas.data"
                        :key="e.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">
                            <p class="font-medium">{{ e.nombre_comercial }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ e.codigo }}
                            </p>
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ e.razon_social ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="puedeVerSucursales"
                                :href="hrefSucursalesDe(e)"
                                class="focus-visible:ring-ring rounded underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                :aria-label="`Ver sucursales activas de ${e.nombre_comercial} en el módulo Sucursales`"
                            >
                                {{ e.sucursales_activas }}
                            </Link>
                            <template v-else>{{
                                e.sucursales_activas
                            }}</template>
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="puedeVerColaboradores"
                                :href="hrefColaboradoresDe(e)"
                                class="focus-visible:ring-ring rounded underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                :aria-label="`Ver colaboradores activos de ${e.nombre_comercial} en el módulo Colaboradores`"
                            >
                                {{ e.colaboradores_activos }}
                            </Link>
                            <template v-else>{{
                                e.colaboradores_activos
                            }}</template>
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="e.activa ? 'success' : 'secondary'"
                            >
                                {{ e.activa ? 'Activa' : 'Eliminada' }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="verDetalle(e)"
                                    >Ver</Button
                                >
                                <Button
                                    v-if="puedeEditar"
                                    variant="ghost"
                                    size="sm"
                                    @click="editarEmpresa(e)"
                                    >Editar</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="empresas.links" :total="empresas.total" />

        <Dialog v-model:open="modalAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            empresaEnEdicion
                                ? 'Editar empresa'
                                : 'Nueva empresa'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            empresaEnEdicion
                                ? 'Actualiza los datos generales, de contacto y el logotipo de la empresa.'
                                : 'Registra una nueva organización en la plataforma.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormularioEmpresa
                    :key="claveFormulario"
                    :empresa="empresaEnEdicion"
                    :logo-url-actual="logoUrlEnEdicion"
                    @guardado="alGuardar"
                    @cancelar="modalAbierto = false"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
