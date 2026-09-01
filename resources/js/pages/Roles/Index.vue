<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
const form = useForm<{ name: string; permisos: string[] }>({
    name: '',
    permisos: [],
});

function nuevo() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}
function editar(r: Rol) {
    editando.value = r;
    form.name = r.name;
    form.permisos = [...r.permisos];
    form.clearErrors();
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
function eliminar(r: Rol) {
    router.delete(`/roles/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Roles y permisos" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Roles y permisos"
            descripcion="Define roles con permisos granulares. Los roles base no se pueden eliminar."
        >
            <template #acciones>
                <Button v-if="permisos.crear" @click="nuevo">
                    <Plus class="size-4" /> Nuevo rol
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="grid gap-3 md:grid-cols-2">
            <div v-for="r in roles" :key="r.id" class="rounded-xl border p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="font-medium capitalize">{{ r.etiqueta }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ r.usuarios }} usuario(s) ·
                            {{ r.permisos.length }} permiso(s)
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge v-if="r.base" variant="secondary">Base</Badge>
                        <button
                            v-if="permisos.editar"
                            class="text-primary text-xs hover:underline"
                            @click="editar(r)"
                        >
                            Editar
                        </button>
                        <button
                            v-if="
                                permisos.editar && !r.base && r.usuarios === 0
                            "
                            class="text-destructive"
                            @click="eliminar(r)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <Dialog v-model:open="abierto">
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{
                        editando
                            ? `Editar rol: ${editando.etiqueta}`
                            : 'Nuevo rol'
                    }}</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="guardar">
                    <div class="grid gap-1.5">
                        <Label for="r-name">Nombre del rol</Label>
                        <Input
                            id="r-name"
                            v-model="form.name"
                            :disabled="editando?.base"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div
                        v-for="(grupo, clave) in gruposPermisos"
                        :key="clave"
                        class="rounded-lg border p-3"
                    >
                        <p class="mb-2 text-sm font-medium">
                            {{ grupo.etiqueta }}
                        </p>
                        <div class="grid gap-1.5 sm:grid-cols-2">
                            <label
                                v-for="(etiqueta, permiso) in grupo.permisos"
                                :key="permiso"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    v-model="form.permisos"
                                    type="checkbox"
                                    :value="permiso"
                                    class="size-3.5"
                                />
                                {{ etiqueta }}
                            </label>
                        </div>
                    </div>

                    <Button type="submit" :disabled="form.processing"
                        >Guardar rol</Button
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
