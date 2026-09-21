<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ChevronDown,
    Download,
    TriangleAlert,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { EmpresaAutorizada } from '@/types/sistema';

type ErrorImportacion = {
    fila: number;
    campo: string | null;
    valor: unknown;
    error: string;
};
type ColumnasReporte = {
    esperadas: string[];
    obligatorias: string[];
    opcionales: string[];
    encontradas: string[];
    correctas: string[];
    faltantes: string[];
    adicionales: string[];
};
type AreasReporte = {
    existentes: string[];
    nuevas: string[];
};
type Analisis = {
    token: string;
    columnas: ColumnasReporte;
    areas: AreasReporte;
    total: number;
    listos: number;
    errores: ErrorImportacion[];
    duplicados: ErrorImportacion[];
    filas_con_error: number;
    filas_duplicadas: number;
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

const ETIQUETAS_COLUMNA: Record<string, string> = {
    nombre_completo: 'Nombre completo',
    curp: 'CURP',
    puesto: 'Puesto',
    area: 'Área',
    correo: 'Correo',
    sucursal_codigo: 'Código de sucursal',
};

function etiquetaColumna(col: string): string {
    return ETIQUETAS_COLUMNA[col] ?? col;
}

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

const columnasFaltantes = computed(
    () => props.analisis?.columnas.faltantes ?? [],
);
const bloqueadoPorColumnas = computed(() => columnasFaltantes.value.length > 0);
const problemas = computed<ErrorImportacion[]>(() => [
    ...(props.analisis?.errores ?? []),
    ...(props.analisis?.duplicados ?? []),
]);
const puedeConfirmar = computed(
    () =>
        !!props.analisis &&
        !bloqueadoPorColumnas.value &&
        problemas.value.length === 0 &&
        props.analisis.listos > 0,
);
const areasNuevas = computed(() => props.analisis?.areas.nuevas ?? []);

function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

const descargandoErrores = ref(false);

async function descargarErrores() {
    if (problemas.value.length === 0 || descargandoErrores.value) {
        return;
    }

    descargandoErrores.value = true;

    try {
        const respuesta = await fetch('/colaboradores/importar/errores', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ errores: problemas.value }),
        });

        if (!respuesta.ok) {
            return;
        }

        const blob = await respuesta.blob();
        const url = URL.createObjectURL(blob);
        const enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = 'errores-importacion-colaboradores.xlsx';
        enlace.click();
        URL.revokeObjectURL(url);
    } finally {
        descargandoErrores.value = false;
    }
}
</script>

<template>
    <Head title="Importar colaboradores" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Importar colaboradores desde Excel"
            descripcion="Elige la empresa destino. El número de empleado se genera automáticamente: no se captura en el archivo."
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
                <div class="text-muted-foreground text-sm">
                    <p>Columnas de la plantilla:</p>
                    <ul class="mt-1 list-inside list-disc">
                        <li v-for="c in columnas" :key="c">
                            {{ etiquetaColumna(c) }}
                            <code class="bg-muted rounded px-1 text-xs">{{
                                c
                            }}</code>
                        </li>
                    </ul>
                </div>
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
            <Alert v-if="bloqueadoPorColumnas" variant="destructive">
                <TriangleAlert class="size-4" />
                <AlertTitle>Faltan columnas en el archivo</AlertTitle>
                <AlertDescription>
                    <p>
                        No se puede analizar fila por fila hasta que el archivo
                        tenga las
                        {{ analisis.columnas.esperadas.length }} columnas de la
                        plantilla. Descarga la plantilla actualizada y vuelve a
                        intentarlo.
                    </p>
                    <p class="mt-2 font-medium">
                        Falta{{ columnasFaltantes.length > 1 ? 'n' : '' }}:
                        {{
                            columnasFaltantes
                                .map((c) => etiquetaColumna(c))
                                .join(', ')
                        }}
                    </p>
                </AlertDescription>
            </Alert>

            <template v-else>
                <div class="grid gap-3 sm:grid-cols-4">
                    <Card>
                        <CardContent class="pt-6">
                            <p class="text-2xl font-semibold">
                                {{ analisis.total }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Total de filas
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="flex items-center gap-3 pt-6">
                            <CheckCircle2 class="size-8 text-emerald-500" />
                            <div>
                                <p class="text-2xl font-semibold">
                                    {{ analisis.listos }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Listas para importar
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="flex items-center gap-3 pt-6">
                            <XCircle class="size-8 text-rose-500" />
                            <div>
                                <p class="text-2xl font-semibold">
                                    {{ analisis.filas_con_error }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Con errores
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="flex items-center gap-3 pt-6">
                            <TriangleAlert class="size-8 text-amber-500" />
                            <div>
                                <p class="text-2xl font-semibold">
                                    {{ analisis.filas_duplicadas }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Duplicados
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Columnas detectadas
                            <span class="text-muted-foreground font-normal"
                                >({{ analisis.columnas.correctas.length }} /
                                {{ analisis.columnas.esperadas.length }}
                                correctas)</span
                            ></CardTitle
                        >
                    </CardHeader>
                    <CardContent class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="c in analisis.columnas.correctas"
                            :key="'ok-'.concat(c)"
                            variant="secondary"
                        >
                            ✓ {{ etiquetaColumna(c) }}
                        </Badge>
                        <Badge
                            v-for="c in analisis.columnas.adicionales"
                            :key="'extra-'.concat(c)"
                            variant="outline"
                            class="border-amber-500 text-amber-600"
                        >
                            ⚠ {{ c }} (no reconocida, se ignora)
                        </Badge>
                    </CardContent>
                </Card>

                <Card
                    v-if="
                        analisis.areas.existentes.length ||
                        analisis.areas.nuevas.length
                    "
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Áreas detectadas</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div class="flex flex-wrap gap-1.5">
                            <Badge
                                v-for="a in analisis.areas.existentes"
                                :key="'area-ok-'.concat(a)"
                                variant="secondary"
                            >
                                ✓ {{ a }}
                            </Badge>
                            <Badge
                                v-for="a in analisis.areas.nuevas"
                                :key="'area-nueva-'.concat(a)"
                                variant="outline"
                                class="border-emerald-500 text-emerald-600"
                            >
                                + {{ a }} (nueva)
                            </Badge>
                        </div>
                        <p
                            v-if="areasNuevas.length"
                            class="text-muted-foreground text-sm"
                        >
                            Las áreas nuevas se crearán automáticamente al
                            confirmar la importación.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="problemas.length">
                    <CardHeader
                        class="flex flex-row items-center justify-between gap-3"
                    >
                        <CardTitle class="text-base"
                            >Errores por corregir</CardTitle
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="descargandoErrores"
                            @click="descargarErrores"
                        >
                            <Download class="size-4" />
                            Descargar errores (.xlsx)
                        </Button>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-muted-foreground text-sm">
                            Corrige el Excel y vuelve a analizarlo. Ningún
                            colaborador se importa mientras haya errores o
                            duplicados pendientes.
                        </p>
                        <Collapsible default-open class="rounded-lg border">
                            <CollapsibleTrigger
                                class="flex w-full items-center justify-between gap-2 p-3 text-left text-sm font-medium"
                            >
                                <span
                                    >{{ problemas.length }} fila(s) con
                                    problemas</span
                                >
                                <ChevronDown class="size-4" />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <div class="overflow-x-auto border-t">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr
                                                class="text-muted-foreground text-left"
                                            >
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Fila
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Campo
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Valor
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Error
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="(e, i) in problemas"
                                                :key="i"
                                                class="border-t"
                                            >
                                                <td class="px-3 py-2">
                                                    {{ e.fila }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    {{
                                                        e.campo
                                                            ? etiquetaColumna(
                                                                  e.campo,
                                                              )
                                                            : '—'
                                                    }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    {{
                                                        (e.valor as string) ??
                                                        '—'
                                                    }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    {{ e.error }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >2. Confirmar importación</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p v-if="puedeConfirmar" class="text-sm">
                            <strong>{{ analisis.listos }}</strong>
                            colaboradores listos para importar. El número de
                            empleado se generará automáticamente para cada uno.
                            <template v-if="areasNuevas.length">
                                Se
                                {{
                                    areasNuevas.length > 1
                                        ? 'crearán'
                                        : 'creará'
                                }}
                                {{ areasNuevas.length }}
                                {{
                                    areasNuevas.length > 1
                                        ? 'áreas nuevas'
                                        : 'área nueva'
                                }}
                                al confirmar la importación.
                            </template>
                        </p>
                        <p v-else class="text-muted-foreground text-sm">
                            Corrige los errores y duplicados señalados arriba y
                            vuelve a analizar el archivo para poder confirmar la
                            importación.
                        </p>
                        <Button
                            :disabled="
                                !puedeConfirmar || confirmForm.processing
                            "
                            @click="confirmar(analisis.token)"
                        >
                            Importar {{ analisis.listos }} colaboradores
                        </Button>
                    </CardContent>
                </Card>
            </template>
        </template>

        <div>
            <Button variant="ghost" as-child>
                <Link href="/colaboradores">Volver a colaboradores</Link>
            </Button>
        </div>
    </div>
</template>
