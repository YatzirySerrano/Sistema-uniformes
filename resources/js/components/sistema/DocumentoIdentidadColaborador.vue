<script setup lang="ts">
import { ExternalLink, IdCard } from '@lucide/vue';
import { computed, ref } from 'vue';
import CapturaEvidencia from '@/components/sistema/CapturaEvidencia.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { xsrfToken } from '@/lib/utils';

/**
 * Bloque "Documento de identidad" reutilizado por Entregas y Devoluciones
 * (`App\Servicios\ServicioIdentidadColaborador` en el backend): verificación
 * VISUAL de la identificación oficial del colaborador antes de solicitar su
 * firma. Si no existe, permite capturarla (cámara o archivo) y la guarda en
 * su expediente. Sube por `fetch`+FormData (no Inertia) para no recrear el
 * wizard que lo contiene — mismo patrón que `Entregas/Crear.vue::guardarIne`.
 */
export type DocIdentidad = {
    disponible: boolean;
    nombre?: string;
    mime?: string;
    previsualizable?: boolean;
    actualizado_en?: string;
    url?: string;
};

const props = withDefaults(
    defineProps<{
        /** GET → metadata JSON (`{disponible, mime, previsualizable, url, …}`). */
        urlMetadata: string;
        /** GET → stream inline privado del documento. */
        urlVer: string;
        /** POST → captura de una identificación faltante (FormData `archivo`). */
        urlGuardar: string;
        notaConsulta?: string;
        notaCaptura?: string;
    }>(),
    {
        notaConsulta:
            'Consulta el documento registrado en el expediente para realizar una verificación visual antes de solicitar la firma.',
        notaCaptura:
            'Se guardará en el expediente del colaborador (carpeta Identificación) y quedará disponible para ésta y futuras operaciones. No es obligatorio para continuar.',
    },
);

const docIdentidad = ref<DocIdentidad | null>(null);
const cargando = ref(false);
const previewAbierto = ref(false);

async function cargar(): Promise<void> {
    cargando.value = true;
    docIdentidad.value = null;
    try {
        const res = await fetch(props.urlMetadata, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        docIdentidad.value = res.ok ? await res.json() : { disponible: false };
    } catch {
        docIdentidad.value = { disponible: false };
    } finally {
        cargando.value = false;
    }
}

defineExpose({ cargar });

// --- Captura de identificación faltante ---
const MAX_MB = 10;
const archivo = ref<File | null>(null);
const guardando = ref(false);
const error = ref<string | null>(null);
const exito = ref<string | null>(null);

async function guardar(): Promise<void> {
    if (archivo.value === null) return;
    error.value = null;
    exito.value = null;

    if (archivo.value.size > MAX_MB * 1024 * 1024) {
        error.value = `El archivo pesa demasiado. El máximo permitido es ${MAX_MB} MB. Toma una foto con la cámara o sube una versión más ligera.`;
        return;
    }

    guardando.value = true;
    const cuerpo = new FormData();
    cuerpo.append('archivo', archivo.value);
    try {
        const res = await fetch(props.urlGuardar, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: cuerpo,
        });
        const j = (await res.json().catch(() => ({}))) as {
            ok?: boolean;
            message?: string;
            documento?: DocIdentidad;
            errors?: { archivo?: string[] };
        };

        if (!res.ok) {
            if (res.status === 403) {
                error.value =
                    'No tienes permiso para agregar la identificación de este colaborador.';
            } else {
                error.value =
                    j.errors?.archivo?.[0] ??
                    j.message ??
                    'No se pudo guardar la identificación.';
            }
            return;
        }

        archivo.value = null;
        exito.value = 'Identificación guardada correctamente en el expediente.';
        docIdentidad.value = j.documento ?? { disponible: true };
        void cargar();
    } catch {
        error.value =
            'No se pudo guardar la identificación. Revisa tu conexión e inténtalo de nuevo.';
    } finally {
        guardando.value = false;
    }
}

const esImagen = computed(() =>
    (docIdentidad.value?.mime ?? '').startsWith('image/'),
);
const esPdf = computed(() => docIdentidad.value?.mime === 'application/pdf');
</script>

<template>
    <div>
        <div class="bg-muted/30 rounded-lg border p-3">
            <div class="flex flex-wrap items-center gap-2">
                <IdCard class="text-muted-foreground size-4" />
                <span class="text-sm font-medium">Documento de identidad</span>
                <Button
                    v-if="docIdentidad?.disponible"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="ml-auto"
                    @click="previewAbierto = true"
                >
                    <ExternalLink class="size-3.5" /> Ver identificación
                </Button>
            </div>

            <p v-if="cargando" class="text-muted-foreground mt-2 text-xs">
                Buscando el documento en el expediente…
            </p>
            <template v-else-if="docIdentidad?.disponible">
                <p class="text-muted-foreground mt-2 text-xs">
                    {{ notaConsulta }}
                </p>
            </template>
            <template v-else-if="docIdentidad !== null">
                <p
                    class="mt-2 flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500"
                >
                    No se encontró una identificación en el expediente de este
                    colaborador.
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <CapturaEvidencia
                        v-model="archivo"
                        permite-pdf
                        etiqueta="Tomar foto o subir identificación"
                    />
                    <Button
                        v-if="archivo"
                        type="button"
                        size="sm"
                        :disabled="guardando"
                        @click="guardar"
                    >
                        {{
                            guardando
                                ? 'Guardando…'
                                : 'Guardar en el expediente'
                        }}
                    </Button>
                </div>
                <p
                    v-if="error"
                    class="border-destructive/40 bg-destructive/10 text-destructive mt-2 rounded-md border p-2 text-sm"
                >
                    {{ error }}
                </p>
                <p
                    v-if="exito"
                    class="mt-2 rounded-md border border-emerald-500/40 bg-emerald-500/10 p-2 text-sm text-emerald-700 dark:text-emerald-400"
                >
                    {{ exito }}
                </p>
                <p class="text-muted-foreground mt-1 text-[11px]">
                    {{ notaCaptura }}
                </p>
            </template>
        </div>

        <!-- Diálogo de previsualización -->
        <Dialog v-model:open="previewAbierto">
            <DialogContent
                class="max-h-[90dvh] w-[calc(100vw-2rem)] overflow-auto sm:max-w-3xl"
            >
                <DialogHeader>
                    <DialogTitle>Documento de identidad</DialogTitle>
                    <DialogDescription>
                        Verificación visual antes de firmar. Este documento no
                        se adjunta al acuse ni al correo.
                    </DialogDescription>
                </DialogHeader>

                <div class="mt-2">
                    <img
                        v-if="
                            docIdentidad?.disponible &&
                            docIdentidad.url &&
                            esImagen
                        "
                        :src="docIdentidad.url"
                        alt="Documento de identidad del colaborador"
                        class="mx-auto max-h-[70dvh] w-auto rounded-md border"
                    />
                    <iframe
                        v-else-if="
                            docIdentidad?.disponible &&
                            docIdentidad.url &&
                            esPdf
                        "
                        :src="docIdentidad.url"
                        title="Documento de identidad del colaborador"
                        class="h-[70dvh] w-full rounded-md border"
                    />
                    <p
                        v-else
                        class="text-muted-foreground py-8 text-center text-sm"
                    >
                        Este documento no puede previsualizarse aquí. Consúltalo
                        desde el expediente del colaborador.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
