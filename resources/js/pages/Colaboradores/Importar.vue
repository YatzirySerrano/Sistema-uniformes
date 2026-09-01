<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Download, TriangleAlert, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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

defineProps<{ columnas: string[]; analisis?: Analisis }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Importar', href: '/colaboradores/importar' },
        ],
    },
});

const archivo = ref<File | null>(null);
const form = useForm<{ archivo: File | null }>({ archivo: null });

function subir() {
    form.archivo = archivo.value;
    form.post('/colaboradores/importar/analizar', { forceFormData: true });
}

const confirmForm = useForm({ token: '' });
function confirmar(token: string) {
    confirmForm.token = token;
    confirmForm.post('/colaboradores/importar/confirmar');
}
</script>

<template>
    <Head title="Importar colaboradores" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Importar colaboradores desde Excel"
            descripcion="La empresa siempre es la empresa activa. La columna sucursal_codigo debe existir en esta empresa."
        >
            <template #acciones>
                <Button variant="outline" as-child>
                    <a href="/colaboradores/importar/plantilla">
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
                <p class="text-muted-foreground text-sm">
                    Columnas esperadas:
                    <code class="bg-muted rounded px-1">{{
                        columnas.join(', ')
                    }}</code>
                </p>
                <input
                    type="file"
                    accept=".xlsx,.xls,.csv"
                    class="text-sm"
                    @change="
                        archivo =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <InputError :message="form.errors.archivo" />
                <Button :disabled="!archivo || form.processing" @click="subir">
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
