<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import CapturaEvidencia from '@/components/sistema/CapturaEvidencia.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    type CampoEspecificacion,
    ETIQUETA_CAMPO,
    ETIQUETA_PERFIL,
    type EspecificacionUnidad,
    type PerfilTecnico,
    camposRequeridos,
    camposVisibles,
    especificacionVacia,
} from '@/lib/perfilTecnicoUnidad';

type OpcionAlmacen = { id: number; nombre: string; codigo: string | null };
type OpcionVariante = { id: number; valor: string };

const props = defineProps<{
    open: boolean;
    activoId: number;
    empresaId: number;
    usaVariantes: boolean;
    esSeguimientoIndividual: boolean;
    /** Perfil técnico del activo (Celular / Computadora / Tablet), o null. */
    perfilTecnico?: PerfilTecnico | null;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm<{
    almacen_id: number | null;
    talla_id: number | null;
    cantidad: number;
    motivo: string;
    abrir_etiquetas: boolean;
    especificaciones: EspecificacionUnidad[];
    imagenes: (File | null)[];
    imagenes_origen: ('camara' | 'archivo' | null)[];
}>({
    almacen_id: null,
    talla_id: null,
    cantidad: 1,
    motivo: '',
    abrir_etiquetas: false,
    especificaciones: [],
    imagenes: [],
    imagenes_origen: [],
});

// Gate del bloque "Unidad N": aplica a cualquier alta de unidades, tenga o
// no perfil técnico — la foto nunca depende de eso.
const mostrarUnidades = computed(
    () => props.esSeguimientoIndividual && form.cantidad > 0,
);
const mostrarEspecificaciones = computed(
    () => mostrarUnidades.value && !!props.perfilTecnico,
);
const camposDelPerfil = computed<CampoEspecificacion[]>(() =>
    props.perfilTecnico ? camposVisibles(props.perfilTecnico) : [],
);
function campoRequerido(campo: CampoEspecificacion): boolean {
    return props.perfilTecnico
        ? camposRequeridos(props.perfilTecnico).includes(campo)
        : false;
}
const erroresLaxos = computed(
    () => form.errors as unknown as Record<string, string>,
);

watch(
    () => [mostrarUnidades.value, mostrarEspecificaciones.value, form.cantidad],
    () => {
        if (!mostrarUnidades.value) {
            form.especificaciones = [];
            form.imagenes = [];
            form.imagenes_origen = [];
            return;
        }
        form.especificaciones = mostrarEspecificaciones.value
            ? Array.from(
                  { length: form.cantidad },
                  (_, i) => form.especificaciones[i] ?? especificacionVacia(),
              )
            : [];
        form.imagenes = Array.from(
            { length: form.cantidad },
            (_, i) => form.imagenes[i] ?? null,
        );
        form.imagenes_origen = Array.from(
            { length: form.cantidad },
            (_, i) => form.imagenes_origen[i] ?? null,
        );
    },
    { immediate: true },
);

const almacenSel = ref<OpcionAlmacen | null>(null);
const tallaSel = ref<OpcionVariante | null>(null);

async function buscarAlmacenes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionAlmacen[]> {
    const res = await fetch(
        `/almacenes/buscar?empresa_id=${props.empresaId}&q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).almacenes ?? [];
}

async function buscarVariantes(
    q: string,
    signal?: AbortSignal,
): Promise<OpcionVariante[]> {
    const params = new URLSearchParams({
        activo_id: String(props.activoId),
        q,
    });
    const res = await fetch(`/tallas/buscar?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) return [];
    return (await res.json()).tallas ?? [];
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            form.reset();
            form.clearErrors();
            almacenSel.value = null;
            tallaSel.value = null;
        }
    },
);

function enviar(): void {
    form.post(`/activos/${props.activoId}/existencias`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>
                    {{
                        esSeguimientoIndividual
                            ? 'Agregar unidades'
                            : 'Agregar existencias'
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="esSeguimientoIndividual">
                        Genera más unidades para este activo: el sistema crea un
                        código nuevo por cada una.
                    </template>
                    <template v-else>
                        Registra nuevas piezas que ingresaron al inventario de
                        este activo. El cambio quedará registrado en el
                        historial.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviar">
                <div class="grid gap-1.5">
                    <Label for="existencias-almacen">Almacén</Label>
                    <BuscadorAsync
                        id="existencias-almacen"
                        :model-value="almacenSel"
                        :buscar="buscarAlmacenes"
                        :etiqueta="(a) => (a as OpcionAlmacen).nombre"
                        :descripcion="(a) => (a as OpcionAlmacen).codigo ?? ''"
                        placeholder="Selecciona un almacén"
                        placeholder-busqueda="Buscar almacén por nombre"
                        sin-resultados="Este almacén no abastece a la empresa del activo."
                        :invalido="!!form.errors.almacen_id"
                        @update:model-value="
                            (v) => {
                                almacenSel = v as OpcionAlmacen | null;
                                form.almacen_id =
                                    (v as OpcionAlmacen | null)?.id ?? null;
                                form.clearErrors('almacen_id');
                            }
                        "
                    />
                    <InputError :message="form.errors.almacen_id" />
                </div>

                <div v-if="usaVariantes" class="grid gap-1.5">
                    <Label for="existencias-variante">Variante / talla</Label>
                    <BuscadorAsync
                        id="existencias-variante"
                        :model-value="tallaSel"
                        :buscar="buscarVariantes"
                        :etiqueta="(t) => (t as OpcionVariante).valor"
                        placeholder="Selecciona la variante"
                        placeholder-busqueda="Buscar variante"
                        :invalido="!!form.errors.talla_id"
                        @update:model-value="
                            (v) => {
                                tallaSel = v as OpcionVariante | null;
                                form.talla_id =
                                    (v as OpcionVariante | null)?.id ?? null;
                                form.clearErrors('talla_id');
                            }
                        "
                    />
                    <InputError :message="form.errors.talla_id" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="existencias-cantidad">
                        {{
                            esSeguimientoIndividual
                                ? 'Cantidad de unidades a agregar'
                                : 'Cantidad'
                        }}
                    </Label>
                    <Input
                        id="existencias-cantidad"
                        v-model.number="form.cantidad"
                        type="number"
                        min="1"
                        step="1"
                    />
                    <InputError :message="form.errors.cantidad" />
                </div>

                <div
                    v-if="mostrarUnidades"
                    class="grid max-h-64 gap-2 overflow-y-auto"
                >
                    <p
                        v-if="mostrarEspecificaciones && perfilTecnico"
                        class="text-xs font-medium"
                    >
                        Datos del equipo · {{ ETIQUETA_PERFIL[perfilTecnico] }}
                    </p>
                    <InputError :message="form.errors.especificaciones" />
                    <div
                        v-for="(_imagen, i) in form.imagenes"
                        :key="i"
                        class="grid gap-1.5 rounded-lg border p-2"
                    >
                        <p class="text-muted-foreground text-xs">
                            Unidad {{ i + 1 }}
                        </p>
                        <template
                            v-if="mostrarEspecificaciones && perfilTecnico"
                        >
                            <div
                                v-for="campo in camposDelPerfil"
                                :key="campo"
                                class="grid gap-1"
                            >
                                <Label
                                    :for="`ae-${i}-${campo}`"
                                    class="text-xs"
                                >
                                    {{ ETIQUETA_CAMPO[campo] }}
                                    <span
                                        v-if="campoRequerido(campo)"
                                        class="text-destructive"
                                        >*</span
                                    >
                                </Label>
                                <Input
                                    :id="`ae-${i}-${campo}`"
                                    v-model="form.especificaciones[i][campo]"
                                    class="h-8"
                                    autocomplete="off"
                                />
                                <InputError
                                    :message="
                                        erroresLaxos[
                                            `especificaciones.${i}.${campo}`
                                        ]
                                    "
                                />
                            </div>
                        </template>
                        <div class="grid gap-1">
                            <Label :for="`ae-img-${i}`" class="text-xs">
                                Foto
                                <span class="text-muted-foreground"
                                    >(opcional)</span
                                >
                            </Label>
                            <CapturaEvidencia
                                :id="`ae-img-${i}`"
                                v-model="form.imagenes[i]"
                                v-model:origen="form.imagenes_origen[i]"
                                etiqueta="Tomar foto / Subir archivo"
                            />
                            <InputError
                                :message="erroresLaxos[`imagenes.${i}`]"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="esSeguimientoIndividual" class="space-y-1">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="form.abrir_etiquetas"
                            type="checkbox"
                            class="size-4"
                        />
                        Abrir las etiquetas para imprimir al guardar
                    </label>
                    <p class="text-muted-foreground text-xs">
                        El código QR de cada unidad queda disponible siempre;
                        esto sólo abre el PDF de etiquetas para imprimirlas
                        ahora. Podrás reimprimirlas después desde cada unidad.
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="existencias-motivo"
                        >Motivo
                        <span class="text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    >
                    <Input
                        id="existencias-motivo"
                        v-model="form.motivo"
                        placeholder="p. ej. Compra, reposición…"
                    />
                    <InputError :message="form.errors.motivo" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('update:open', false)"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Agregar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
