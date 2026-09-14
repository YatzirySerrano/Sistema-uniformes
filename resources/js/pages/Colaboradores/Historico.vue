<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Briefcase, Building2, Truck, Undo2 } from '@lucide/vue';
import BotonVer from '@/components/sistema/BotonVer.vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Badge } from '@/components/ui/badge';
import { fechaHora } from '@/lib/fecha';

type ServicioPeriodo = {
    servicio: string;
    contrato: string | null;
    ocurrido_en: string;
};

type ReferenciaPeriodo = { id: number; folio: string; fecha: string };

type Periodo = {
    origen: 'inicial' | 'bitacora' | 'estructurado';
    actual: boolean;
    empresa_id: number | null;
    empresa: string;
    sucursal: string | null;
    area: string | null;
    numero_empleado: string | null;
    fecha_inicio: string | null;
    fecha_inicio_es_alta_registro: boolean;
    fecha_fin: string | null;
    servicios: ServicioPeriodo[];
    entregas: ReferenciaPeriodo[];
    devoluciones: ReferenciaPeriodo[];
};

defineProps<{
    colaborador: {
        id: number;
        nombre_completo: string;
        numero_empleado: string;
        empresa_actual: string | null;
    };
    periodos: Periodo[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Perfil', href: '#' },
            { title: 'Histórico laboral', href: '#' },
        ],
    },
});

function fecha(iso: string | null): string {
    if (!iso) return '—';
    return fechaHora(iso, { dateStyle: 'medium', timeStyle: undefined });
}

/** Fechas de negocio ("YYYY-MM-DD", sin hora): se pintan tal cual, sin pasar
 * por conversión de zona horaria (evita que la resta de horas corra el día). */
function fechaSimple(fechaIso: string): string {
    const [anio, mes, dia] = fechaIso.split('-');
    return `${dia}/${mes}/${anio}`;
}
</script>

<template>
    <Head :title="`Histórico laboral — ${colaborador.nombre_completo}`" />

    <div class="flex flex-col gap-4 p-4">
        <Link
            :href="`/colaboradores/${colaborador.id}`"
            class="text-muted-foreground hover:text-foreground flex w-fit items-center gap-1 text-sm"
        >
            <ArrowLeft class="size-4" /> Volver al perfil
        </Link>

        <EncabezadoPagina
            titulo="Histórico laboral"
            :descripcion="`Empresas por las que ha pasado ${colaborador.nombre_completo} (N.º ${colaborador.numero_empleado}), en orden cronológico. Sólo se muestran fechas y datos que realmente existen registrados.`"
        />

        <EstadoVacio
            v-if="!periodos.length"
            titulo="Sin periodos registrados"
            descripcion="Todavía no hay información suficiente para construir el histórico."
        />

        <div v-else class="flex flex-col gap-3">
            <details
                v-for="(periodo, i) in periodos"
                :key="i"
                class="group rounded-xl border"
                :open="periodo.actual"
            >
                <summary
                    class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 rounded-xl p-4 select-none"
                >
                    <div class="flex items-center gap-2">
                        <Building2 class="text-muted-foreground size-4" />
                        <span class="font-semibold">{{ periodo.empresa }}</span>
                        <Badge v-if="periodo.actual" variant="default"
                            >Actual</Badge
                        >
                        <Badge v-else variant="outline">Histórica</Badge>
                        <Badge
                            v-if="periodo.origen === 'bitacora'"
                            variant="outline"
                            class="text-muted-foreground"
                            title="Reconstruido a partir de la bitácora de auditoría, anterior al histórico estructurado."
                        >
                            Reconstruido de bitácora
                        </Badge>
                    </div>
                    <span class="text-muted-foreground text-sm">
                        {{
                            periodo.fecha_inicio_es_alta_registro
                                ? 'Alta del registro'
                                : fecha(periodo.fecha_inicio)
                        }}
                        →
                        {{
                            periodo.actual ? 'Actual' : fecha(periodo.fecha_fin)
                        }}
                    </span>
                </summary>

                <div class="border-t p-4">
                    <div
                        class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div>
                            <p class="text-muted-foreground text-xs">
                                Número de empleado
                            </p>
                            <p class="font-medium">
                                {{ periodo.numero_empleado ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-muted-foreground text-xs">
                                Sucursal
                            </p>
                            <p class="font-medium">
                                {{ periodo.sucursal ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-muted-foreground text-xs">
                                Área / departamento
                            </p>
                            <p class="font-medium">{{ periodo.area ?? '—' }}</p>
                        </div>
                        <div v-if="periodo.empresa_id === null">
                            <p class="text-muted-foreground text-xs">
                                Empresa no vinculada
                            </p>
                            <p class="text-xs">
                                No se pudo asociar este periodo a una empresa
                                actual; los detalles de servicios, entregas y
                                devoluciones no están disponibles para él.
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="periodo.servicios.length"
                        class="mt-4 border-t pt-3"
                    >
                        <p
                            class="text-muted-foreground mb-2 flex items-center gap-1 text-xs"
                        >
                            <Briefcase class="size-3" /> Servicios asociados
                        </p>
                        <ul class="grid gap-1 sm:grid-cols-2">
                            <li
                                v-for="(s, j) in periodo.servicios"
                                :key="j"
                                class="flex items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-xs"
                            >
                                <span
                                    >{{ s.contrato ? `${s.contrato} — ` : ''
                                    }}{{ s.servicio }}</span
                                >
                                <span class="text-muted-foreground">{{
                                    fecha(s.ocurrido_en)
                                }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="
                            periodo.entregas.length ||
                            periodo.devoluciones.length
                        "
                        class="mt-4 grid gap-4 border-t pt-3 sm:grid-cols-2"
                    >
                        <div v-if="periodo.entregas.length">
                            <p
                                class="text-muted-foreground mb-2 flex items-center gap-1 text-xs"
                            >
                                <Truck class="size-3" /> Entregas en esta
                                empresa ({{ periodo.entregas.length }})
                            </p>
                            <ul class="flex flex-col gap-1">
                                <li
                                    v-for="e in periodo.entregas"
                                    :key="e.id"
                                    class="flex items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-xs"
                                >
                                    <span
                                        >{{ e.folio }} ·
                                        {{ fechaSimple(e.fecha) }}</span
                                    >
                                    <BotonVer :href="`/entregas/${e.id}`" />
                                </li>
                            </ul>
                        </div>
                        <div v-if="periodo.devoluciones.length">
                            <p
                                class="text-muted-foreground mb-2 flex items-center gap-1 text-xs"
                            >
                                <Undo2 class="size-3" /> Devoluciones en esta
                                empresa ({{ periodo.devoluciones.length }})
                            </p>
                            <ul class="flex flex-col gap-1">
                                <li
                                    v-for="d in periodo.devoluciones"
                                    :key="d.id"
                                    class="flex items-center justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-xs"
                                >
                                    <span
                                        >{{ d.folio }} ·
                                        {{ fechaSimple(d.fecha) }}</span
                                    >
                                    <BotonVer :href="`/devoluciones/${d.id}`" />
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </details>
        </div>
    </div>
</template>
