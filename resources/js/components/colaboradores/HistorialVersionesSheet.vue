<script setup lang="ts">
import { Download } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

type VersionHistorial = {
    version: number;
    nombre_archivo_original: string;
    mime: string;
    peso_bytes: number;
    hash_sha256: string;
    comentario: string | null;
    subido_por: string | null;
    subido_en: string;
};

const props = defineProps<{
    open: boolean;
    nombre: string;
    versionesUrl: string;
    descargarVersionUrlBase: string;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const versiones = ref<VersionHistorial[]>([]);
const cargando = ref(false);
const detalleAbierto = ref<number | null>(null);

function formatoPeso(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatoFecha(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

async function cargar(): Promise<void> {
    cargando.value = true;
    versiones.value = [];

    const res = await fetch(props.versionesUrl, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (res.ok) {
        versiones.value = (await res.json()).versiones ?? [];
    }

    cargando.value = false;
}

watch(
    () => props.open,
    (abierto) => {
        detalleAbierto.value = null;
        if (abierto) cargar();
    },
);
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle class="truncate"
                    >Historial de "{{ nombre }}"</SheetTitle
                >
                <SheetDescription>
                    Ninguna versión anterior se borra al subir una nueva.
                </SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-0 px-4 pb-4">
                <p v-if="cargando" class="text-muted-foreground text-sm">
                    Cargando…
                </p>

                <div
                    v-for="(v, i) in versiones"
                    :key="v.version"
                    class="relative flex gap-3 pb-6 last:pb-0"
                >
                    <div class="flex flex-col items-center">
                        <span
                            class="bg-primary mt-1 size-2.5 shrink-0 rounded-full"
                        />
                        <span
                            v-if="i < versiones.length - 1"
                            class="bg-border mt-1 w-px flex-1"
                        />
                    </div>
                    <div class="min-w-0 flex-1 space-y-1 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">v{{ v.version }}</span>
                            <Button variant="outline" size="sm" as-child>
                                <a
                                    :href="`${descargarVersionUrlBase}/${v.version}/descargar`"
                                >
                                    <Download class="size-3.5" /> Descargar
                                </a>
                            </Button>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ formatoFecha(v.subido_en) }}
                            <template v-if="v.subido_por">
                                · Subido por {{ v.subido_por }}</template
                            >
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ v.nombre_archivo_original }} ·
                            {{ formatoPeso(v.peso_bytes) }}
                        </p>
                        <p v-if="v.comentario" class="text-pretty">
                            {{ v.comentario }}
                        </p>
                        <button
                            type="button"
                            class="text-muted-foreground text-xs underline"
                            @click="
                                detalleAbierto =
                                    detalleAbierto === v.version
                                        ? null
                                        : v.version
                            "
                        >
                            Detalles técnicos
                        </button>
                        <p
                            v-if="detalleAbierto === v.version"
                            class="text-muted-foreground font-mono text-[11px] break-all"
                        >
                            SHA-256: {{ v.hash_sha256 }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="!cargando && !versiones.length"
                    class="text-muted-foreground text-sm"
                >
                    No hay versiones registradas.
                </p>
            </div>
        </SheetContent>
    </Sheet>
</template>
