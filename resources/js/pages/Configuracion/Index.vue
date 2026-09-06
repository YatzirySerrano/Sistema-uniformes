<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Configuracion = {
    color_principal: string;
    color_hover_principal: string;
    color_texto_boton_principal: string;
    fondo_general: string;
    fondo_tarjetas: string;
    fondo_sidebar: string;
    color_secundario: string | null;
};

const props = defineProps<{
    configuracion: Configuracion;
    valoresPorDefecto: Configuracion;
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuración', href: '/configuracion' }],
    },
});

const form = useForm<Configuracion>({ ...props.configuracion });

const usaColorSecundario = computed({
    get: () => form.color_secundario !== null,
    set: (valor: boolean) => {
        form.color_secundario = valor
            ? (props.valoresPorDefecto.color_secundario ?? '#6366f1')
            : null;
    },
});

type Campo = {
    clave: keyof Omit<Configuracion, 'color_secundario'>;
    etiqueta: string;
    ayuda: string;
};

const campos: Campo[] = [
    {
        clave: 'color_principal',
        etiqueta: 'Color principal',
        ayuda: 'Botones principales, elemento activo del menú y acentos de la aplicación.',
    },
    {
        clave: 'color_hover_principal',
        etiqueta: 'Hover principal',
        ayuda: 'Color al pasar el cursor sobre un botón o elemento principal.',
    },
    {
        clave: 'color_texto_boton_principal',
        etiqueta: 'Texto sobre botón principal',
        ayuda: 'Debe contrastar bien con el color principal para seguir siendo legible.',
    },
    {
        clave: 'fondo_general',
        etiqueta: 'Fondo general',
        ayuda: 'Fondo de la aplicación en modo claro.',
    },
    {
        clave: 'fondo_tarjetas',
        etiqueta: 'Fondo de tarjetas',
        ayuda: 'Fondo de cards, diálogos y menús desplegables en modo claro.',
    },
    {
        clave: 'fondo_sidebar',
        etiqueta: 'Fondo del sidebar',
        ayuda: 'Fondo del menú lateral en modo claro.',
    },
];

function restaurarValoresPorDefecto() {
    form.color_principal = props.valoresPorDefecto.color_principal;
    form.color_hover_principal = props.valoresPorDefecto.color_hover_principal;
    form.color_texto_boton_principal =
        props.valoresPorDefecto.color_texto_boton_principal;
    form.fondo_general = props.valoresPorDefecto.fondo_general;
    form.fondo_tarjetas = props.valoresPorDefecto.fondo_tarjetas;
    form.fondo_sidebar = props.valoresPorDefecto.fondo_sidebar;
    form.color_secundario = null;
}

function guardar() {
    form.post('/configuracion', { preserveScroll: true });
}
</script>

<template>
    <Head title="Configuración" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Personalización del sistema"
            descripcion="Colores globales de la aplicación. Se aplican de inmediato para todos los usuarios y todas las empresas — no existe personalización por empresa."
        />

        <form
            class="bg-card flex flex-col gap-6 rounded-xl border p-6 shadow-sm"
            @submit.prevent="guardar"
        >
            <div>
                <h2 class="text-base font-semibold">Colores del sistema</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Cada color tiene una muestra visual y su valor hexadecimal
                    (#RRGGBB).
                </p>
            </div>

            <fieldset :disabled="!puedeEditar" class="flex flex-col gap-5">
                <div
                    v-for="campo in campos"
                    :key="campo.clave"
                    class="flex flex-col gap-1.5"
                >
                    <Label :for="campo.clave">{{ campo.etiqueta }}</Label>
                    <div class="flex items-center gap-3">
                        <input
                            :id="campo.clave"
                            v-model="form[campo.clave]"
                            type="color"
                            class="size-9 shrink-0 cursor-pointer rounded-md border p-0.5"
                            :aria-label="`Selector visual de ${campo.etiqueta.toLowerCase()}`"
                        />
                        <Input
                            v-model="form[campo.clave]"
                            class="max-w-40 font-mono uppercase"
                            maxlength="7"
                            :aria-invalid="
                                !!form.errors[campo.clave] || undefined
                            "
                        />
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{ campo.ayuda }}
                    </p>
                    <InputError :message="form.errors[campo.clave]" />
                </div>

                <div class="flex flex-col gap-1.5 border-t pt-5">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input
                            v-model="usaColorSecundario"
                            type="checkbox"
                            class="size-4 rounded border"
                        />
                        Usar color secundario / acento
                    </label>
                    <p class="text-muted-foreground text-xs">
                        Opcional: sólo actívalo si aporta coherencia visual
                        adicional (por ejemplo, para distinguir un elemento
                        destacado).
                    </p>
                    <div
                        v-if="usaColorSecundario && form.color_secundario"
                        class="mt-2 flex items-center gap-3"
                    >
                        <input
                            v-model="form.color_secundario"
                            type="color"
                            class="size-9 shrink-0 cursor-pointer rounded-md border p-0.5"
                            aria-label="Selector visual de color secundario"
                        />
                        <Input
                            v-model="form.color_secundario"
                            class="max-w-40 font-mono uppercase"
                            maxlength="7"
                            :aria-invalid="
                                !!form.errors.color_secundario || undefined
                            "
                        />
                    </div>
                    <InputError :message="form.errors.color_secundario" />
                </div>
            </fieldset>

            <div v-if="puedeEditar" class="flex items-center gap-2 pt-2">
                <Button type="submit" :disabled="form.processing">
                    Guardar personalización
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="restaurarValoresPorDefecto"
                >
                    <RotateCcw class="size-4" /> Restaurar valores por defecto
                </Button>
            </div>
            <p v-else class="text-muted-foreground text-sm">
                No tienes permiso para editar la personalización visual del
                sistema.
            </p>
        </form>

        <!-- Vista previa -->
        <div class="rounded-xl border p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">Vista previa</h2>
            <div class="grid gap-4 sm:grid-cols-[200px_1fr]">
                <div
                    class="flex flex-col gap-1 rounded-lg border p-3"
                    :style="{ background: form.fondo_sidebar }"
                >
                    <span
                        class="rounded-lg px-3 py-2 text-sm font-medium"
                        :style="{
                            background: form.color_principal,
                            color: form.color_texto_boton_principal,
                        }"
                        >Elemento seleccionado</span
                    >
                    <span class="text-foreground rounded-lg px-3 py-2 text-sm"
                        >Elemento del menú</span
                    >
                </div>
                <div
                    class="flex flex-col gap-3 rounded-lg border p-4"
                    :style="{ background: form.fondo_general }"
                >
                    <div
                        class="rounded-lg border p-4 shadow-sm"
                        :style="{ background: form.fondo_tarjetas }"
                    >
                        <p class="text-foreground text-sm font-semibold">
                            Tarjeta de ejemplo
                        </p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Así se ve un texto normal sobre una tarjeta.
                        </p>
                        <div class="mt-3 flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-md px-4 py-2 text-sm font-medium"
                                :style="{
                                    background: form.color_principal,
                                    color: form.color_texto_boton_principal,
                                }"
                            >
                                Botón principal
                            </button>
                            <span
                                v-if="
                                    usaColorSecundario && form.color_secundario
                                "
                                class="rounded-md px-3 py-1 text-xs font-medium"
                                :style="{
                                    background: form.color_secundario,
                                    color: '#ffffff',
                                }"
                                >Acento</span
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
