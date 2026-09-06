<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PadFirma from '@/components/sistema/PadFirma.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const props = defineProps<{
    devolucion: {
        id: number;
        folio: string;
        fecha: string;
        motivo: string | null;
        empresa: string;
        sucursal: string;
        operador: string;
        colaborador: {
            nombre_completo: string;
            numero_empleado: string;
        } | null;
        items: {
            activo: string;
            talla: string | null;
            cantidad: number | null;
        }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Devoluciones', href: '/devoluciones' },
            { title: 'Firma de conformidad', href: '#' },
        ],
    },
});

const TEXTO_CONSENTIMIENTO =
    'He leído el detalle de esta devolución y confirmo que recibo los activos descritos bajo mi responsabilidad.';

const padColaborador = ref<InstanceType<typeof PadFirma> | null>(null);
const padOperador = ref<InstanceType<typeof PadFirma> | null>(null);
const vacioColaborador = ref(true);
const vacioOperador = ref(true);
const aceptacion = ref(false);

const form = useForm({ firma: '', firma_operador: '', aceptacion: false });

const puedeConfirmar = computed(
    () =>
        !vacioColaborador.value &&
        !vacioOperador.value &&
        aceptacion.value &&
        !form.processing,
);

function confirmar() {
    const firmaColaborador = padColaborador.value?.obtenerDataUrl();
    const firmaOperador = padOperador.value?.obtenerDataUrl();

    if (!firmaColaborador || !firmaOperador || !aceptacion.value) {
        return;
    }

    form.firma = firmaColaborador;
    form.firma_operador = firmaOperador;
    form.aceptacion = aceptacion.value;
    form.post(`/devoluciones/${props.devolucion.id}/firmar`, {
        onError: () => {
            /* los errores se muestran vía toast/InputError */
        },
    });
}
</script>

<template>
    <Head :title="`Firmar devolución ${devolucion.folio}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Acuse de devolución"
            :descripcion="`Devolución ${devolucion.folio} · ${devolucion.empresa}`"
        />

        <Card>
            <CardHeader>
                <CardTitle class="text-base"
                    >Revisa el contenido antes de firmar</CardTitle
                >
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <div class="grid gap-1 sm:grid-cols-2">
                    <p>
                        <span class="text-muted-foreground">Colaborador:</span>
                        {{ devolucion.colaborador?.nombre_completo }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">N.º empleado:</span>
                        {{ devolucion.colaborador?.numero_empleado }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Sucursal:</span>
                        {{ devolucion.sucursal }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Recibe:</span>
                        {{ devolucion.operador }}
                    </p>
                    <p v-if="devolucion.motivo">
                        <span class="text-muted-foreground">Motivo:</span>
                        {{ devolucion.motivo }}
                    </p>
                </div>

                <table class="w-full">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="py-1.5">Activo</th>
                            <th class="py-1.5">Talla</th>
                            <th class="py-1.5 text-right">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(it, i) in devolucion.items"
                            :key="i"
                            class="border-t"
                        >
                            <td class="py-1.5">{{ it.activo }}</td>
                            <td class="py-1.5">{{ it.talla ?? '—' }}</td>
                            <td class="py-1.5 text-right">
                                {{ it.cantidad ?? 1 }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Firma de quien devuelve</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    Firma del colaborador ({{
                        devolucion.colaborador?.nombre_completo
                    }}) que hace la devolución de los activos descritos.
                </p>
                <PadFirma
                    ref="padColaborador"
                    @cambio="(v: boolean) => (vacioColaborador = v)"
                />
                <p v-if="form.errors.firma" class="text-destructive text-sm">
                    {{ form.errors.firma }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Firma de quien recibe</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <label
                    class="bg-muted/40 flex items-start gap-2 rounded-lg border p-3 text-sm"
                >
                    <input
                        v-model="aceptacion"
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <span>{{ TEXTO_CONSENTIMIENTO }}</span>
                </label>
                <PadFirma
                    ref="padOperador"
                    @cambio="(v: boolean) => (vacioOperador = v)"
                />
                <p
                    v-if="form.errors.firma_operador"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.firma_operador }}
                </p>
                <p
                    v-if="form.errors.aceptacion"
                    class="text-destructive text-sm"
                >
                    {{ form.errors.aceptacion }}
                </p>
            </CardContent>
        </Card>

        <div class="flex items-center gap-3">
            <Button :disabled="!puedeConfirmar" @click="confirmar">
                Firmar y confirmar devolución
            </Button>
            <Button variant="ghost" as-child>
                <Link href="/devoluciones">Cancelar</Link>
            </Button>
        </div>
    </div>
</template>
