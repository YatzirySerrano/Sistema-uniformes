<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Activo = {
    id: number;
    nombre: string;
    descripcion: string | null;
    categoria: string | null;
    codigo: string | null;
    tipo_activo_id: number | null;
    tipo_control: 'cantidad' | 'serializado';
    activo: boolean;
    imagen_url: string | null;
    tallas: number[];
};

const props = defineProps<{
    activo: Activo | null;
    tallas: { id: number; valor: string }[];
    tiposActivo: { id: number; nombre: string }[];
    tiposControl: { valor: string; etiqueta: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Activos', href: '/activos' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.activo;
const PESO_MAXIMO_MB = 4;

const form = useForm<{
    nombre: string;
    descripcion: string;
    categoria: string;
    codigo: string;
    tipo_activo_id: number | '';
    tipo_control: string;
    activo: boolean;
    tallas: number[];
    imagen: File | null;
    _method?: string;
}>({
    nombre: props.activo?.nombre ?? '',
    descripcion: props.activo?.descripcion ?? '',
    categoria: props.activo?.categoria ?? '',
    codigo: props.activo?.codigo ?? '',
    tipo_activo_id: props.activo?.tipo_activo_id ?? '',
    tipo_control: props.activo?.tipo_control ?? 'cantidad',
    activo: props.activo?.activo ?? true,
    tallas: props.activo?.tallas ?? [],
    imagen: null,
});

function enviar() {
    // Siempre POST (subida de imagen); en edición se usa method spoofing.
    if (esEdicion) {
        form.transform((d) => ({ ...d, _method: 'POST' })).post(
            `/activos/${props.activo!.id}`,
            { forceFormData: true },
        );
    } else {
        form.post('/activos', { forceFormData: true });
    }
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar activo' : 'Nuevo activo'" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar activo' : 'Nuevo activo'"
            descripcion="Los datos pertenecen a la empresa activa."
        />

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="enviar">
            <section class="space-y-4 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Información</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="nombre">Nombre</Label>
                        <Input id="nombre" v-model="form.nombre" required />
                        <InputError :message="form.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="codigo" class="flex items-center gap-1.5">
                            Código
                            <AyudaTooltip
                                texto="Identificador interno del activo dentro de la empresa. Si lo dejas vacío se genera automáticamente (ACT-0001)."
                                etiqueta="Ayuda sobre el código"
                            />
                        </Label>
                        <Input
                            id="codigo"
                            v-model="form.codigo"
                            class="uppercase"
                            placeholder="Se genera automáticamente"
                        />
                        <InputError :message="form.errors.codigo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="categoria">Categoría</Label>
                        <Input id="categoria" v-model="form.categoria" />
                        <InputError :message="form.errors.categoria" />
                    </div>
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
            </section>

            <section class="space-y-4 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Clasificación</h2>
                <div class="grid gap-1.5">
                    <Label
                        for="tipo_activo_id"
                        class="flex items-center gap-1.5"
                    >
                        Tipo de activo
                        <AyudaTooltip
                            texto="Categoría del activo (Uniforme / Prenda, Equipo de cómputo, Dispositivo móvil, Accesorio, Otro…). El administrador puede crear tipos nuevos."
                            etiqueta="Ayuda sobre el tipo de activo"
                        />
                    </Label>
                    <select
                        id="tipo_activo_id"
                        v-model="form.tipo_activo_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Sin tipo</option>
                        <option
                            v-for="t in tiposActivo"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.nombre }}
                        </option>
                    </select>
                    <InputError :message="form.errors.tipo_activo_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label class="flex items-center gap-1.5">
                        Tipo de control
                        <AyudaTooltip
                            texto="«Por cantidad»: se controla por existencias (uniformes, accesorios). «Serializado»: cada unidad se identifica por número de serie / IMEI (laptops, teléfonos)."
                            etiqueta="Ayuda sobre el tipo de control"
                        />
                    </Label>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="c in tiposControl"
                            :key="c.valor"
                            class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm"
                            :class="
                                form.tipo_control === c.valor
                                    ? 'border-primary bg-primary/10'
                                    : ''
                            "
                        >
                            <input
                                v-model="form.tipo_control"
                                type="radio"
                                :value="c.valor"
                                class="size-3.5"
                            />
                            {{ c.etiqueta }}
                        </label>
                    </div>
                    <InputError :message="form.errors.tipo_control" />
                </div>

                <div class="grid gap-1.5">
                    <Label class="flex items-center gap-1.5">
                        Variantes / tallas
                        <AyudaTooltip
                            texto="Opcional. Un uniforme usa tallas; una laptop no necesita. Marca las variantes que aplican a este activo."
                            etiqueta="Ayuda sobre variantes / tallas"
                        />
                    </Label>
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
                            No hay variantes / tallas.
                            <Link href="/tallas" class="text-primary underline"
                                >Crea variantes primero</Link
                            >.
                        </p>
                    </div>
                    <InputError :message="form.errors.tallas" />
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-4 lg:col-span-2">
                <h2 class="text-sm font-semibold">Imagen y estado</h2>
                <div class="grid gap-1.5">
                    <Label for="imagen">Imagen (opcional)</Label>
                    <input
                        id="imagen"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="text-sm"
                        @change="
                            form.imagen =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p class="text-muted-foreground text-xs">
                        Formatos aceptados: JPG, PNG o WebP. Peso máximo:
                        {{ PESO_MAXIMO_MB }} MB.
                    </p>
                    <img
                        v-if="activo?.imagen_url"
                        :src="activo.imagen_url"
                        class="mt-1 h-24 w-24 rounded-md object-cover"
                        alt=""
                    />
                    <InputError :message="form.errors.imagen" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.activo"
                        type="checkbox"
                        class="size-4 rounded border"
                    />
                    Activo disponible para operaciones
                </label>
            </section>

            <div class="flex items-center gap-3 lg:col-span-2">
                <Button type="submit" :disabled="form.processing">
                    {{ esEdicion ? 'Guardar cambios' : 'Crear activo' }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/activos">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
