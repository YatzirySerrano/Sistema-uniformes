<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
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
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Cambio = { campo: string; antes: string; ahora: string };

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
    return new Date(iso).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
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

function verDetalle(registro: Registro) {
    registroSeleccionado.value = registro;
    mostrarJson.value = false;
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

                <Button
                    variant="outline"
                    size="sm"
                    class="mt-1 w-full"
                    @click="verDetalle(r)"
                    >Ver detalle</Button
                >
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
                            <Button
                                variant="outline"
                                size="sm"
                                @click="verDetalle(r)"
                                >Ver detalle</Button
                            >
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

                <div v-if="registroSeleccionado" class="space-y-4 text-sm">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Módulo
                            </dt>
                            <dd>{{ registroSeleccionado.modulo }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Acción
                            </dt>
                            <dd>
                                {{
                                    etiquetaAccion(registroSeleccionado.accion)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Usuario
                            </dt>
                            <dd>
                                {{ registroSeleccionado.usuario ?? 'Sistema' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">Fecha</dt>
                            <dd>{{ fecha(registroSeleccionado.fecha) }}</dd>
                        </div>
                        <div v-if="registroSeleccionado.empresa">
                            <dt class="text-muted-foreground text-xs">
                                Empresa
                            </dt>
                            <dd>{{ registroSeleccionado.empresa }}</dd>
                        </div>
                        <div v-if="registroSeleccionado.sucursal">
                            <dt class="text-muted-foreground text-xs">
                                Sucursal
                            </dt>
                            <dd>{{ registroSeleccionado.sucursal }}</dd>
                        </div>
                        <div v-if="registroSeleccionado.entidad">
                            <dt class="text-muted-foreground text-xs">
                                Entidad
                            </dt>
                            <dd>{{ registroSeleccionado.entidad }}</dd>
                        </div>
                        <div v-if="registroSeleccionado.ip">
                            <dt class="text-muted-foreground text-xs">IP</dt>
                            <dd>{{ registroSeleccionado.ip }}</dd>
                        </div>
                    </dl>

                    <p class="text-sm">
                        {{ registroSeleccionado.descripcion }}
                    </p>
                    <p
                        v-if="registroSeleccionado.motivo"
                        class="text-muted-foreground text-xs italic"
                    >
                        Motivo: {{ registroSeleccionado.motivo }}
                    </p>

                    <div>
                        <h3 class="mb-1.5 text-xs font-medium">Cambios</h3>
                        <div
                            v-if="registroSeleccionado.cambios.length"
                            class="overflow-x-auto rounded-md border"
                        >
                            <table class="w-full min-w-[420px] text-sm">
                                <thead
                                    class="bg-muted/50 text-muted-foreground text-left text-xs"
                                >
                                    <tr>
                                        <th class="px-2.5 py-1.5 font-medium">
                                            Campo
                                        </th>
                                        <th class="px-2.5 py-1.5 font-medium">
                                            Antes
                                        </th>
                                        <th class="px-2.5 py-1.5 font-medium">
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
                                        <td class="px-2.5 py-1.5 font-medium">
                                            {{ c.campo }}
                                        </td>
                                        <td
                                            class="text-muted-foreground px-2.5 py-1.5"
                                        >
                                            {{ c.antes }}
                                        </td>
                                        <td class="px-2.5 py-1.5">
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

                    <div v-if="hayJsonTecnico">
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                            @click="mostrarJson = !mostrarJson"
                        >
                            <Code2 class="size-3.5" />
                            {{
                                mostrarJson
                                    ? 'Ocultar JSON técnico'
                                    : 'Ver JSON técnico (avanzado)'
                            }}
                        </button>
                        <div
                            v-if="mostrarJson"
                            class="mt-2 grid gap-2 sm:grid-cols-2"
                        >
                            <div>
                                <p class="text-muted-foreground mb-1 text-xs">
                                    Antes
                                </p>
                                <pre
                                    class="bg-muted max-h-48 overflow-auto rounded-md p-2 text-xs"
                                    >{{
                                        jsonLegible(
                                            registroSeleccionado.valores_anteriores,
                                        )
                                    }}</pre>
                            </div>
                            <div>
                                <p class="text-muted-foreground mb-1 text-xs">
                                    Ahora
                                </p>
                                <pre
                                    class="bg-muted max-h-48 overflow-auto rounded-md p-2 text-xs"
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
