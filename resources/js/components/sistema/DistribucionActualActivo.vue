<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MapPinned } from '@lucide/vue';
import { computed } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import { Badge } from '@/components/ui/badge';
import { varianteBadgeFinalidad } from '@/lib/finalidadCustodia';

type Grupo = 'almacen' | 'uso_personal' | 'redistribucion' | 'sin_clasificar';

type Custodio = {
    id: number;
    nombre_completo: string;
    numero_empleado: string | null;
};

export type DistribucionActivo = {
    totales: Record<Grupo, number>;
    filas: {
        grupo: Grupo;
        grupo_etiqueta: string;
        almacen: string | null;
        custodio: Custodio | null;
        talla: string | null;
        cantidad: number;
    }[];
    unidades: {
        codigo: string;
        public_token: string;
        grupo: Grupo;
        grupo_etiqueta: string;
        custodio: Custodio | null;
        almacen: string | null;
        condicion: string;
        finalidad_etiqueta: string | null;
    }[];
    unidades_total: number;
};

/**
 * "Distribución actual del activo": dónde está hoy cada pieza — en almacén o
 * bajo la custodia de alguien, con su finalidad. Todo lo calcula el backend
 * (`App\Servicios\ServicioDistribucionActivo`); aquí sólo se presenta, sin
 * volver a agrupar ni mezclar finalidades o custodios.
 */
const props = defineProps<{
    distribucion: DistribucionActivo;
    usaVariantes: boolean;
    esIndividual: boolean;
}>();

const GRUPOS: { clave: Grupo; etiqueta: string }[] = [
    { clave: 'almacen', etiqueta: 'En almacén' },
    { clave: 'uso_personal', etiqueta: 'Uso personal' },
    { clave: 'redistribucion', etiqueta: 'Para redistribuir' },
    { clave: 'sin_clasificar', etiqueta: 'Sin clasificar' },
];

const filasPorGrupo = computed(() =>
    GRUPOS.map((g) => ({
        ...g,
        filas: props.distribucion.filas.filter((f) => f.grupo === g.clave),
    })).filter((g) => g.filas.length > 0),
);

function varianteGrupo(grupo: Grupo) {
    return grupo === 'almacen'
        ? 'success'
        : varianteBadgeFinalidad(grupo === 'sin_clasificar' ? null : grupo);
}

function nombreCustodio(c: Custodio): string {
    return c.numero_empleado
        ? `${c.nombre_completo} (${c.numero_empleado})`
        : c.nombre_completo;
}

const sinPiezas = computed(
    () =>
        props.distribucion.filas.length === 0 &&
        props.distribucion.unidades.length === 0,
);
</script>

<template>
    <section
        id="distribucion-actual"
        class="min-w-0 rounded-xl border p-4"
        aria-labelledby="titulo-distribucion"
    >
        <h2
            id="titulo-distribucion"
            class="mb-1 flex items-center gap-2 text-sm font-semibold"
        >
            <MapPinned class="text-muted-foreground size-4" />
            Distribución actual del activo
            <AyudaTooltip
                texto="En almacén: existencia lista en cada almacén. Uso personal: asignado para uso directo de la persona. Para redistribuir: lo tiene alguien para entregarlo a otras personas. Sin clasificar: entregado antes de registrar la finalidad (no se ofrece para redistribuir)."
                etiqueta="Ayuda sobre la distribución actual"
            />
        </h2>
        <p class="text-muted-foreground mb-3 text-xs">
            Dónde está hoy cada pieza: en qué almacén o bajo la custodia de
            quién, y con qué finalidad.
        </p>

        <dl class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div
                v-for="g in GRUPOS"
                :key="g.clave"
                class="bg-muted/40 rounded-lg p-3 text-center"
            >
                <dd class="text-2xl font-semibold">
                    {{ distribucion.totales[g.clave] }}
                </dd>
                <dt class="text-muted-foreground text-xs">{{ g.etiqueta }}</dt>
            </div>
        </dl>

        <p v-if="sinPiezas" class="text-muted-foreground text-sm">
            Este activo no tiene piezas en almacén ni bajo custodia.
        </p>

        <!-- Por cantidad: una fila por almacén/custodio + variante + finalidad. -->
        <div v-if="!esIndividual && filasPorGrupo.length" class="grid gap-3">
            <div
                v-for="g in filasPorGrupo"
                :key="g.clave"
                class="rounded-lg border p-3"
            >
                <p class="mb-2 flex items-center gap-2 text-sm font-medium">
                    <Badge :variant="varianteGrupo(g.clave)" class="text-xs">{{
                        g.etiqueta
                    }}</Badge>
                </p>
                <ul class="grid gap-1 text-sm">
                    <li
                        v-for="(f, i) in g.filas"
                        :key="`${g.clave}-${i}`"
                        class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5"
                    >
                        <span class="min-w-0">
                            <Link
                                v-if="f.custodio"
                                :href="`/colaboradores/${f.custodio.id}`"
                                class="hover:underline"
                                >{{ nombreCustodio(f.custodio) }}</Link
                            >
                            <span v-else>{{ f.almacen }}</span>
                            <span
                                v-if="usaVariantes"
                                class="text-muted-foreground"
                            >
                                · {{ f.talla ?? 'Sin variante' }}</span
                            >
                        </span>
                        <span class="font-medium">{{ f.cantidad }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Seguimiento individual: una fila por unidad. -->
        <template v-if="esIndividual && distribucion.unidades.length">
            <ul class="grid gap-2 sm:hidden">
                <li
                    v-for="u in distribucion.unidades"
                    :key="u.public_token"
                    class="rounded-lg border p-3 text-sm"
                >
                    <div class="flex items-center justify-between gap-2">
                        <Link
                            :href="`/activos/unidades/${u.public_token}`"
                            class="font-mono text-xs hover:underline"
                            >{{ u.codigo }}</Link
                        >
                        <Badge
                            :variant="varianteGrupo(u.grupo)"
                            class="text-xs"
                            >{{ u.grupo_etiqueta }}</Badge
                        >
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">
                        <template v-if="u.custodio"
                            >{{ nombreCustodio(u.custodio) }} ·
                        </template>
                        <template v-if="u.almacen">{{ u.almacen }} · </template>
                        {{ u.condicion }}
                    </p>
                </li>
            </ul>
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="py-1.5">Código</th>
                            <th class="py-1.5">Estado</th>
                            <th class="py-1.5">Custodio</th>
                            <th class="py-1.5">Almacén</th>
                            <th class="py-1.5">Condición</th>
                            <th class="py-1.5">Finalidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="u in distribucion.unidades"
                            :key="u.public_token"
                            class="border-t"
                        >
                            <td class="py-1.5">
                                <Link
                                    :href="`/activos/unidades/${u.public_token}`"
                                    class="font-mono text-xs hover:underline"
                                    >{{ u.codigo }}</Link
                                >
                            </td>
                            <td class="py-1.5">
                                <Badge
                                    :variant="varianteGrupo(u.grupo)"
                                    class="text-xs"
                                    >{{ u.grupo_etiqueta }}</Badge
                                >
                            </td>
                            <td class="py-1.5">
                                <Link
                                    v-if="u.custodio"
                                    :href="`/colaboradores/${u.custodio.id}`"
                                    class="hover:underline"
                                    >{{ nombreCustodio(u.custodio) }}</Link
                                >
                                <span v-else class="text-muted-foreground"
                                    >No asignada</span
                                >
                            </td>
                            <td class="py-1.5">{{ u.almacen ?? '—' }}</td>
                            <td class="py-1.5">{{ u.condicion }}</td>
                            <td class="py-1.5">
                                {{ u.finalidad_etiqueta ?? 'No aplica' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="
                    distribucion.unidades_total > distribucion.unidades.length
                "
                class="text-muted-foreground mt-2 text-xs"
            >
                Se muestran {{ distribucion.unidades.length }} de
                {{ distribucion.unidades_total }} unidades. Consulta el listado
                completo en Unidades identificadas.
            </p>
        </template>
    </section>
</template>
