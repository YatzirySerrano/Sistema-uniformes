<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2 } from '@lucide/vue';
import { reactive } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Button } from '@/components/ui/button';

type Detalle = {
    activo: string | null;
    talla: string | null;
    cantidad: number;
    minimo: number;
};
type Pendiente = {
    sucursal_id: number;
    sucursal: string | null;
    codigo: string | null;
    filas: number;
    unidades: number;
    detalle: Detalle[];
};
type Almacen = {
    id: number;
    nombre: string;
    codigo: string | null;
    sucursales_abastecidas: number[];
};

const props = defineProps<{
    pendientes: Pendiente[];
    almacenes: Almacen[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventario', href: '/inventario' },
            {
                title: 'Migración de existencias',
                href: '/inventario/migracion',
            },
        ],
    },
});

const seleccion = reactive<Record<number, number | ''>>(
    Object.fromEntries(props.pendientes.map((p) => [p.sucursal_id, ''])),
);
const enviando = reactive<Record<number, boolean>>({});

function almacenesPara(sucursalId: number) {
    // Se ofrecen todos los almacenes de la empresa, marcando los que ya
    // abastecen esa sucursal como recomendados.
    return [...props.almacenes].sort((a, b) => {
        const aAbastece = a.sucursales_abastecidas.includes(sucursalId) ? 0 : 1;
        const bAbastece = b.sucursales_abastecidas.includes(sucursalId) ? 0 : 1;
        return aAbastece - bAbastece || a.nombre.localeCompare(b.nombre);
    });
}

function asignar(p: Pendiente) {
    const almacenId = seleccion[p.sucursal_id];
    if (!almacenId) return;
    enviando[p.sucursal_id] = true;
    router.post(
        '/inventario/migracion/resolver',
        { sucursal_id: p.sucursal_id, almacen_id: almacenId },
        {
            preserveScroll: true,
            onFinish: () => (enviando[p.sucursal_id] = false),
        },
    );
}
</script>

<template>
    <Head title="Migración de existencias" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Migración de existencias (sucursal → almacén)"
            descripcion="El inventario dejó de vivir en la sucursal. Asigna a un almacén las existencias legacy que aún no tienen uno. No se duplican saldos y la operación es idempotente."
        />

        <EstadoVacio
            v-if="!pendientes.length"
            titulo="Todo migrado"
            descripcion="No quedan existencias asociadas a una sucursal. El inventario ya vive por completo en los almacenes."
        >
            <template #icono>
                <CheckCircle2 class="size-8 text-emerald-500" />
            </template>
        </EstadoVacio>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="p in pendientes"
                :key="p.sucursal_id"
                class="flex min-w-0 flex-col gap-3 rounded-xl border p-4"
            >
                <header class="min-w-0">
                    <h2 class="truncate font-semibold">
                        {{ p.sucursal ?? 'Sucursal #' + p.sucursal_id }}
                        <span
                            v-if="p.codigo"
                            class="text-muted-foreground font-normal"
                            >· {{ p.codigo }}</span
                        >
                    </h2>
                    <p class="text-muted-foreground text-sm">
                        {{ p.filas }} variante(s) · {{ p.unidades }} unidad(es)
                    </p>
                </header>

                <div class="max-h-40 overflow-y-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr
                                v-for="(d, i) in p.detalle"
                                :key="i"
                                class="border-b last:border-0"
                            >
                                <td class="px-3 py-1.5">{{ d.activo }}</td>
                                <td class="text-muted-foreground px-3 py-1.5">
                                    {{ d.talla }}
                                </td>
                                <td class="px-3 py-1.5 text-right font-medium">
                                    {{ d.cantidad }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-model="seleccion[p.sucursal_id]"
                        class="border-input bg-background h-9 min-w-0 flex-1 rounded-md border px-3 text-sm"
                    >
                        <option value="">
                            Selecciona el almacén de destino…
                        </option>
                        <option
                            v-for="a in almacenesPara(p.sucursal_id)"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.nombre
                            }}{{
                                a.sucursales_abastecidas.includes(p.sucursal_id)
                                    ? ' (abastece esta sucursal)'
                                    : ''
                            }}
                        </option>
                    </select>
                    <Button
                        size="sm"
                        :disabled="
                            !seleccion[p.sucursal_id] || enviando[p.sucursal_id]
                        "
                        @click="asignar(p)"
                    >
                        Asignar
                    </Button>
                </div>

                <p v-if="!almacenes.length" class="text-destructive text-xs">
                    No hay almacenes activos en esta empresa. Crea uno en el
                    módulo Almacenes antes de migrar.
                </p>
            </article>
        </div>
    </div>
</template>
