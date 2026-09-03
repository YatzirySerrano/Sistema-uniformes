<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    Plus,
    Search,
    SquareArrowOutUpRight,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { EmpresaEditable } from '@/components/empresas/FormularioEmpresa.vue';
import FormularioEmpresa from '@/components/empresas/FormularioEmpresa.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
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
import type { Paginado } from '@/types/sistema';

type EmpresaTarjeta = EmpresaEditable & {
    sucursales_activas: number;
    colaboradores_activos: number;
    color_principal: string;
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

const filtrosEstado: { valor: '' | 'activas' | 'inactivas'; texto: string }[] =
    [
        { valor: '', texto: 'Todas' },
        { valor: 'activas', texto: 'Activas' },
        { valor: 'inactivas', texto: 'Inactivas' },
    ];

const claseSelect =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-2.5 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none';

// --- Modal de alta / edición ---
const modalAbierto = ref(false);
const empresaEnEdicion = ref<EmpresaEditable | null>(null);
// Fuerza el remontaje del formulario para reiniciar su estado en cada apertura.
const claveFormulario = ref(0);

function nuevaEmpresa(): void {
    empresaEnEdicion.value = null;
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
</script>

<template>
    <Head title="Empresas" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Empresas"
            descripcion="Organizaciones que tienes autorizadas en la plataforma."
        >
            <template #acciones>
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
                    <select
                        v-model="sucursales"
                        :class="claseSelect"
                        aria-label="Filtrar por sucursales activas"
                    >
                        <option value="">Todas</option>
                        <option value="con">Con sucursales</option>
                        <option value="sin">Sin sucursales</option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Colaboradores</span>
                    <select
                        v-model="colaboradores"
                        :class="claseSelect"
                        aria-label="Filtrar por colaboradores activos"
                    >
                        <option value="">Todas</option>
                        <option value="con">Con colaboradores</option>
                        <option value="sin">Sin colaboradores</option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Orden</span>
                    <select
                        v-model="orden"
                        :class="claseSelect"
                        aria-label="Ordenar empresas"
                    >
                        <option value="az">Nombre A–Z</option>
                        <option value="za">Nombre Z–A</option>
                    </select>
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

        <TooltipProvider v-else :delay-duration="150">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="e in empresas.data"
                    :key="e.id"
                    role="button"
                    tabindex="0"
                    :aria-label="`Ver detalles de ${e.nombre_comercial}`"
                    class="group focus-visible:ring-ring hover:border-primary/40 flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="verDetalle(e)"
                    @keydown.enter="verDetalle(e)"
                    @keydown.space.prevent="verDetalle(e)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border"
                                :style="{ background: e.color_principal }"
                            >
                                <img
                                    v-if="e.logo_url"
                                    :src="e.logo_url"
                                    :alt="`Logotipo de ${e.nombre_comercial}`"
                                    class="size-full object-contain"
                                />
                                <Building2
                                    v-else
                                    class="size-4 text-white mix-blend-difference"
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
                                        e.activa ? 'default' : 'secondary'
                                    "
                                >
                                    {{ e.activa ? 'Activa' : 'Inactiva' }}
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
                        <div class="bg-muted/40 rounded-lg px-3 py-2">
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
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
                        <div class="bg-muted/40 rounded-lg px-3 py-2">
                            <p
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
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
                                ? 'Actualiza los datos generales y de contacto de la empresa.'
                                : 'Registra una nueva organización en la plataforma. El logotipo y los colores se configuran después en Personalización.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <FormularioEmpresa
                    :key="claveFormulario"
                    :empresa="empresaEnEdicion"
                    @guardado="alGuardar"
                    @cancelar="modalAbierto = false"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
