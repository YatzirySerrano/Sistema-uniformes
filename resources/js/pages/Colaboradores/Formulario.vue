<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Colaborador = {
    id: number;
    numero_empleado: string;
    nombre_completo: string;
    sucursal_id: number;
    puesto: string | null;
    area: string | null;
    correo: string | null;
    activo: boolean;
};

const props = defineProps<{
    colaborador: Colaborador | null;
    sucursales: { id: number; nombre: string }[];
    sucursalPreseleccionadaId?: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.colaborador;

const form = useForm({
    numero_empleado: props.colaborador?.numero_empleado ?? '',
    nombre_completo: props.colaborador?.nombre_completo ?? '',
    sucursal_id:
        props.colaborador?.sucursal_id ??
        props.sucursalPreseleccionadaId ??
        props.sucursales[0]?.id ??
        '',
    puesto: props.colaborador?.puesto ?? '',
    area: props.colaborador?.area ?? '',
    correo: props.colaborador?.correo ?? '',
    activo: props.colaborador?.activo ?? true,
});

function enviar() {
    if (esEdicion) {
        form.put(`/colaboradores/${props.colaborador!.id}`);
    } else {
        form.post('/colaboradores');
    }
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar colaborador' : 'Nuevo colaborador'" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar colaborador' : 'Nuevo colaborador'"
            descripcion="Los datos pertenecen a la empresa activa."
        />

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="numero_empleado">Número de empleado</Label>
                    <Input
                        id="numero_empleado"
                        v-model="form.numero_empleado"
                        required
                    />
                    <InputError :message="form.errors.numero_empleado" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="sucursal_id">Sucursal</Label>
                    <select
                        id="sucursal_id"
                        v-model="form.sucursal_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        required
                    >
                        <option
                            v-for="s in sucursales"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.nombre }}
                        </option>
                    </select>
                    <InputError :message="form.errors.sucursal_id" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="nombre_completo">Nombre completo</Label>
                <Input
                    id="nombre_completo"
                    v-model="form.nombre_completo"
                    required
                />
                <InputError :message="form.errors.nombre_completo" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="puesto">Puesto</Label>
                    <Input id="puesto" v-model="form.puesto" />
                    <InputError :message="form.errors.puesto" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="area">Área / Departamento</Label>
                    <Input id="area" v-model="form.area" />
                    <InputError :message="form.errors.area" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="correo">Correo electrónico (opcional)</Label>
                <Input id="correo" v-model="form.correo" type="email" />
                <InputError :message="form.errors.correo" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.activo"
                    type="checkbox"
                    class="size-4 rounded border"
                />
                Colaborador activo
            </label>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{
                        esEdicion ? 'Guardar cambios' : 'Registrar colaborador'
                    }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/colaboradores">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
