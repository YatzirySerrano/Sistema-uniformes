<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Download, TriangleAlert, XCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { EmpresaAutorizada } from '@/types/sistema';

type Fila = {
    fila: number;
    datos: Record<string, string | null>;
    motivo?: string;
};
type Analisis = {
    token: string;
    total: number;
    validos: { fila: number; datos: Record<string, string | null> }[];
    duplicados: Fila[];
    errores: { fila: number; errores: string[] }[];
    importados: number;
};

const props = defineProps<{
    columnas: string[];
    empresasAutorizadas: EmpresaAutorizada[];
    empresaSeleccionadaId?: number;
    analisis?: Analisis;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Importar', href: '/colaboradores/importar' },
        ],
    },
});

const empresaIdInicial =
    props.empresaSeleccionadaId ??
    (props.empresasAutorizadas.length === 1
        ? props.empresasAutorizadas[0].id
        : '');
const empresaSel = ref<EmpresaAutorizada | null>(
    props.empresasAutorizadas.find((e) => e.id === empresaIdInicial) ?? null,
);
const empresaId = computed(() => empresaSel.value?.id ?? '');

async function buscarEmpresas(termino: string) {
    const t = termino.trim().toLowerCase();

    return props.empresasAutorizadas.filter((e) =>
        e.nombre_comercial.toLowerCase().includes(t),
    );
}

const archivo = ref<File | null>(null);
const form = useForm<{ empresa_id: number | ''; archivo: File | null }>({
    empresa_id: empresaId.value,
    archivo: null,
});

function subir() {
    form.empresa_id = empresaId.value;
    form.archivo = archivo.value;
    form.post('/colaboradores/importar/analizar', { forceFormData: true });
}

const confirmForm = useForm({ empresa_id: empresaId.value, token: '' });
function confirmar(token: string) {
    confirmForm.empresa_id = empresaId.value;
    confirmForm.token = token;
    confirmForm.post('/colaboradores/importar/confirmar');
}

const urlPlantilla = () =>
    empresaId.value
        ? `/colaboradores/importar/plantilla?empresa_id=${empresaId.value}`
        : '#';
</script>

<template>
    <Head title="Importar colaboradores" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Importar colaboradores desde Excel"
            descripcion="Elige la empresa destino. La columna sucursal_codigo debe existir en esa empresa."
        >
            <template #acciones>
                <Button variant="outline" as-child :disabled="!empresaId">
                    <a :href="urlPlantilla()">
                        <Download class="size-4" /> Descargar plantilla
                    </a>
                </Button>
            </template>
        </EncabezadoPagina>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">1. Cargar archivo</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div class="grid gap-1.5">
                    <label for="imp-empresa" class="text-sm font-medium"
                        >Empresa destino</label
                    >
                    <div class="w-64">
                        <BuscadorAsync
                            id="imp-empresa"
                            v-model="empresaSel"
                            :buscar="buscarEmpresas"
                            :etiqueta="(e) => String(e.nombre_comercial)"
                            placeholder="Selecciona una empresa"
                            placeholder-busqueda="Buscar empresa…"
                        />
                    </div>
                    <InputError :message="form.errors.empresa_id" />
                </div>
                <p class="text-muted-foreground text-sm">
                    Columnas esperadas:
                    <code class="bg-muted rounded px-1">{{
                        columnas.join(', ')
                    }}</code>
                </p>
                <SubidaArchivo
                    v-model="archivo"
                    tipo="documento"
                    tamano="large"
                    accept=".xlsx,.xls,.csv"
                    formatos-etiqueta="Formatos aceptados: XLSX, XLS o CSV."
                    :invalido="!!form.errors.archivo"
                    :cargando="form.processing"
                />
                <InputError :message="form.errors.archivo" />
                <Button
                    :disabled="!archivo || !empresaId || form.processing"
                    @click="subir"
                >
                    Analizar archivo
                </Button>
            </CardContent>
        </Card>

        <template v-if="analisis">
            <div class="grid gap-3 sm:grid-cols-3">
                <Card>
                    <CardContent class="flex items-center gap-3 pt-6">
                        <CheckCircle2 class="size-8 text-emerald-500" />
                        <div>
                            <p class="text-2xl font-semibold">
                                {{ analisis.validos.length }}
                            </p>
                            <p class="text-muted-foreground text-xs">Válidos</p>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="flex items-center gap-3 pt-6">
                        <TriangleAlert class="size-8 text-amber-500" />
                        <div>
                            <p class="text-2xl font-semibold">
                                {{ analisis.duplicados.length }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Duplicados
                            </p>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="flex items-center gap-3 pt-6">
                        <XCircle class="size-8 text-rose-500" />
                        <div>
                            <p class="text-2xl font-semibold">
                                {{ analisis.errores.length }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Con errores
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="analisis.errores.length">
                <CardHeader>
                    <CardTitle class="text-base"
                        >Errores por corregir</CardTitle
                    >
                </CardHeader>
                <CardContent class="space-y-1 text-sm">
                    <div
                        v-for="e in analisis.errores"
                        :key="e.fila"
                        class="border-b py-1.5 last:border-0"
                    >
                        <span class="font-medium">Fila {{ e.fila }}:</span>
                        {{ e.errores.join(' ') }}
                    </div>
                </CardContent>
            </Card>

            <Card v-if="analisis.duplicados.length">
                <CardHeader>
                    <CardTitle class="text-base"
                        >Duplicados (se omiten)</CardTitle
                    >
                </CardHeader>
                <CardContent class="space-y-1 text-sm">
                    <div
                        v-for="d in analisis.duplicados"
                        :key="d.fila"
                        class="border-b py-1.5 last:border-0"
                    >
                        <span class="font-medium">Fila {{ d.fila }}:</span>
                        {{ d.datos.numero_empleado }} — {{ d.motivo }}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >2. Confirmar importación</CardTitle
                    >
                </CardHeader>
                <CardContent class="space-y-3">
                    <p class="text-sm">
                        Se importarán
                        <strong>{{ analisis.validos.length }}</strong>
                        colaboradores. Los duplicados y las filas con errores no
                        se importan.
                    </p>
                    <Button
                        :disabled="
                            !analisis.validos.length || confirmForm.processing
                        "
                        @click="confirmar(analisis.token)"
                    >
                        Importar {{ analisis.validos.length }} colaboradores
                    </Button>
                </CardContent>
            </Card>
        </template>

        <div>
            <Button variant="ghost" as-child>
                <Link href="/colaboradores">Volver a colaboradores</Link>
            </Button>
        </div>
    </div>
</template>
