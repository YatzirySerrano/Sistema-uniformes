<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

type Colaborador = {
    id: number;
    empresa_id: number;
    numero_empleado: string;
    nombre_completo: string;
    sucursal_id: number;
    puesto: string | null;
    area: string | null;
    area_id: number | null;
    correo: string | null;
    activo: boolean;
};

type Opcion = { id: number; nombre: string };
type Catalogo = { sucursales: Opcion[]; areas: Opcion[] };

const props = defineProps<{
    colaborador: Colaborador | null;
    empresasAutorizadas: EmpresaAutorizada[];
    catalogosPorEmpresa: Record<number, Catalogo>;
    sucursalPreseleccionadaId?: number | null;
    empresaPreseleccionadaId?: number | null;
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

const empresaId = ref<number | ''>(
    props.colaborador?.empresa_id ??
        props.empresaPreseleccionadaId ??
        (props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : ''),
);

function catalogoDe(id: number | ''): Catalogo {
    return (
        (id !== '' && props.catalogosPorEmpresa[id]) || {
            sucursales: [],
            areas: [],
        }
    );
}

const catalogo = computed(() => catalogoDe(empresaId.value));

const form = useForm<{
    empresa_id: number | null;
    numero_empleado: string;
    nombre_completo: string;
    sucursal_id: number | '';
    puesto: string;
    area_id: number | '';
    correo: string;
    activo: boolean;
}>({
    empresa_id: empresaId.value === '' ? null : empresaId.value,
    numero_empleado: props.colaborador?.numero_empleado ?? '',
    nombre_completo: props.colaborador?.nombre_completo ?? '',
    sucursal_id:
        props.colaborador?.sucursal_id ??
        props.sucursalPreseleccionadaId ??
        catalogoDe(empresaId.value).sucursales[0]?.id ??
        '',
    puesto: props.colaborador?.puesto ?? '',
    area_id: props.colaborador?.area_id ?? '',
    correo: props.colaborador?.correo ?? '',
    activo: props.colaborador?.activo ?? true,
});

// Al cambiar de empresa se recargan sucursales / áreas y se limpia la selección.
watch(empresaId, (id) => {
    form.empresa_id = id === '' ? null : id;
    form.sucursal_id = catalogoDe(id).sucursales[0]?.id ?? '';
    form.area_id = '';
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
            descripcion="El colaborador pertenece a una empresa / razón social y a una de sus sucursales."
        />

        <form class="space-y-5" @submit.prevent="enviar">
            <div v-if="!esEdicion" class="grid gap-1.5">
                <Label for="empresa_id">Empresa / razón social</Label>
                <select
                    id="empresa_id"
                    v-model="empresaId"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    required
                >
                    <option value="" disabled>Selecciona una empresa</option>
                    <option
                        v-for="e in empresasAutorizadas"
                        :key="e.id"
                        :value="e.id"
                    >
                        {{ e.nombre_comercial }}
                    </option>
                </select>
                <InputError :message="form.errors.empresa_id" />
            </div>

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
                        :disabled="empresaId === ''"
                        required
                    >
                        <option value="" disabled>
                            {{
                                empresaId === ''
                                    ? 'Elige una empresa primero'
                                    : 'Selecciona una sucursal'
                            }}
                        </option>
                        <option
                            v-for="s in catalogo.sucursales"
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
                    <Label for="area_id">Área / Departamento</Label>
                    <select
                        id="area_id"
                        v-model="form.area_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Sin área</option>
                        <option
                            v-for="a in catalogo.areas"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.nombre }}
                        </option>
                    </select>
                    <InputError :message="form.errors.area_id" />
                    <p
                        v-if="empresaId !== '' && !catalogo.areas.length"
                        class="text-muted-foreground text-xs"
                    >
                        Esta empresa no tiene áreas registradas.
                        <Link href="/areas" class="underline"
                            >Crear un área</Link
                        >.
                    </p>
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
