<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmpresaAutorizada } from '@/types/sistema';

type OpcionActivo = {
    id: number;
    nombre: string;
    codigo: string | null;
    tipo: string | null;
    categoria: string | null;
    usa_variantes: boolean;
    tallas: { id: number; valor: string }[];
};

type ComponenteEditable = {
    activo_id: number;
    activo_nombre: string | null;
    activo_codigo: string | null;
    cantidad_requerida: number;
    talla_id: number | null;
    talla_valor: string | null;
    talla_libre: boolean;
};

type Conjunto = {
    id: number;
    empresa_id: number;
    nombre: string;
    codigo: string | null;
    descripcion: string | null;
    activo: boolean;
    componentes: ComponenteEditable[];
};

const props = defineProps<{
    conjunto: Conjunto | null;
    empresasAutorizadas: EmpresaAutorizada[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Conjuntos', href: '/conjuntos' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.conjunto;

const empresaId = ref<number | ''>(
    props.conjunto?.empresa_id ??
        (props.empresasAutorizadas.length === 1
            ? props.empresasAutorizadas[0].id
            : ''),
);

type Fila = {
    activo_id: number | '';
    cantidad_requerida: number;
    talla_id: number | null;
    talla_libre: boolean;
};

const filasIniciales: Fila[] = (props.conjunto?.componentes ?? []).map((c) => ({
    activo_id: c.activo_id,
    cantidad_requerida: c.cantidad_requerida,
    talla_id: c.talla_id,
    talla_libre: c.talla_libre,
}));
if (filasIniciales.length === 0) {
    filasIniciales.push({
        activo_id: '',
        cantidad_requerida: 1,
        talla_id: null,
        talla_libre: false,
    });
}

const form = useForm<{
    empresa_id: number | null;
    nombre: string;
    codigo: string;
    descripcion: string;
    activo: boolean;
    componentes: Fila[];
}>({
    empresa_id: empresaId.value === '' ? null : empresaId.value,
    nombre: props.conjunto?.nombre ?? '',
    codigo: props.conjunto?.codigo ?? '',
    descripcion: props.conjunto?.descripcion ?? '',
    activo: props.conjunto?.activo ?? true,
    componentes: filasIniciales,
});

// Estado de UI paralelo por fila: el objeto activo seleccionado (para el
// combobox) y las variantes elegibles que trajo la búsqueda.
const filasUI = reactive<
    {
        activoSel: OpcionActivo | null;
        tallas: { id: number; valor: string }[];
    }[]
>(
    (props.conjunto?.componentes ?? filasIniciales).map((c) => {
        const conCodigo = c as ComponenteEditable;
        return {
            activoSel: conCodigo.activo_id
                ? {
                      id: conCodigo.activo_id,
                      nombre: conCodigo.activo_nombre ?? '',
                      codigo: conCodigo.activo_codigo ?? null,
                      tipo: null,
                      categoria: null,
                      usa_variantes: !!(
                          conCodigo.talla_id || conCodigo.talla_libre
                      ),
                      tallas: conCodigo.talla_id
                          ? [
                                {
                                    id: conCodigo.talla_id,
                                    valor: conCodigo.talla_valor ?? '',
                                },
                            ]
                          : [],
                  }
                : null,
            tallas: conCodigo.talla_id
                ? [
                      {
                          id: conCodigo.talla_id,
                          valor: conCodigo.talla_valor ?? '',
                      },
                  ]
                : [],
        };
    }),
);

async function buscarActivos(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionActivo[]> {
    if (empresaId.value === '') return [];
    const res = await fetch(
        `/activos/buscar?empresa_id=${empresaId.value}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).activos ?? [];
}

function alElegirActivo(i: number, o: OpcionActivo | null): void {
    filasUI[i].activoSel = o;
    filasUI[i].tallas = o?.tallas ?? [];
    form.componentes[i].activo_id = o?.id ?? '';
    form.componentes[i].talla_id = null;
    form.componentes[i].talla_libre = false;
    form.clearErrors(`componentes.${i}.activo_id`, `componentes.${i}.talla_id`);
}

function agregarFila(): void {
    form.componentes.push({
        activo_id: '',
        cantidad_requerida: 1,
        talla_id: null,
        talla_libre: false,
    });
    filasUI.push({ activoSel: null, tallas: [] });
}

function quitarFila(i: number): void {
    form.componentes.splice(i, 1);
    filasUI.splice(i, 1);
    if (form.componentes.length === 0) agregarFila();
}

/** Acceso laxo a errores anidados (`componentes.0.talla_id`). */
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);
function errFila(i: number, campo: string): string | undefined {
    return erroresLaxos.value[`componentes.${i}.${campo}`];
}

function enviar(): void {
    if (esEdicion) {
        form.put(`/conjuntos/${props.conjunto!.id}`);
    } else {
        form.post('/conjuntos');
    }
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar conjunto' : 'Nuevo conjunto'" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar conjunto' : 'Nuevo conjunto'"
            descripcion="Un conjunto agrupa activos que se entregan juntos (un uniforme completo, un kit de cómputo…). Sólo puede incluir activos de la misma empresa. No tiene existencia propia: su disponibilidad se calcula desde el stock real de cada componente."
        />

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="enviar">
            <section class="min-w-0 space-y-4 rounded-xl border p-4">
                <h2 class="text-sm font-semibold">Información</h2>

                <div v-if="!esEdicion" class="grid gap-1.5">
                    <Label for="empresa_id">Empresa / razón social</Label>
                    <select
                        id="empresa_id"
                        v-model="empresaId"
                        class="border-input bg-background h-9 min-w-0 rounded-md border px-3 text-sm"
                        @change="
                            form.empresa_id =
                                empresaId === '' ? null : empresaId
                        "
                    >
                        <option value="" disabled>
                            Selecciona una empresa
                        </option>
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

                <div class="grid gap-1.5">
                    <Label for="nombre">Nombre</Label>
                    <Input id="nombre" v-model="form.nombre" required />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="codigo"
                        >Código
                        <span class="text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    >
                    <Input
                        id="codigo"
                        v-model="form.codigo"
                        class="uppercase"
                    />
                    <InputError :message="form.errors.codigo" />
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

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.activo"
                        type="checkbox"
                        class="size-4 rounded border"
                    />
                    Activo disponible para operaciones
                </label>
            </section>

            <section class="min-w-0 space-y-4 rounded-xl border p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Componentes</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="agregarFila"
                    >
                        <Plus class="size-4" /> Agregar componente
                    </Button>
                </div>
                <InputError :message="form.errors.componentes" />

                <div
                    v-for="(fila, i) in form.componentes"
                    :key="i"
                    class="grid gap-2 rounded-lg border p-3"
                >
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <Label>Activo</Label>
                            <BuscadorAsync
                                :model-value="filasUI[i].activoSel"
                                :buscar="buscarActivos"
                                :dependencia="empresaId"
                                :disabled="empresaId === ''"
                                :etiqueta="(a) => (a as OpcionActivo).nombre"
                                :descripcion="
                                    (a) =>
                                        [
                                            (a as OpcionActivo).codigo,
                                            (a as OpcionActivo).tipo,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                "
                                placeholder="Buscar activo…"
                                placeholder-busqueda="Buscar por nombre o código"
                                sin-resultados="No hay activos para esta empresa."
                                :invalido="!!errFila(i, 'activo_id')"
                                @update:model-value="
                                    (v) =>
                                        alElegirActivo(
                                            i,
                                            v as OpcionActivo | null,
                                        )
                                "
                            />
                            <InputError :message="errFila(i, 'activo_id')" />
                        </div>
                        <Button
                            v-if="form.componentes.length > 1"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="mt-6"
                            :aria-label="`Quitar componente ${i + 1}`"
                            @click="quitarFila(i)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Cantidad requerida</Label>
                            <Input
                                v-model.number="fila.cantidad_requerida"
                                type="number"
                                min="1"
                                step="1"
                            />
                            <InputError
                                :message="errFila(i, 'cantidad_requerida')"
                            />
                        </div>

                        <div
                            v-if="filasUI[i].tallas.length > 0"
                            class="grid gap-1.5"
                        >
                            <Label>Variante</Label>
                            <select
                                v-model="fila.talla_id"
                                class="border-input bg-background h-9 rounded-md border px-2.5 text-sm"
                                :disabled="fila.talla_libre"
                            >
                                <option :value="null">Sin variante fija</option>
                                <option
                                    v-for="t in filasUI[i].tallas"
                                    :key="t.id"
                                    :value="t.id"
                                >
                                    {{ t.valor }}
                                </option>
                            </select>
                            <label
                                class="mt-1 flex items-center gap-1.5 text-xs"
                            >
                                <input
                                    v-model="fila.talla_libre"
                                    type="checkbox"
                                    class="size-3.5"
                                    @change="
                                        () => {
                                            if (fila.talla_libre)
                                                fila.talla_id = null;
                                        }
                                    "
                                />
                                Seleccionar variante durante la entrega
                            </label>
                            <InputError :message="errFila(i, 'talla_id')" />
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3 lg:col-span-2">
                <Button type="submit" :disabled="form.processing">
                    {{ esEdicion ? 'Guardar cambios' : 'Crear conjunto' }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/conjuntos">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
