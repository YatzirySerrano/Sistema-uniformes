<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    usuario: {
        id: number;
        name: string;
        email: string;
        activo: boolean;
        roles: string[];
        empresas: number[];
        sucursales: number[];
    } | null;
    roles: { name: string; etiqueta: string }[];
    empresas: { id: number; nombre_comercial: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Usuarios', href: '/usuarios' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.usuario;

const form = useForm<{
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    activo: boolean;
    roles: string[];
    empresas: number[];
    sucursales: number[];
}>({
    name: props.usuario?.name ?? '',
    email: props.usuario?.email ?? '',
    password: '',
    password_confirmation: '',
    activo: props.usuario?.activo ?? true,
    roles: props.usuario?.roles ?? [],
    empresas: props.usuario?.empresas ?? [],
    sucursales: props.usuario?.sucursales ?? [],
});

function enviar() {
    if (esEdicion) form.put(`/usuarios/${props.usuario!.id}`);
    else form.post('/usuarios');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar usuario' : 'Nuevo usuario'" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar usuario' : 'Nuevo usuario'"
            :descripcion="
                esEdicion
                    ? 'Deja la contraseña en blanco para no cambiarla.'
                    : 'Se enviará un correo de verificación al crear la cuenta.'
            "
        />

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="name">Nombre</Label>
                    <Input id="name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="email">Correo</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                    />
                    <InputError :message="form.errors.email" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="password">Contraseña</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                    />
                    <InputError :message="form.errors.password" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="password_confirmation"
                        >Confirmar contraseña</Label
                    >
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                    />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label>Roles</Label>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="r in roles"
                        :key="r.name"
                        class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm capitalize"
                        :class="
                            form.roles.includes(r.name)
                                ? 'border-primary bg-primary/10'
                                : ''
                        "
                    >
                        <input
                            v-model="form.roles"
                            type="checkbox"
                            :value="r.name"
                            class="size-3.5"
                        />
                        {{ r.etiqueta }}
                    </label>
                </div>
                <InputError :message="form.errors.roles" />
            </div>

            <div class="grid gap-1.5">
                <Label>Empresas autorizadas</Label>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="e in empresas"
                        :key="e.id"
                        class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm"
                        :class="
                            form.empresas.includes(e.id)
                                ? 'border-primary bg-primary/10'
                                : ''
                        "
                    >
                        <input
                            v-model="form.empresas"
                            type="checkbox"
                            :value="e.id"
                            class="size-3.5"
                        />
                        {{ e.nombre_comercial }}
                    </label>
                </div>
                <p class="text-muted-foreground text-xs">
                    Sin sucursales asignadas, el usuario ve todas las de sus
                    empresas.
                </p>
                <InputError :message="form.errors.empresas" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.activo" type="checkbox" class="size-4" />
                Cuenta activa
            </label>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ esEdicion ? 'Guardar cambios' : 'Crear usuario' }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/usuarios">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
