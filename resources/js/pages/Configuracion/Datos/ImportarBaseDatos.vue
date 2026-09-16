<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    DatabaseZap,
    Download,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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

type ErrorImportacion = {
    hoja: string;
    fila: number;
    campo: string | null;
    valor: unknown;
    error: string;
};
type ResumenHoja = { total: number; validos: number; errores: number };
type Analisis = {
    token: string;
    resumen: Record<string, ResumenHoja>;
    errores: ErrorImportacion[];
};

const props = defineProps<{
    baseNoVacia: boolean;
    analisis?: Analisis;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Configuración', href: '/configuracion' },
            {
                title: 'Importar base de datos',
                href: '/datos/importar-maestro',
            },
        ],
    },
});

const ETIQUETAS_HOJA: Record<string, string> = {
    EMPRESAS: 'Empresas',
    SUCURSALES: 'Sucursales',
    CONTRATOS: 'Contratos',
    SERVICIOS: 'Servicios',
    COLABORADORES: 'Colaboradores',
    TALLAS: 'Tallas',
    ACTIVOS: 'Activos',
    ACTIVO_TALLA: 'Activo · Talla',
    ALMACENES: 'Almacenes',
    UNIDADES_ACTIVO: 'Unidades',
};

function etiquetaHoja(hoja: string): string {
    return ETIQUETAS_HOJA[hoja] ?? hoja;
}

const archivo = ref<File | null>(null);
const form = useForm<{ archivo: File | null }>({ archivo: null });

function prevalidar() {
    form.archivo = archivo.value;
    form.post('/datos/importar-maestro/prevalidar', { forceFormData: true });
}

const confirmForm = useForm<{ token: string }>({ token: '' });
function confirmar(token: string) {
    confirmForm.token = token;
    confirmForm.post('/datos/importar-maestro/confirmar');
}

const totalErrores = computed(() => props.analisis?.errores.length ?? 0);
const totalCreados = computed(
    () =>
        Object.values(props.analisis?.resumen ?? {}).reduce(
            (acumulado, r) => acumulado + r.validos,
            0,
        ) ?? 0,
);

const erroresPorHoja = computed(() => {
    const grupos = new Map<string, ErrorImportacion[]>();
    for (const error of props.analisis?.errores ?? []) {
        const lista = grupos.get(error.hoja) ?? [];
        lista.push(error);
        grupos.set(error.hoja, lista);
    }

    return grupos;
});

function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

const descargandoErrores = ref(false);

async function descargarErrores() {
    if (!props.analisis || descargandoErrores.value) {
        return;
    }

    descargandoErrores.value = true;

    try {
        const respuesta = await fetch('/datos/importar-maestro/errores', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ errores: props.analisis.errores }),
        });

        if (!respuesta.ok) {
            return;
        }

        const blob = await respuesta.blob();
        const url = URL.createObjectURL(blob);
        const enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = 'errores-importacion-maestra.xlsx';
        enlace.click();
        URL.revokeObjectURL(url);
    } finally {
        descargandoErrores.value = false;
    }
}
</script>

<template>
    <Head title="Importar base de datos" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Importar base de datos maestra"
            descripcion="Carga inicial de Empresas, Sucursales, Contratos, Servicios, Tallas, Activos, Almacenes, Colaboradores y Unidades desde un único Excel. Pensado para poblar una base de datos VACÍA (arranque de un VPS nuevo)."
        />

        <Alert v-if="baseNoVacia" variant="destructive">
            <AlertTriangle class="size-4" />
            <AlertTitle>La base de datos ya contiene información</AlertTitle>
            <AlertDescription>
                Este importador es exclusivo para la carga inicial de una base
                vacía (se detectó al menos una empresa registrada). No se puede
                usar para actualizar datos existentes.
            </AlertDescription>
        </Alert>

        <template v-else>
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <DatabaseZap class="size-4" />
                        1. Cargar archivo
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p class="text-muted-foreground text-sm">
                        El archivo debe tener exactamente 10 hojas, en este
                        orden:
                        <code class="bg-muted rounded px-1 text-xs">
                            EMPRESAS, SUCURSALES, CONTRATOS, SERVICIOS,
                            COLABORADORES, TALLAS, ACTIVOS, ACTIVO_TALLA,
                            ALMACENES, UNIDADES_ACTIVO
                        </code>
                        . Nada se guarda todavía: primero se prevalida.
                    </p>
                    <SubidaArchivo
                        v-model="archivo"
                        tipo="documento"
                        tamano="large"
                        accept=".xlsx"
                        formatos-etiqueta="Formato aceptado: XLSX. Peso máximo: 20 MB."
                        :invalido="!!form.errors.archivo"
                        :cargando="form.processing"
                    />
                    <InputError :message="form.errors.archivo" />
                    <Button
                        :disabled="!archivo || form.processing"
                        @click="prevalidar"
                    >
                        Prevalidar archivo
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
                                    {{ totalCreados }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Registros listos para crear
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="flex items-center gap-3 pt-6">
                            <XCircle class="size-8 text-rose-500" />
                            <div>
                                <p class="text-2xl font-semibold">
                                    {{ totalErrores }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Errores críticos
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="flex items-center gap-3 pt-6">
                            <DatabaseZap class="text-muted-foreground size-8" />
                            <div>
                                <p class="text-2xl font-semibold">
                                    {{ Object.keys(analisis.resumen).length }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    Hojas leídas
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Resumen por hoja</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div
                                v-for="(r, hoja) in analisis.resumen"
                                :key="hoja"
                                class="rounded-lg border p-3"
                            >
                                <p class="text-sm font-medium">
                                    {{ etiquetaHoja(String(hoja)) }}
                                </p>
                                <p class="text-2xl font-semibold">
                                    {{ r.total }}
                                </p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <Badge
                                        v-if="r.validos > 0"
                                        variant="secondary"
                                        >{{ r.validos }} válidos</Badge
                                    >
                                    <Badge
                                        v-if="r.errores > 0"
                                        variant="destructive"
                                        >{{ r.errores }} con error</Badge
                                    >
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="totalErrores > 0">
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
                            Corrige el Excel maestro y vuelve a prevalidar.
                            Ningún dato se guarda mientras haya errores
                            pendientes.
                        </p>
                        <Collapsible
                            v-for="[hoja, errores] in erroresPorHoja"
                            :key="hoja"
                            default-open
                            class="rounded-lg border"
                        >
                            <CollapsibleTrigger
                                class="flex w-full items-center justify-between gap-2 p-3 text-left text-sm font-medium"
                            >
                                <span
                                    >{{ etiquetaHoja(hoja) }} ({{
                                        errores.length
                                    }})</span
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
                                                v-for="(e, i) in errores"
                                                :key="i"
                                                class="border-t"
                                            >
                                                <td class="px-3 py-2">
                                                    {{ e.fila }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    {{ e.campo ?? '—' }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    {{ e.valor ?? '—' }}
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
                        <p class="text-sm">
                            Se crearán
                            <strong>{{ totalCreados }}</strong>
                            registros en total, de forma atómica: si algo falla,
                            no se guarda nada.
                        </p>
                        <Button
                            :disabled="
                                totalErrores > 0 || confirmForm.processing
                            "
                            @click="confirmar(analisis.token)"
                        >
                            Confirmar importación
                        </Button>
                    </CardContent>
                </Card>
            </template>
        </template>
    </div>
</template>
