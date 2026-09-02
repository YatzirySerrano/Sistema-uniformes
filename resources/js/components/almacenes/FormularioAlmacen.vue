<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Colaborador = {
    id: number;
    nombre_completo: string;
    numero_empleado: string;
};

type SucursalOpcion = {
    id: number;
    nombre: string;
    codigo: string;
    activa: boolean;
};

export type AlmacenEditable = {
    id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
    direccion: string | null;
    telefono: string | null;
    correo: string | null;
    responsable: Colaborador | null;
    sucursales_ids: number[];
};

const props = defineProps<{
    almacen: AlmacenEditable | null;
    sucursales: SucursalOpcion[];
}>();
const emit = defineEmits<{ (e: 'guardado'): void; (e: 'cancelar'): void }>();

const esEdicion = computed(() => props.almacen !== null);

const responsable = ref<Colaborador | null>(props.almacen?.responsable ?? null);

const form = useForm<{
    nombre: string;
    codigo: string;
    descripcion: string;
    direccion: string;
    telefono: string;
    correo: string;
    responsable_colaborador_id: number | null;
    sucursales: number[];
}>({
    nombre: props.almacen?.nombre ?? '',
    codigo: props.almacen?.codigo ?? '',
    descripcion: props.almacen?.descripcion ?? '',
    direccion: props.almacen?.direccion ?? '',
    telefono: props.almacen?.telefono ?? '',
    correo: props.almacen?.correo ?? '',
    responsable_colaborador_id: props.almacen?.responsable?.id ?? null,
    sucursales: [...(props.almacen?.sucursales_ids ?? [])],
});

const tocado = reactive<Record<string, boolean>>({});
function marcar(campo: string): void {
    tocado[campo] = true;
}

function filtrarTelefono(evento: Event): void {
    const objetivo = evento.target as HTMLInputElement;
    const limpio = objetivo.value.replace(/\D+/g, '').slice(0, 10);
    form.telefono = limpio;
    objetivo.value = limpio;
}

async function buscarColaboradores(termino: string): Promise<Colaborador[]> {
    const url = `/almacenes/colaboradores-buscar?q=${encodeURIComponent(termino)}`;
    const res = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!res.ok) return [];
    const data = (await res.json()) as { colaboradores: Colaborador[] };
    return data.colaboradores ?? [];
}

function alElegirResponsable(c: { id: number } | null): void {
    responsable.value = (c as Colaborador) ?? null;
    form.responsable_colaborador_id = c?.id ?? null;
}

// --- Sucursales abastecidas ---
const buscarSucursal = ref('');
const sucursalesFiltradas = computed(() => {
    const t = buscarSucursal.value.trim().toLowerCase();
    if (!t) return props.sucursales;
    return props.sucursales.filter(
        (s) =>
            s.nombre.toLowerCase().includes(t) ||
            s.codigo.toLowerCase().includes(t),
    );
});

function alternarSucursal(id: number): void {
    const i = form.sucursales.indexOf(id);
    if (i === -1) form.sucursales.push(id);
    else form.sucursales.splice(i, 1);
}

const erroresLocales = computed<Record<string, string>>(() => {
    const e: Record<string, string> = {};
    if (tocado.nombre && form.nombre.trim() === '') {
        e.nombre = 'El nombre del almacén es obligatorio.';
    }
    if (
        tocado.codigo &&
        form.codigo.trim() !== '' &&
        !/^[A-Za-z0-9_-]+$/.test(form.codigo.trim())
    ) {
        e.codigo = 'Sólo letras, números, guiones y guiones bajos.';
    }
    if (
        tocado.telefono &&
        form.telefono.trim() !== '' &&
        form.telefono.replace(/\D+/g, '').length !== 10
    ) {
        e.telefono = 'El teléfono debe contener 10 dígitos.';
    }
    if (
        tocado.correo &&
        form.correo.trim() !== '' &&
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.correo.trim())
    ) {
        e.correo = 'El correo no tiene un formato válido.';
    }
    return e;
});

function error(campo: string): string | undefined {
    return (
        (form.errors as Record<string, string>)[campo] ??
        erroresLocales.value[campo]
    );
}

const hayErroresLocales = computed(
    () => Object.keys(erroresLocales.value).length > 0,
);

function enviar(): void {
    tocado.nombre = true;
    if (form.nombre.trim() === '') return;

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('guardado'),
    };

    if (esEdicion.value) {
        form.put(`/almacenes/${props.almacen!.id}`, opciones);
    } else {
        form.post('/almacenes', opciones);
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="alm-nombre" class="flex items-center gap-1.5">
                    Nombre
                    <span class="text-destructive">*</span>
                    <AyudaTooltip
                        texto="Nombre con el que se identifica el almacén (Almacén Morelos, Almacén Centro, etc.)."
                        etiqueta="Ayuda sobre el nombre"
                    />
                </Label>
                <Input
                    id="alm-nombre"
                    v-model="form.nombre"
                    required
                    maxlength="255"
                    @blur="marcar('nombre')"
                />
                <InputError :message="error('nombre')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="alm-codigo" class="flex items-center gap-1.5">
                    Código
                    <AyudaTooltip
                        texto="Identificador interno del almacén dentro de la empresa. Si lo dejas vacío se genera automáticamente (ALM-0001)."
                        etiqueta="Ayuda sobre el código"
                    />
                </Label>
                <Input
                    id="alm-codigo"
                    v-model="form.codigo"
                    class="uppercase"
                    placeholder="Se genera automáticamente"
                    maxlength="60"
                    @blur="marcar('codigo')"
                />
                <InputError :message="error('codigo')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="alm-telefono" class="flex items-center gap-1.5">
                    Teléfono
                    <AyudaTooltip
                        texto="Teléfono de contacto del almacén a 10 dígitos. Se guardan sólo los números."
                        etiqueta="Ayuda sobre el teléfono"
                    />
                </Label>
                <Input
                    id="alm-telefono"
                    v-model="form.telefono"
                    inputmode="numeric"
                    maxlength="10"
                    placeholder="10 dígitos"
                    @input="filtrarTelefono"
                    @blur="marcar('telefono')"
                />
                <InputError :message="error('telefono')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="alm-correo">Correo</Label>
                <Input
                    id="alm-correo"
                    v-model="form.correo"
                    type="email"
                    maxlength="255"
                    @blur="marcar('correo')"
                />
                <InputError :message="error('correo')" />
            </div>

            <div class="grid gap-1.5">
                <Label for="alm-responsable" class="flex items-center gap-1.5">
                    Responsable del almacén
                    <AyudaTooltip
                        texto="Colaborador activo de la empresa responsable del almacén. Búscalo por nombre o número de empleado. Es opcional."
                        etiqueta="Ayuda sobre el responsable"
                    />
                </Label>
                <BuscadorAsync
                    id="alm-responsable"
                    :model-value="responsable"
                    :buscar="buscarColaboradores"
                    :etiqueta="(c) => (c as Colaborador).nombre_completo"
                    :descripcion="
                        (c) => `N.º ${(c as Colaborador).numero_empleado}`
                    "
                    placeholder="Sin responsable"
                    @update:model-value="alElegirResponsable"
                />
                <InputError :message="error('responsable_colaborador_id')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="alm-direccion">Dirección</Label>
                <Input
                    id="alm-direccion"
                    v-model="form.direccion"
                    autocomplete="street-address"
                    maxlength="255"
                />
                <InputError :message="error('direccion')" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="alm-descripcion">Descripción</Label>
                <textarea
                    id="alm-descripcion"
                    v-model="form.descripcion"
                    rows="2"
                    maxlength="1000"
                    class="border-input bg-background focus-visible:ring-ring min-h-[60px] rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                ></textarea>
                <InputError :message="error('descripcion')" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label class="flex items-center gap-1.5">
                Sucursales abastecidas
                <AyudaTooltip
                    texto="Sucursales que este almacén abastece. Un almacén puede abastecer varias sucursales y una sucursal puede recibir de varios almacenes."
                    etiqueta="Ayuda sobre sucursales abastecidas"
                />
                <Badge v-if="form.sucursales.length" variant="secondary">
                    {{ form.sucursales.length }} seleccionadas
                </Badge>
            </Label>

            <div class="relative">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
                />
                <Input
                    v-model="buscarSucursal"
                    class="h-8 pl-8"
                    placeholder="Buscar sucursal por nombre o código"
                    aria-label="Buscar sucursal"
                />
            </div>

            <div
                v-if="sucursales.length"
                class="max-h-44 overflow-y-auto rounded-md border"
            >
                <label
                    v-for="s in sucursalesFiltradas"
                    :key="s.id"
                    class="hover:bg-accent/60 flex cursor-pointer items-center gap-2 border-b px-3 py-2 text-sm last:border-b-0"
                >
                    <input
                        type="checkbox"
                        class="size-4 rounded border"
                        :checked="form.sucursales.includes(s.id)"
                        @change="alternarSucursal(s.id)"
                    />
                    <span class="min-w-0 flex-1 truncate">
                        {{ s.nombre }}
                        <span class="text-muted-foreground font-mono text-xs">
                            {{ s.codigo }}
                        </span>
                    </span>
                    <Badge
                        v-if="!s.activa"
                        variant="outline"
                        class="shrink-0 text-xs"
                    >
                        Inactiva
                    </Badge>
                </label>
                <p
                    v-if="!sucursalesFiltradas.length"
                    class="text-muted-foreground px-3 py-2 text-sm"
                >
                    Ninguna sucursal coincide con la búsqueda.
                </p>
            </div>
            <p v-else class="text-muted-foreground text-xs">
                Esta empresa aún no tiene sucursales registradas.
            </p>
            <InputError :message="error('sucursales')" />
        </div>

        <div class="flex items-center justify-end gap-2 pt-1">
            <Button
                type="button"
                variant="ghost"
                :disabled="form.processing"
                @click="emit('cancelar')"
            >
                Cancelar
            </Button>
            <Button
                type="submit"
                :disabled="form.processing || hayErroresLocales"
            >
                {{ esEdicion ? 'Guardar cambios' : 'Registrar almacén' }}
            </Button>
        </div>
    </form>
</template>
