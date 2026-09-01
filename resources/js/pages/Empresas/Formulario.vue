<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Empresa = {
    id: number;
    codigo: string;
    nombre_comercial: string;
    razon_social: string | null;
    rfc: string | null;
    telefono: string | null;
    correo: string | null;
    direccion: string | null;
    activa: boolean;
};

const props = defineProps<{ empresa: Empresa | null }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Empresas', href: '/empresas' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.empresa;

const form = useForm({
    nombre_comercial: props.empresa?.nombre_comercial ?? '',
    razon_social: props.empresa?.razon_social ?? '',
    rfc: props.empresa?.rfc ?? '',
    codigo: props.empresa?.codigo ?? '',
    telefono: props.empresa?.telefono ?? '',
    correo: props.empresa?.correo ?? '',
    direccion: props.empresa?.direccion ?? '',
    activa: props.empresa?.activa ?? true,
});

function enviar() {
    if (esEdicion) form.put(`/empresas/${props.empresa!.id}`);
    else form.post('/empresas');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar empresa' : 'Nueva empresa'" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar empresa' : 'Nueva empresa'"
            descripcion="El branding (colores y logotipo) se configura en Personalización."
        />

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="nombre_comercial">Nombre comercial</Label>
                    <Input
                        id="nombre_comercial"
                        v-model="form.nombre_comercial"
                        required
                    />
                    <InputError :message="form.errors.nombre_comercial" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="codigo">Código (opcional)</Label>
                    <Input
                        id="codigo"
                        v-model="form.codigo"
                        placeholder="Se genera automáticamente"
                    />
                    <InputError :message="form.errors.codigo" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="razon_social">Razón social</Label>
                    <Input id="razon_social" v-model="form.razon_social" />
                    <InputError :message="form.errors.razon_social" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="rfc">RFC</Label>
                    <Input id="rfc" v-model="form.rfc" />
                    <InputError :message="form.errors.rfc" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="telefono">Teléfono</Label>
                    <Input id="telefono" v-model="form.telefono" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="correo">Correo</Label>
                    <Input id="correo" v-model="form.correo" type="email" />
                    <InputError :message="form.errors.correo" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="direccion">Dirección</Label>
                <Input id="direccion" v-model="form.direccion" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.activa" type="checkbox" class="size-4" />
                Empresa activa
            </label>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ esEdicion ? 'Guardar cambios' : 'Crear empresa' }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/empresas">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
