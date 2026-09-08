<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BotonesExportar from '@/components/sistema/BotonesExportar.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import SelectSimple from '@/components/sistema/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { EmpresaAutorizada, Paginado } from '@/types/sistema';

type Usuario = {
    id: number;
    name: string;
    email: string;
    activo: boolean;
    verificado: boolean;
    roles: string[];
    empresas: string[];
    ultimo_acceso_en: string | null;
    puedeCambiarEstado: boolean;
};
type RolOpcion = { name: string; etiqueta: string };
type EstadoFiltro = '' | 'activos' | 'eliminados';

const props = defineProps<{
    usuarios: Paginado<Usuario>;
    puedeCrear: boolean;
    puedeVerEliminados: boolean;
    empresasAutorizadas: EmpresaAutorizada[];
    rolesDisponibles: RolOpcion[];
    filtros: {
        buscar: string;
        rol: string;
        estado: EstadoFiltro;
        empresa_id: number | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Usuarios', href: '/usuarios' }] },
});

const buscar = ref(props.filtros.buscar);
const rol = ref(props.filtros.rol);
const estado = ref<EstadoFiltro>(props.filtros.estado);
const empresaSeleccionada = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === props.filtros.empresa_id) ??
        null,
);
const empresaId = computed(() => empresaSeleccionada.value?.id ?? '');

watch(
    () => props.filtros.empresa_id,
    (nuevoId) => {
        if (nuevoId !== (empresaSeleccionada.value?.id ?? null)) {
            empresaSeleccionada.value =
                props.empresasAutorizadas.find((e) => e.id === nuevoId) ?? null;
        }
    },
);

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const opcionesRol = computed(() => [
    { valor: '', etiqueta: 'Todos los roles' },
    ...props.rolesDisponibles.map((r) => ({
        valor: r.name,
        etiqueta: r.etiqueta,
    })),
]);

const opcionesEstado = computed<{ valor: EstadoFiltro; texto: string }[]>(
    () => [
        { valor: '', texto: 'Todos' },
        { valor: 'activos', texto: 'Activos' },
        ...(props.puedeVerEliminados
            ? ([{ valor: 'eliminados', texto: 'Eliminados' }] as const)
            : []),
    ],
);

const hayFiltrosActivos = computed(
    () =>
        buscar.value !== '' ||
        rol.value !== '' ||
        estado.value !== '' ||
        empresaId.value !== '',
);

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch([buscar, rol, estado, empresaId], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/usuarios',
            {
                buscar: buscar.value || undefined,
                rol: rol.value || undefined,
                estado: estado.value || undefined,
                empresa_id: empresaId.value || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['usuarios', 'filtros'],
            },
        );
    }, 300);
});

function limpiarFiltros(): void {
    buscar.value = '';
    rol.value = '';
    estado.value = '';
    empresaSeleccionada.value = null;
}

const filtrosExport = computed(() => ({
    buscar: buscar.value || undefined,
    rol: rol.value || undefined,
    estado: estado.value || undefined,
    empresa_id: empresaId.value || undefined,
}));

const confirmando = ref<Usuario | null>(null);
const procesandoEstado = ref(false);

function toggle(u: Usuario) {
    if (u.activo) {
        confirmando.value = u; // eliminar => confirmación
    } else {
        // restaurar es seguro: sin confirmación
        router.post(`/usuarios/${u.id}/estado`, {}, { preserveScroll: true });
    }
}

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/usuarios/${confirmando.value.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                confirmando.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Usuarios"
            descripcion="Cuentas de acceso al sistema. El registro público está deshabilitado. Usa el buscador y los filtros para administrar grandes volúmenes de cuentas."
        >
            <template #acciones>
                <BotonesExportar
                    endpoint="/usuarios/exportar"
                    :filtros="filtrosExport"
                />
                <Button v-if="puedeCrear" as-child>
                    <Link href="/usuarios/crear"
                        ><Plus class="size-4" /> Nuevo usuario</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-col gap-3">
            <div class="relative w-full sm:w-[420px]">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="buscar"
                    class="pl-8"
                    placeholder="Buscar por nombre, correo o rol"
                    aria-label="Buscar por nombre, correo o rol"
                />
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label class="flex items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Rol</span>
                    <div class="w-52">
                        <SelectSimple v-model="rol" :opciones="opcionesRol" />
                    </div>
                </label>

                <div
                    class="flex gap-1"
                    role="group"
                    aria-label="Filtrar por estado"
                >
                    <Button
                        v-for="f in opcionesEstado"
                        :key="f.valor"
                        type="button"
                        size="sm"
                        :variant="estado === f.valor ? 'default' : 'outline'"
                        @click="estado = f.valor"
                    >
                        {{ f.texto }}
                    </Button>
                </div>

                <label
                    v-if="empresasAutorizadas.length > 1"
                    class="flex items-center gap-1.5 text-sm"
                >
                    <span class="text-muted-foreground">Empresa</span>
                    <BuscadorAsync
                        v-model="empresaSeleccionada"
                        :buscar="buscarEmpresas"
                        :etiqueta="(e) => String(e.nombre_comercial)"
                        placeholder="Todas"
                        placeholder-busqueda="Buscar empresa…"
                        class="w-56"
                    />
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

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Nombre</th>
                        <th class="px-3 py-2 font-medium">Correo</th>
                        <th class="px-3 py-2 font-medium">Roles</th>
                        <th class="px-3 py-2 font-medium">Empresas</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!usuarios.data.length" class="border-t">
                        <td
                            colspan="6"
                            class="text-muted-foreground px-3 py-8 text-center text-sm"
                        >
                            Ningún usuario coincide con la búsqueda o los
                            filtros aplicados.
                        </td>
                    </tr>
                    <tr
                        v-for="u in usuarios.data"
                        :key="u.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">{{ u.name }}</td>
                        <td class="px-3 py-2">
                            {{ u.email }}
                            <Badge
                                v-if="!u.verificado"
                                variant="secondary"
                                class="ml-1 text-amber-600"
                                >sin verificar</Badge
                            >
                        </td>
                        <td class="px-3 py-2">
                            <span
                                v-for="r in u.roles"
                                :key="r"
                                class="bg-muted mr-1 rounded px-1.5 py-0.5 text-[11px] capitalize"
                                >{{ r }}</span
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ u.empresas.join(', ') || '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="u.activo ? 'success' : 'secondary'"
                                >{{ u.activo ? 'Activo' : 'Eliminado' }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Link
                                :href="`/usuarios/${u.id}/editar`"
                                class="text-primary text-xs hover:underline"
                                >Editar</Link
                            >
                            <button
                                v-if="u.puedeCambiarEstado"
                                type="button"
                                class="text-primary ml-3 text-xs hover:underline"
                                @click="toggle(u)"
                            >
                                {{ u.activo ? 'Eliminar' : 'Restaurar' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="usuarios.links" :total="usuarios.total" />

        <Dialog
            :open="confirmando !== null"
            @update:open="
                (v) => {
                    if (!v) confirmando = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Eliminar el usuario
                        <span v-if="confirmando">{{ confirmando.name }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción le impedirá iniciar sesión. Su historial de
                        acciones no se modifica y podrás restaurar el acceso
                        cuando quieras.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEstado"
                        @click="confirmando = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoEstado"
                        @click="confirmarEstado"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
