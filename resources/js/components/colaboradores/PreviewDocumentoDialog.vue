<script setup lang="ts">
import { Download, ExternalLink } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { iconoDocumento } from '@/lib/categoriasExpediente';

type VersionActual = {
    nombre_archivo_original: string;
    mime: string;
    extension: string;
    peso_bytes: number;
};

const props = defineProps<{
    open: boolean;
    nombre: string;
    version: VersionActual | null;
    verUrl: string;
    descargarUrl: string;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

function formatoPeso(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const esImagen = computed(
    () => props.version?.mime.startsWith('image/') ?? false,
);
const esPdf = computed(() => props.version?.mime === 'application/pdf');
const esTexto = computed(() => props.version?.mime === 'text/plain');
const esCsv = computed(() => props.version?.mime === 'text/csv');

const contenidoTexto = ref('');
const filasCsv = ref<string[][]>([]);
const cargandoContenido = ref(false);

async function cargarContenidoTextual(): Promise<void> {
    if (!esTexto.value && !esCsv.value) return;

    cargandoContenido.value = true;
    contenidoTexto.value = '';
    filasCsv.value = [];

    const res = await fetch(props.verUrl, { credentials: 'same-origin' });

    if (res.ok) {
        const texto = await res.text();

        if (esCsv.value) {
            filasCsv.value = texto
                .split(/\r?\n/)
                .filter((linea) => linea.trim() !== '')
                .slice(0, 50)
                .map((linea) => linea.split(','));
        } else {
            contenidoTexto.value = texto;
        }
    }

    cargandoContenido.value = false;
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) cargarContenidoTextual();
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-3xl md:max-w-4xl"
        >
            <DialogHeader>
                <DialogTitle class="truncate">{{ nombre }}</DialogTitle>
                <DialogDescription v-if="version">
                    {{ version.nombre_archivo_original }} ·
                    {{ formatoPeso(version.peso_bytes) }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="version" class="flex flex-col gap-3">
                <img
                    v-if="esImagen"
                    :src="verUrl"
                    :alt="nombre"
                    class="max-h-[70dvh] w-full rounded-md border object-contain"
                />

                <iframe
                    v-else-if="esPdf"
                    :src="verUrl"
                    class="h-[70dvh] w-full rounded-md border"
                    title="Vista previa del PDF"
                />

                <div
                    v-else-if="esTexto"
                    class="max-h-[70dvh] overflow-auto rounded-md border p-3"
                >
                    <p
                        v-if="cargandoContenido"
                        class="text-muted-foreground text-sm"
                    >
                        Cargando…
                    </p>
                    <pre v-else class="text-sm whitespace-pre-wrap">{{
                        contenidoTexto
                    }}</pre>
                </div>

                <div
                    v-else-if="esCsv"
                    class="max-h-[70dvh] overflow-auto rounded-md border"
                >
                    <p
                        v-if="cargandoContenido"
                        class="text-muted-foreground p-3 text-sm"
                    >
                        Cargando…
                    </p>
                    <table v-else class="w-full text-sm">
                        <tbody>
                            <tr
                                v-for="(fila, i) in filasCsv"
                                :key="i"
                                class="border-b last:border-0"
                            >
                                <td
                                    v-for="(celda, j) in fila"
                                    :key="j"
                                    class="px-2 py-1 whitespace-nowrap"
                                >
                                    {{ celda }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="!cargandoContenido && filasCsv.length === 50"
                        class="text-muted-foreground p-2 text-xs"
                    >
                        Mostrando las primeras 50 filas.
                    </p>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 rounded-md border border-dashed p-8 text-center"
                >
                    <component
                        :is="iconoDocumento(version.mime)"
                        class="text-muted-foreground size-10"
                    />
                    <p class="font-medium">
                        {{ version.nombre_archivo_original }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{ formatoPeso(version.peso_bytes) }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Este formato no admite vista previa dentro del sistema.
                    </p>
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        v-if="esImagen || esPdf"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a :href="verUrl" target="_blank" rel="noopener">
                            <ExternalLink class="size-3.5" /> Abrir en nueva
                            pestaña
                        </a>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="descargarUrl">
                            <Download class="size-3.5" /> Descargar
                        </a>
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
