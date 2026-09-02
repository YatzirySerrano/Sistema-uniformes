<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PackagePlus } from '@lucide/vue';
import { ref, watch } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Paginado } from '@/types/sistema';

type Saldo = {
    id: number;
    sucursal_id: number;
    activo_id: number;
    talla_id: number;
    sucursal: string;
    activo: string;
    talla: string;
    cantidad: number;
    minimo: number;
    bajo_minimo: boolean;
};

const props = defineProps<{
    saldos: Paginado<Saldo>;
    filtros: {
        sucursal_id?: number;
        activo_id?: number;
        solo_bajo_minimo?: boolean;
    };
    sucursales: { id: number; nombre: string }[];
    activos: { id: number; nombre: string }[];
    permisos: { entrada: boolean; ajustar: boolean; minimos: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventario', href: '/inventario' }] },
});

const sucursalId = ref(props.filtros.sucursal_id ?? '');
const activoId = ref(props.filtros.activo_id ?? '');
const soloBajo = ref(!!props.filtros.solo_bajo_minimo);

watch([sucursalId, activoId, soloBajo], () => {
    router.get(
        '/inventario',
        {
            sucursal_id: sucursalId.value || undefined,
            activo_id: activoId.value || undefined,
            solo_bajo_minimo: soloBajo.value ? 1 : undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
});

const dialogo = ref<'ajuste' | 'minimo' | null>(null);
const actual = ref<Saldo | null>(null);

const ajuste = useForm({
    sucursal_id: 0,
    activo_id: 0,
    talla_id: 0,
    existencia_objetivo: 0,
    motivo: '',
});
const minimo = useForm({
    sucursal_id: 0,
    activo_id: 0,
    talla_id: 0,
    minimo: 0,
});

function abrir(tipo: 'ajuste' | 'minimo', s: Saldo) {
    actual.value = s;
    dialogo.value = tipo;
    if (tipo === 'ajuste') {
        ajuste.defaults({
            sucursal_id: s.sucursal_id,
            activo_id: s.activo_id,
            talla_id: s.talla_id,
            existencia_objetivo: s.cantidad,
            motivo: '',
        });
        ajuste.reset();
    } else {
        minimo.defaults({
            sucursal_id: s.sucursal_id,
            activo_id: s.activo_id,
            talla_id: s.talla_id,
            minimo: s.minimo,
        });
        minimo.reset();
    }
}

function guardarAjuste() {
    ajuste.post('/inventario/ajuste', {
        preserveScroll: true,
        onSuccess: () => (dialogo.value = null),
    });
}
function guardarMinimo() {
    minimo.post('/inventario/minimos', {
        preserveScroll: true,
        onSuccess: () => (dialogo.value = null),
    });
}
</script>

<template>
    <Head title="Inventario" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Inventario"
            descripcion="Existencias por sucursal, activo y talla."
        >
            <template #acciones>
                <Button v-if="permisos.entrada" as-child>
                    <Link href="/inventario/entrada"
                        ><PackagePlus class="size-4" /> Registrar entrada</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="flex flex-wrap items-center gap-2">
            <select
                v-model="sucursalId"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todas las sucursales</option>
                <option v-for="s in sucursales" :key="s.id" :value="s.id">
                    {{ s.nombre }}
                </option>
            </select>
            <select
                v-model="activoId"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">Todos los activos</option>
                <option v-for="p in activos" :key="p.id" :value="p.id">
                    {{ p.nombre }}
                </option>
            </select>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="soloBajo" type="checkbox" class="size-4" />
                Solo bajo mínimo
            </label>
        </div>

        <EstadoVacio
            v-if="!saldos.data.length"
            titulo="Sin existencias"
            descripcion="No hay registros de inventario con los filtros seleccionados."
        />

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Sucursal</th>
                        <th class="px-3 py-2 font-medium">Activo</th>
                        <th class="px-3 py-2 font-medium">Talla</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Existencia
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Mínimo</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in saldos.data" :key="s.id" class="border-t">
                        <td class="px-3 py-2">{{ s.sucursal }}</td>
                        <td class="px-3 py-2">{{ s.activo }}</td>
                        <td class="px-3 py-2">{{ s.talla }}</td>
                        <td class="px-3 py-2 text-right font-medium">
                            {{ s.cantidad }}
                            <Badge
                                v-if="s.bajo_minimo"
                                variant="secondary"
                                class="ml-1 text-amber-600"
                                >mín.</Badge
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2 text-right">
                            {{ s.minimo }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button
                                v-if="permisos.ajustar"
                                class="text-primary text-xs hover:underline"
                                @click="abrir('ajuste', s)"
                            >
                                Ajustar
                            </button>
                            <button
                                v-if="permisos.minimos"
                                class="text-primary ml-3 text-xs hover:underline"
                                @click="abrir('minimo', s)"
                            >
                                Mínimo
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="saldos.links" :total="saldos.total" />

        <Dialog
            :open="dialogo === 'ajuste'"
            @update:open="(v: boolean) => !v && (dialogo = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Ajustar existencia</DialogTitle>
                </DialogHeader>
                <p v-if="actual" class="text-muted-foreground text-sm">
                    {{ actual.activo }} · {{ actual.talla }} ·
                    {{ actual.sucursal }} — existencia actual
                    {{ actual.cantidad }}
                </p>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label>Existencia objetivo</Label>
                        <Input
                            v-model.number="ajuste.existencia_objetivo"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Motivo (obligatorio)</Label>
                        <Input v-model="ajuste.motivo" />
                        <p
                            v-if="ajuste.errors.motivo"
                            class="text-destructive text-xs"
                        >
                            {{ ajuste.errors.motivo }}
                        </p>
                    </div>
                    <Button
                        :disabled="ajuste.processing"
                        @click="guardarAjuste"
                    >
                        Registrar ajuste
                    </Button>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="dialogo === 'minimo'"
            @update:open="(v: boolean) => !v && (dialogo = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Configurar mínimo</DialogTitle>
                </DialogHeader>
                <p v-if="actual" class="text-muted-foreground text-sm">
                    {{ actual.activo }} · {{ actual.talla }} ·
                    {{ actual.sucursal }}
                </p>
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label>Existencia mínima</Label>
                        <Input
                            v-model.number="minimo.minimo"
                            type="number"
                            min="0"
                        />
                    </div>
                    <Button :disabled="minimo.processing" @click="guardarMinimo"
                        >Guardar mínimo</Button
                    >
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
