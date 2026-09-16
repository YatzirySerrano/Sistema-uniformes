<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    ChevronDown,
    Code2,
    FileClock,
    LayoutGrid,
    MapPin,
    Package,
    Settings2,
    ShieldCheck,
    UserCog,
    Users,
    Warehouse,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BotonVer from '@/components/sistema/BotonVer.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import DatePicker from '@/components/sistema/DatePicker.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectorVista from '@/components/sistema/SelectorVista.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useVistaPreferida } from '@/composables/useVistaPreferida';
import { fechaHora } from '@/lib/fecha';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Cambio = { campo: string; antes: string; ahora: string };

type RenglonTraspaso = {
    control: 'cantidad' | 'individual';
    activo_origen: string;
    activo_destino: string;
    talla: string | null;
    cantidad: number;
    unidad_codigo: string | null;
};

type Registro = {
    id: number;
    fecha: string;
    usuario: string | null;
    modulo: string;
    accion: string;
    descripcion: string | null;
    entidad: string | null;
    empresa: string | null;
    sucursal: string | null;
    motivo: string | null;
    ip: string | null;
    cambios: Cambio[];
    valores_anteriores: Record<string, unknown> | null;
    valores_nuevos: Record<string, unknown> | null;
};

const props = defineProps<{
    registros: Paginado<Registro>;
    filtros: Record<string, string | number | undefined>;
    empresasAutorizadas: EmpresaAutorizada[];
    modulos: string[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Auditoría', href: '/auditoria' }] },
});

const f = ref({
    empresa_id: props.filtros.empresa_id ?? '',
    modulo: props.filtros.modulo ?? '',
    buscar: props.filtros.buscar ?? '',
    desde: String(props.filtros.desde ?? ''),
    hasta: String(props.filtros.hasta ?? ''),
});

const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === f.value.empresa_id) ?? null,
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

watch(empresaSeleccionada, (e) => {
    f.value.empresa_id = e?.id ?? '';
});

let t: ReturnType<typeof setTimeout>;
watch(
    f,
    () => {
        clearTimeout(t);
        t = setTimeout(() => {
            router.get(
                '/auditoria',
                { ...f.value },
                {
                    preserveState: true,
                    replace: true,
                    preserveScroll: true,
                },
            );
        }, 300);
    },
    { deep: true },
);

function limpiar() {
    f.value = { empresa_id: '', modulo: '', buscar: '', desde: '', hasta: '' };
    empresaSeleccionada.value = null;
}

function fecha(iso: string) {
    return fechaHora(iso, { dateStyle: 'medium', timeStyle: 'short' });
}

const ICONOS_MODULO: Record<string, unknown> = {
    empresas: Building2,
    sucursales: MapPin,
    areas: LayoutGrid,
    almacenes: Warehouse,
    activos: Package,
    colaboradores: Users,
    usuarios: UserCog,
    roles: ShieldCheck,
    inventario: Boxes,
    configuracion: Settings2,
};

function iconoModulo(modulo: string): unknown {
    return ICONOS_MODULO[modulo] ?? FileClock;
}

const ROJO =
    'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400';
const VERDE =
    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-400';
const AZUL =
    'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-400';
const GRIS = 'border-border bg-muted text-muted-foreground';

function claseAccion(accion: string): string {
    if (
        accion.includes('desactivar') ||
        accion.includes('eliminar') ||
        accion.includes('baja') ||
        accion.includes('incidencia')
    ) {
        return ROJO;
    }
    if (
        accion.includes('crear') ||
        accion.includes('activar') ||
        accion.includes('recuperacion')
    ) {
        return VERDE;
    }
    if (
        accion.includes('editar') ||
        accion.includes('actualizar') ||
        accion.includes('reordenar')
    ) {
        return AZUL;
    }
    return GRIS;
}

function etiquetaAccion(accion: string): string {
    return accion
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}

const detalleAbierto = ref(false);
const registroSeleccionado = ref<Registro | null>(null);
const mostrarJson = ref(false);
const mostrarRenglones = ref(false);

// Snapshot inmutable (columnas `*_snapshot` congeladas al momento del
// traspaso): lo que se ve aquí no cambia aunque el activo se renombre
// después. No es un dato en vivo ni forma parte del diff genérico de
// "Cambios" — tiene su propia sección (ver `esClaveOculta` en
// `DescripcionAuditoria`).
function renglonesDe(
    valoresNuevos: Record<string, unknown> | null,
): RenglonTraspaso[] {
    const r = valoresNuevos?.renglones;

    return Array.isArray(r) ? (r as RenglonTraspaso[]) : [];
}

const renglonesTraspaso = computed(() =>
    renglonesDe(registroSeleccionado.value?.valores_nuevos ?? null),
);

function verDetalle(registro: Registro) {
    registroSeleccionado.value = registro;
    mostrarJson.value = false;
    // 1-2 renglones: se muestran abiertos por conveniencia. 3+: colapsados
    // por defecto, sobre todo en móvil, para no alargar el diálogo.
    mostrarRenglones.value = renglonesDe(registro.valores_nuevos).length <= 2;
    detalleAbierto.value = true;
}

const vista = useVistaPreferida('auditoria');

const hayJsonTecnico = computed(
    () =>
        !!registroSeleccionado.value?.valores_anteriores ||
        !!registroSeleccionado.value?.valores_nuevos,
);

function jsonLegible(valor: Record<string, unknown> | null): string {
    return valor === null
        ? 'No se registraron datos en este momento.'
        : JSON.stringify(valor, null, 2);
}
</script>

<template>
    <Head title="Auditoría" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Bitácora de auditoría"
            descripcion="Registro append-only de acciones relevantes del sistema."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/auditoria/exportar"
                    :filtros="filtros"
                />
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap gap-2">
            <BuscadorAsync
                v-if="empresasAutorizadas.length > 1"
                v-model="empresaSeleccionada"
                :buscar="buscarEmpresas"
                :etiqueta="(e) => String(e.nombre_comercial)"
                placeholder="Todas las empresas"
                placeholder-busqueda="Buscar empresa…"
                class="w-56"
            />
            <div class="w-52">
                <SelectSimple
                    v-model="f.modulo"
                    :opciones="[
                        { valor: '', etiqueta: 'Todos los módulos' },
                        ...modulos.map((m) => ({ valor: m, etiqueta: m })),
                    ]"
                />
            </div>
            <Input
                v-model="f.buscar"
                placeholder="Buscar en descripción o usuario"
                class="max-w-xs"
            />
            <div class="w-40">
                <DatePicker v-model="f.desde" placeholder="Desde" />
            </div>
            <div class="w-40">
                <DatePicker v-model="f.hasta" placeholder="Hasta" />
            </div>
            <Button variant="outline" size="sm" @click="limpiar"
                >Limpiar filtros</Button
            >

            <SelectorVista v-model="vista" class="ml-auto" />
        </div>

        <EstadoVacio
            v-if="!registros.data.length"
            titulo="Sin registros"
            descripcion="No hay eventos de auditoría con estos filtros."
        />

        <div
            v-else-if="vista === 'cards'"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="r in registros.data"
                :key="r.id"
                class="flex flex-col gap-2 rounded-xl border p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium"
                        :class="claseAccion(r.accion)"
                    >
                        <component
                            :is="iconoModulo(r.modulo)"
                            class="size-3.5"
                        />
                        {{ r.modulo }} · {{ etiquetaAccion(r.accion) }}
                    </span>
                    <span
                        class="text-muted-foreground shrink-0 text-xs whitespace-nowrap"
                        >{{ fecha(r.fecha) }}</span
                    >
                </div>

                <p class="line-clamp-3 text-sm">
                    {{ r.descripcion ?? 'Sin descripción.' }}
                </p>

                <div class="text-muted-foreground mt-auto space-y-0.5 text-xs">
                    <p class="truncate">
                        <span class="font-medium">{{
                            r.usuario ?? 'Sistema'
                        }}</span>
                        <template v-if="r.empresa"> · {{ r.empresa }}</template>
                        <template v-if="r.sucursal">
                            · {{ r.sucursal }}</template
                        >
                    </p>
                    <p v-if="r.ip">IP: {{ r.ip }}</p>
                </div>

                <BotonVer class="mt-1 w-full" @click="verDetalle(r)" />
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Fecha</th>
                        <th class="px-3 py-2 font-medium">Usuario</th>
                        <th class="px-3 py-2 font-medium">Módulo / Acción</th>
                        <th class="px-3 py-2 font-medium">Descripción</th>
                        <th class="px-3 py-2 font-medium">IP</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in registros.data"
                        :key="r.id"
                        class="hover:bg-muted/40 border-t align-top transition-colors"
                    >
                        <td
                            class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                        >
                            {{ fecha(r.fecha) }}
                        </td>
                        <td class="px-3 py-2">{{ r.usuario ?? 'Sistema' }}</td>
                        <td class="px-3 py-2">
                            <span class="font-medium">{{ r.modulo }}</span>
                            <span class="text-muted-foreground"
                                >/{{ etiquetaAccion(r.accion) }}</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            {{ r.descripcion }}
                            <span
                                v-if="r.empresa"
                                class="text-muted-foreground block text-xs"
                                >{{ r.empresa }}</span
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ r.ip ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <BotonVer @click="verDetalle(r)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="registros.links" :total="registros.total" />

        <Dialog v-model:open="detalleAbierto">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Detalle del evento</DialogTitle>
                </DialogHeader>

                <div
                    v-if="registroSeleccionado"
                    class="min-w-0 space-y-4 text-sm"
                >
                    <dl
                        class="grid min-w-0 grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2"
                    >
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-xs">
                                Módulo
                            </dt>
                            <dd class="break-words">
                                {{ registroSeleccionado.modulo }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-xs">
                                Acción
                            </dt>
                            <dd class="break-words">
                                {{
                                    etiquetaAccion(registroSeleccionado.accion)
                                }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-xs">
                                Usuario
                            </dt>
                            <dd class="break-words">
                                {{ registroSeleccionado.usuario ?? 'Sistema' }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-xs">Fecha</dt>
                            <dd class="break-words">
                                {{ fecha(registroSeleccionado.fecha) }}
                            </dd>
                        </div>
                        <div
                            v-if="registroSeleccionado.empresa"
                            class="min-w-0"
                        >
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd class="break-words">
                                {{ registroSeleccionado.empresa }}
                            </dd>
                        </div>
                        <div
                            v-if="registroSeleccionado.sucursal"
                            class="min-w-0"
                        >
                            <dt class="text-muted-foreground text-xs">
                                Sucursal
                            </dt>
                            <dd class="break-words">
                                {{ registroSeleccionado.sucursal }}
                            </dd>
                        </div>
                        <div
                            v-if="registroSeleccionado.entidad"
                            class="min-w-0"
                        >
                            <dt class="text-muted-foreground text-xs">
                                Entidad
                            </dt>
                            <dd class="break-words">
                                {{ registroSeleccionado.entidad }}
                            </dd>
                        </div>
                        <div v-if="registroSeleccionado.ip" class="min-w-0">
                            <dt class="text-muted-foreground text-xs">IP</dt>
                            <dd class="break-words">
                                {{ registroSeleccionado.ip }}
                            </dd>
                        </div>
                    </dl>

                    <p class="min-w-0 text-sm break-words">
                        {{ registroSeleccionado.descripcion }}
                    </p>
                    <p
                        v-if="registroSeleccionado.motivo"
                        class="text-muted-foreground min-w-0 text-xs break-words italic"
                    >
                        Motivo: {{ registroSeleccionado.motivo }}
                    </p>

                    <div>
                        <h3 class="mb-1.5 text-xs font-medium">Cambios</h3>
                        <div
                            v-if="registroSeleccionado.cambios.length"
                            class="overflow-x-auto rounded-md border"
                        >
                            <table class="w-full table-fixed text-sm">
                                <thead
                                    class="bg-muted/50 text-muted-foreground text-left text-xs"
                                >
                                    <tr>
                                        <th
                                            class="w-[34%] px-2.5 py-1.5 font-medium"
                                        >
                                            Campo
                                        </th>
                                        <th
                                            class="w-[33%] px-2.5 py-1.5 font-medium"
                                        >
                                            Antes
                                        </th>
                                        <th
                                            class="w-[33%] px-2.5 py-1.5 font-medium"
                                        >
                                            Ahora
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(
                                            c, i
                                        ) in registroSeleccionado.cambios"
                                        :key="i"
                                        class="border-t"
                                    >
                                        <td
                                            class="px-2.5 py-1.5 align-top font-medium break-words"
                                        >
                                            {{ c.campo }}
                                        </td>
                                        <td
                                            class="text-muted-foreground px-2.5 py-1.5 align-top break-words"
                                        >
                                            {{ c.antes }}
                                        </td>
                                        <td
                                            class="px-2.5 py-1.5 align-top break-words"
                                        >
                                            {{ c.ahora }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-muted-foreground text-xs">
                            Sin cambios detallados registrados para este evento.
                        </p>
                    </div>

                    <div v-if="renglonesTraspaso.length">
                        <button
                            type="button"
                            class="mb-1.5 flex w-full items-center justify-between gap-2 text-left text-xs font-medium"
                            :aria-expanded="mostrarRenglones"
                            @click="mostrarRenglones = !mostrarRenglones"
                        >
                            <span
                                >Activos traspasados ({{
                                    renglonesTraspaso.length
                                }})</span
                            >
                            <ChevronDown
                                class="size-3.5 shrink-0 transition-transform"
                                :class="mostrarRenglones ? 'rotate-180' : ''"
                            />
                        </button>
                        <ul v-if="mostrarRenglones" class="grid gap-2">
                            <li
                                v-for="(r, i) in renglonesTraspaso"
                                :key="i"
                                class="min-w-0 rounded-md border p-2 text-sm"
                            >
                                <p class="min-w-0 font-medium break-words">
                                    {{ r.activo_origen }}
                                </p>
                                <p
                                    v-if="
                                        r.activo_destino &&
                                        r.activo_destino !== r.activo_origen
                                    "
                                    class="text-muted-foreground min-w-0 text-xs break-words"
                                >
                                    Registrado en destino como:
                                    {{ r.activo_destino }}
                                </p>
                                <div
                                    class="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs"
                                >
                                    <span v-if="r.talla"
                                        >Talla: {{ r.talla }}</span
                                    >
                                    <span v-if="r.unidad_codigo"
                                        >Unidad: {{ r.unidad_codigo }}</span
                                    >
                                    <span>Cantidad: {{ r.cantidad }}</span>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div v-if="hayJsonTecnico">
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground flex w-full items-start gap-1 text-left text-xs underline-offset-2 hover:underline"
                            @click="mostrarJson = !mostrarJson"
                        >
                            <Code2 class="mt-0.5 size-3.5 shrink-0" />
                            {{
                                mostrarJson
                                    ? 'Ocultar JSON técnico'
                                    : 'Ver JSON técnico (avanzado)'
                            }}
                        </button>
                        <div
                            v-if="mostrarJson"
                            class="mt-2 grid min-w-0 gap-2 sm:grid-cols-2"
                        >
                            <div class="min-w-0">
                                <p class="text-muted-foreground mb-1 text-xs">
                                    Antes
                                </p>
                                <pre
                                    class="bg-muted max-h-48 max-w-full overflow-auto rounded-md p-2 text-xs"
                                    >{{
                                        jsonLegible(
                                            registroSeleccionado.valores_anteriores,
                                        )
                                    }}</pre>
                            </div>
                            <div class="min-w-0">
                                <p class="text-muted-foreground mb-1 text-xs">
                                    Ahora
                                </p>
                                <pre
                                    class="bg-muted max-h-48 max-w-full overflow-auto rounded-md p-2 text-xs"
                                    >{{
                                        jsonLegible(
                                            registroSeleccionado.valores_nuevos,
                                        )
                                    }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
