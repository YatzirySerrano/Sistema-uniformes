<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PadFirma from '@/components/entregas/PadFirma.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const props = defineProps<{
    entrega: {
        id: number;
        folio: string;
        fecha_entrega: string;
        empresa: string;
        sucursal: string;
        encargado: string;
        colaborador: {
            nombre_completo: string;
            numero_empleado: string;
        } | null;
        items: { activo: string; talla: string; cantidad: number }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Entregas', href: '/entregas' },
            { title: 'Firma de recepción', href: '#' },
        ],
    },
});

const pad = ref<InstanceType<typeof PadFirma> | null>(null);
const vacio = ref(true);
const form = useForm({ firma: '' });

function confirmar() {
    const data = pad.value?.obtenerDataUrl();
    if (!data) {
        vacio.value = true;
        return;
    }
    form.firma = data;
    form.post(`/entregas/${props.entrega.id}/firmar`, {
        onError: () => {
            /* los errores se muestran vía toast/InputError */
        },
    });
}
</script>

<template>
    <Head :title="`Firmar acuse ${entrega.folio}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            titulo="Acuse de recepción"
            :descripcion="`Entrega ${entrega.folio} · ${entrega.empresa}`"
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
                        {{ entrega.colaborador?.nombre_completo }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">N.º empleado:</span>
                        {{ entrega.colaborador?.numero_empleado }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Sucursal:</span>
                        {{ entrega.sucursal }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Responsable:</span>
                        {{ entrega.encargado }}
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
                            v-for="(it, i) in entrega.items"
                            :key="i"
                            class="border-t"
                        >
                            <td class="py-1.5">{{ it.activo }}</td>
                            <td class="py-1.5">{{ it.talla }}</td>
                            <td class="py-1.5 text-right">{{ it.cantidad }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Firma de conformidad</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    Declaro haber recibido a mi entera satisfacción los activos
                    descritas.
                </p>
                <PadFirma ref="pad" @cambio="(v: boolean) => (vacio = v)" />
                <p v-if="form.errors.firma" class="text-destructive text-sm">
                    {{ form.errors.firma }}
                </p>
                <div class="flex items-center gap-3">
                    <Button
                        :disabled="vacio || form.processing"
                        @click="confirmar"
                    >
                        Firmar y confirmar recepción
                    </Button>
                    <Button variant="ghost" as-child>
                        <Link :href="`/entregas/${entrega.id}`">Cancelar</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
