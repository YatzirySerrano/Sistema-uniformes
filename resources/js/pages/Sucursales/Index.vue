<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
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
import type { Paginado } from '@/types/sistema';

type Sucursal = {
    id: number;
    codigo: string;
    nombre: string;
    direccion: string | null;
    telefono: string | null;
    activa: boolean;
    colaboradores: number;
};

defineProps<{
    sucursales: Paginado<Sucursal>;
    permisos: { crear: boolean; editar: boolean; desactivar: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sucursales', href: '/sucursales' }] },
});

const abierto = ref(false);
const editando = ref<Sucursal | null>(null);

const form = useForm({ nombre: '', codigo: '', direccion: '', telefono: '' });

function nueva() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}
function editar(s: Sucursal) {
    editando.value = s;
    form.nombre = s.nombre;
    form.codigo = s.codigo;
    form.direccion = s.direccion ?? '';
    form.telefono = s.telefono ?? '';
    form.clearErrors();
    abierto.value = true;
}
function guardar() {
    if (editando.value) {
        form.put(`/sucursales/${editando.value.id}`, {
            onSuccess: () => (abierto.value = false),
        });
    } else {
        form.post('/sucursales', {
            onSuccess: () => (abierto.value = false),
        });
    }
}
function toggle(s: Sucursal) {
    router.post(`/sucursales/${s.id}/estado`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Sucursales" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Sucursales"
            descripcion="Ubicaciones de la empresa activa."
        >
            <template #acciones>
                <Button v-if="permisos.crear" @click="nueva">
                    <Plus class="size-4" /> Nueva sucursal
                </Button>
            </template>
        </EncabezadoPagina>

        <EstadoVacio
            v-if="!sucursales.data.length"
            titulo="No hay sucursales"
            descripcion="Crea la primera sucursal de esta empresa."
        />

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Código</th>
                        <th class="px-3 py-2 font-medium">Nombre</th>
                        <th class="px-3 py-2 font-medium">Contacto</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Colaboradores
                        </th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in sucursales.data"
                        :key="s.id"
                        class="border-t"
                    >
                        <td class="px-3 py-2 font-mono">{{ s.codigo }}</td>
                        <td class="px-3 py-2">{{ s.nombre }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{
                                [s.direccion, s.telefono]
                                    .filter(Boolean)
                                    .join(' · ') || '—'
                            }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ s.colaboradores }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="s.activa ? 'default' : 'secondary'"
                                >{{ s.activa ? 'Activa' : 'Inactiva' }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button
                                v-if="permisos.editar"
                                class="text-primary text-xs hover:underline"
                                @click="editar(s)"
                            >
                                Editar
                            </button>
                            <button
                                v-if="permisos.desactivar"
                                class="text-primary ml-3 text-xs hover:underline"
                                @click="toggle(s)"
                            >
                                {{ s.activa ? 'Desactivar' : 'Activar' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="sucursales.links" :total="sucursales.total" />

        <Dialog v-model:open="abierto">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        editando ? 'Editar sucursal' : 'Nueva sucursal'
                    }}</DialogTitle>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="guardar">
                    <div class="grid gap-1.5">
                        <Label for="s-nombre">Nombre</Label>
                        <Input id="s-nombre" v-model="form.nombre" required />
                        <InputError :message="form.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="s-codigo">Código</Label>
                        <Input id="s-codigo" v-model="form.codigo" required />
                        <InputError :message="form.errors.codigo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="s-dir">Dirección</Label>
                        <Input id="s-dir" v-model="form.direccion" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="s-tel">Teléfono</Label>
                        <Input id="s-tel" v-model="form.telefono" />
                    </div>
                    <Button type="submit" :disabled="form.processing"
                        >Guardar</Button
                    >
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
