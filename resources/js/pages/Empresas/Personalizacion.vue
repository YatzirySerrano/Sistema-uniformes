<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    empresa: {
        id: number;
        nombre_comercial: string;
        razon_social: string | null;
        telefono: string | null;
        correo: string | null;
        direccion: string | null;
        color_principal: string;
        color_secundario: string;
        color_acento: string;
        logo_url: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Personalización', href: '/personalizacion' }],
    },
});

const form = useForm<{
    nombre_comercial: string;
    razon_social: string;
    telefono: string;
    correo: string;
    direccion: string;
    color_principal: string;
    color_secundario: string;
    color_acento: string;
    logo: File | null;
    _method: string;
}>({
    nombre_comercial: props.empresa.nombre_comercial,
    razon_social: props.empresa.razon_social ?? '',
    telefono: props.empresa.telefono ?? '',
    correo: props.empresa.correo ?? '',
    direccion: props.empresa.direccion ?? '',
    color_principal: props.empresa.color_principal,
    color_secundario: props.empresa.color_secundario,
    color_acento: props.empresa.color_acento,
    logo: null,
    _method: 'POST',
});

function luminancia(hex: string) {
    const h = hex.replace('#', '');
    if (h.length !== 6) return 0;
    const r = parseInt(h.slice(0, 2), 16) / 255;
    const g = parseInt(h.slice(2, 4), 16) / 255;
    const b = parseInt(h.slice(4, 6), 16) / 255;
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const textoPrincipal = computed(() =>
    luminancia(form.color_principal) > 0.55 ? '#0f172a' : '#ffffff',
);

function enviar() {
    form.post('/personalizacion', { forceFormData: true });
}
</script>

<template>
    <Head title="Personalización de empresa" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Personalización de empresa"
            descripcion="Datos e identidad visual usados en la interfaz y en los comprobantes."
        />

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
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
                        <Label for="razon_social">Razón social</Label>
                        <Input id="razon_social" v-model="form.razon_social" />
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
                    <Label for="direccion">Dirección (documentos)</Label>
                    <Input id="direccion" v-model="form.direccion" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label>Color principal</Label>
                        <input
                            v-model="form.color_principal"
                            type="color"
                            class="h-9 w-full rounded-md border"
                        />
                        <InputError :message="form.errors.color_principal" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Color secundario</Label>
                        <input
                            v-model="form.color_secundario"
                            type="color"
                            class="h-9 w-full rounded-md border"
                        />
                        <InputError :message="form.errors.color_secundario" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Color de acento</Label>
                        <input
                            v-model="form.color_acento"
                            type="color"
                            class="h-9 w-full rounded-md border"
                        />
                        <InputError :message="form.errors.color_acento" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="logo">Logotipo (PNG, JPG o SVG)</Label>
                    <input
                        id="logo"
                        type="file"
                        accept="image/png,image/jpeg,image/svg+xml"
                        class="text-sm"
                        @change="
                            form.logo =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="form.errors.logo" />
                </div>

                <Button type="submit" :disabled="form.processing"
                    >Guardar personalización</Button
                >
            </form>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Vista previa</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        class="flex items-center gap-2 rounded-lg p-3"
                        :style="{
                            background: form.color_principal,
                            color: textoPrincipal,
                        }"
                    >
                        <img
                            v-if="empresa.logo_url"
                            :src="empresa.logo_url"
                            class="h-8 w-8 rounded bg-white/20 object-contain"
                            alt=""
                        />
                        <span class="font-medium">{{
                            form.nombre_comercial
                        }}</span>
                    </div>
                    <button
                        class="w-full rounded-md px-3 py-2 text-sm font-medium"
                        :style="{
                            background: form.color_principal,
                            color: textoPrincipal,
                        }"
                    >
                        Botón principal
                    </button>
                    <button
                        class="w-full rounded-md px-3 py-2 text-sm font-medium text-white"
                        :style="{ background: form.color_secundario }"
                    >
                        Botón secundario
                    </button>
                    <div
                        class="rounded-md border-l-4 p-3 text-sm"
                        :style="{ borderColor: form.color_acento }"
                    >
                        Tarjeta de ejemplo con acento.
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
