<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Search, ShieldAlert, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Rol = {
    id: number;
    name: string;
    etiqueta: string;
    base: boolean;
    usuarios: number;
    permisos: string[];
};
type Grupo = {
    etiqueta: string;
    permisos: Record<string, string>;
};

const props = defineProps<{
    roles: Rol[];
    gruposPermisos: Record<string, Grupo>;
    permisos: { crear: boolean; editar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles y permisos', href: '/roles' }],
    },
});

const abierto = ref(false);
const editando = ref<Rol | null>(null);
const busquedaPermiso = ref('');
const form = useForm<{ name: string; permisos: string[] }>({
    name: '',
    permisos: [],
});

const gruposFiltrados = computed(() => {
    const termino = busquedaPermiso.value.trim().toLowerCase();

    if (!termino) {
        return props.gruposPermisos;
    }

    const resultado: Record<string, Grupo> = {};

    for (const [clave, grupo] of Object.entries(props.gruposPermisos)) {
        const permisosCoincidentes = Object.fromEntries(
            Object.entries(grupo.permisos).filter(
                ([clavePermiso, etiqueta]) =>
                    etiqueta.toLowerCase().includes(termino) ||
                    clavePermiso.toLowerCase().includes(termino) ||
                    grupo.etiqueta.toLowerCase().includes(termino),
            ),
        );

        if (Object.keys(permisosCoincidentes).length) {
            resultado[clave] = {
                etiqueta: grupo.etiqueta,
                permisos: permisosCoincidentes,
            };
        }
    }

    return resultado;
});

function estaSeleccionado(permiso: string): boolean {
    return form.permisos.includes(permiso);
}

function alternarPermiso(permiso: string, marcado: boolean): void {
    if (marcado) {
        if (!form.permisos.includes(permiso)) {
            form.permisos.push(permiso);
        }
    } else {
        form.permisos = form.permisos.filter((p) => p !== permiso);
    }
}

function permisosDelGrupo(grupo: Grupo): string[] {
    return Object.keys(grupo.permisos);
}

function grupoCompleto(grupo: Grupo): boolean {
    return permisosDelGrupo(grupo).every((p) => estaSeleccionado(p));
}

function seleccionarTodoGrupo(grupo: Grupo): void {
    const claves = permisosDelGrupo(grupo);
    const nuevos = new Set(form.permisos);
    claves.forEach((p) => nuevos.add(p));
    form.permisos = Array.from(nuevos);
}

function limpiarGrupo(grupo: Grupo): void {
    const claves = new Set(permisosDelGrupo(grupo));
    form.permisos = form.permisos.filter((p) => !claves.has(p));
}

function nuevo() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    busquedaPermiso.value = '';
    abierto.value = true;
}

function editar(r: Rol) {
    editando.value = r;
    form.name = r.name;
    form.permisos = [...r.permisos];
    form.clearErrors();
    busquedaPermiso.value = '';
    abierto.value = true;
}

function guardar() {
    if (editando.value) {
        form.put(`/roles/${editando.value.id}`, {
            onSuccess: () => (abierto.value = false),
        });
    } else {
        form.post('/roles', { onSuccess: () => (abierto.value = false) });
    }
}

const rolAEliminar = ref<Rol | null>(null);
const eliminando = ref(false);

function pedirEliminar(r: Rol) {
    rolAEliminar.value = r;
}

function confirmarEliminar() {
    if (!rolAEliminar.value) {
        return;
    }

    eliminando.value = true;
    router.delete(`/roles/${rolAEliminar.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            eliminando.value = false;
            rolAEliminar.value = null;
        },
    });
}
</script>

<template>
    <Head title="Roles y permisos" />

    <TooltipProvider :delay-duration="150">
        <div class="flex flex-col gap-4 p-4">
            <EncabezadoPagina
                titulo="Roles y permisos"
                descripcion="Define roles con permisos granulares por módulo. Los roles base del sistema no se pueden eliminar y algunos no permiten cambiar su nombre."
            >
                <template #acciones>
                    <Button v-if="permisos.crear" @click="nuevo">
                        <Plus class="size-4" /> Nuevo rol
                    </Button>
                </template>
            </EncabezadoPagina>

            <EstadoVacio
                v-if="!roles.length"
                titulo="No hay roles configurados"
                descripcion="Crea un rol para empezar a asignar permisos a tu equipo."
            >
                <template #acciones>
                    <Button v-if="permisos.crear" size="sm" @click="nuevo">
                        Crear rol
                    </Button>
                </template>
            </EstadoVacio>

            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="r in roles"
                    :key="r.id"
                    class="hover:border-primary/30 hover:bg-muted/20 flex flex-col gap-3 rounded-xl border p-4 transition-colors"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium capitalize">
                                {{ r.etiqueta }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ r.usuarios }}
                                {{ r.usuarios === 1 ? 'usuario' : 'usuarios' }}
                                · {{ r.permisos.length }}
                                {{
                                    r.permisos.length === 1
                                        ? 'permiso'
                                        : 'permisos'
                                }}
                            </p>
                        </div>
                        <Tooltip v-if="r.base">
                            <TooltipTrigger as-child>
                                <Badge variant="secondary">Base</Badge>
                            </TooltipTrigger>
                            <TooltipContent>
                                Rol del sistema: no puede eliminarse ni
                                renombrarse, pero sus permisos sí pueden
                                ajustarse.
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <div class="mt-auto flex items-center gap-1 pt-1">
                        <Button
                            v-if="permisos.editar"
                            variant="outline"
                            size="sm"
                            @click="editar(r)"
                        >
                            Editar permisos
                        </Button>
                        <Tooltip
                            v-if="
                                permisos.editar && !r.base && r.usuarios === 0
                            "
                        >
                            <TooltipTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Eliminar rol"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive ml-auto"
                                    @click="pedirEliminar(r)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Eliminar rol</TooltipContent>
                        </Tooltip>
                        <Tooltip v-else-if="permisos.editar && r.usuarios > 0">
                            <TooltipTrigger as-child>
                                <span
                                    class="text-muted-foreground ml-auto inline-flex size-8 items-center justify-center"
                                >
                                    <ShieldAlert class="size-4" />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>
                                No se puede eliminar: tiene usuarios asignados.
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </div>
            </div>

            <!-- Editor de permisos -->
            <Dialog v-model:open="abierto">
                <DialogContent
                    class="flex max-h-[90dvh] w-[calc(100vw-2rem)] flex-col overflow-hidden sm:max-w-2xl lg:max-w-3xl"
                >
                    <DialogHeader>
                        <DialogTitle>{{
                            editando
                                ? `Editar rol: ${editando.etiqueta}`
                                : 'Nuevo rol'
                        }}</DialogTitle>
                        <DialogDescription>
                            Agrupa los permisos por módulo. Usa "Todo" /
                            "Ninguno" para acelerar la selección o busca un
                            permiso específico.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        id="form-rol"
                        class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto pr-1"
                        @submit.prevent="guardar"
                    >
                        <div class="grid gap-1.5">
                            <Label for="r-name">Nombre del rol</Label>
                            <Input
                                id="r-name"
                                v-model="form.name"
                                :disabled="editando?.base"
                                required
                            />
                            <p
                                v-if="editando?.base"
                                class="text-muted-foreground text-xs"
                            >
                                Los roles base del sistema no pueden
                                renombrarse.
                            </p>
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="relative">
                            <Search
                                class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                            />
                            <Input
                                v-model="busquedaPermiso"
                                placeholder="Buscar permiso…"
                                class="pl-8"
                                aria-label="Buscar permiso"
                            />
                        </div>

                        <p class="text-muted-foreground text-sm">
                            {{ form.permisos.length }} permiso(s)
                            seleccionado(s)
                        </p>

                        <div class="flex flex-col gap-3">
                            <div
                                v-for="(grupo, clave) in gruposFiltrados"
                                :key="clave"
                                class="rounded-lg border p-3"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between gap-2"
                                >
                                    <p class="text-sm font-medium">
                                        {{ grupo.etiqueta }}
                                    </p>
                                    <div class="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                            :disabled="grupoCompleto(grupo)"
                                            @click="seleccionarTodoGrupo(grupo)"
                                        >
                                            Todo
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                            :disabled="
                                                !permisosDelGrupo(grupo).some(
                                                    (p) => estaSeleccionado(p),
                                                )
                                            "
                                            @click="limpiarGrupo(grupo)"
                                        >
                                            Ninguno
                                        </Button>
                                    </div>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label
                                        v-for="(
                                            etiqueta, permiso
                                        ) in grupo.permisos"
                                        :key="permiso"
                                        class="flex cursor-pointer items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            :model-value="
                                                estaSeleccionado(permiso)
                                            "
                                            @update:model-value="
                                                (v) =>
                                                    alternarPermiso(
                                                        permiso,
                                                        v === true,
                                                    )
                                            "
                                        />
                                        {{ etiqueta }}
                                    </label>
                                </div>
                            </div>

                            <p
                                v-if="!Object.keys(gruposFiltrados).length"
                                class="text-muted-foreground py-6 text-center text-sm"
                            >
                                Ningún permiso coincide con "{{
                                    busquedaPermiso
                                }}".
                            </p>
                        </div>
                    </form>

                    <DialogFooter class="border-t pt-4">
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="form.processing"
                            @click="abierto = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            form="form-rol"
                            type="submit"
                            :disabled="form.processing"
                        >
                            Guardar rol
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Confirmación de eliminación -->
            <Dialog
                :open="rolAEliminar !== null"
                @update:open="(v) => (v ? null : (rolAEliminar = null))"
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >¿Eliminar el rol
                            {{ rolAEliminar?.etiqueta }}?</DialogTitle
                        >
                        <DialogDescription>
                            Esta acción no se puede deshacer. El rol dejará de
                            existir y sus permisos asociados se perderán.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            :disabled="eliminando"
                            @click="rolAEliminar = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="destructive"
                            :disabled="eliminando"
                            @click="confirmarEliminar"
                        >
                            Eliminar rol
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </TooltipProvider>
</template>
