<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Prenda = {
    id: number;
    nombre: string;
    descripcion: string | null;
    categoria: string | null;
    codigo_interno: string | null;
    activa: boolean;
    imagen_url: string | null;
    tallas: number[];
};

const props = defineProps<{
    prenda: Prenda | null;
    tallas: { id: number; valor: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Prendas', href: '/prendas' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.prenda;

const form = useForm<{
    nombre: string;
    descripcion: string;
    categoria: string;
    codigo_interno: string;
    activa: boolean;
    tallas: number[];
    imagen: File | null;
    _method?: string;
}>({
    nombre: props.prenda?.nombre ?? '',
    descripcion: props.prenda?.descripcion ?? '',
    categoria: props.prenda?.categoria ?? '',
    codigo_interno: props.prenda?.codigo_interno ?? '',
    activa: props.prenda?.activa ?? true,
    tallas: props.prenda?.tallas ?? [],
    imagen: null,
});

function enviar() {
    // Siempre POST (subida de imagen); en edición se usa method spoofing.
    if (esEdicion) {
        form.transform((d) => ({ ...d, _method: 'POST' })).post(
            `/prendas/${props.prenda!.id}`,
            { forceFormData: true },
        );
    } else {
        form.post('/prendas', { forceFormData: true });
    }
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar prenda' : 'Nueva prenda'" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar prenda' : 'Nueva prenda'"
        />

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="nombre">Nombre</Label>
                    <Input id="nombre" v-model="form.nombre" required />
                    <InputError :message="form.errors.nombre" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="codigo_interno">Código interno</Label>
                    <Input id="codigo_interno" v-model="form.codigo_interno" />
                    <InputError :message="form.errors.codigo_interno" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="categoria">Categoría</Label>
                <Input id="categoria" v-model="form.categoria" />
                <InputError :message="form.errors.categoria" />
            </div>

            <div class="grid gap-1.5">
                <Label for="descripcion">Descripción</Label>
                <textarea
                    id="descripcion"
                    v-model="form.descripcion"
                    rows="3"
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
                <InputError :message="form.errors.descripcion" />
            </div>

            <div class="grid gap-1.5">
                <Label>Tallas disponibles</Label>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="t in tallas"
                        :key="t.id"
                        class="flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm"
                        :class="
                            form.tallas.includes(t.id)
                                ? 'border-primary bg-primary/10'
                                : ''
                        "
                    >
                        <input
                            v-model="form.tallas"
                            type="checkbox"
                            :value="t.id"
                            class="size-3.5"
                        />
                        {{ t.valor }}
                    </label>
                    <p
                        v-if="!tallas.length"
                        class="text-muted-foreground text-sm"
                    >
                        No hay tallas.
                        <Link href="/tallas" class="text-primary underline"
                            >Crea tallas primero</Link
                        >.
                    </p>
                </div>
                <InputError :message="form.errors.tallas" />
            </div>

            <div class="grid gap-1.5">
                <Label for="imagen">Imagen (opcional)</Label>
                <input
                    id="imagen"
                    type="file"
                    accept="image/*"
                    class="text-sm"
                    @change="
                        form.imagen =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <img
                    v-if="prenda?.imagen_url"
                    :src="prenda.imagen_url"
                    class="mt-1 h-24 w-24 rounded-md object-cover"
                    alt=""
                />
                <InputError :message="form.errors.imagen" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.activa"
                    type="checkbox"
                    class="size-4 rounded border"
                />
                Prenda activa
            </label>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ esEdicion ? 'Guardar cambios' : 'Crear prenda' }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/prendas">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
