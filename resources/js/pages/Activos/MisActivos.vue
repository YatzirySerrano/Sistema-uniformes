<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MapPin, Package, UserRound } from '@lucide/vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import EstadoVacio from '@/components/sistema/EstadoVacio.vue';
import { Badge } from '@/components/ui/badge';

type Bien = {
    tipo: 'cantidad' | 'unidad';
    activo: string | null;
    talla: string | null;
    cantidad: number;
    codigo: string | null;
    marca_modelo: string | null;
    condicion: string | null;
    finalidad: 'uso_personal' | 'redistribucion' | null;
    finalidad_etiqueta: string;
};

type Conjunto = {
    conjunto_id: number;
    nombre: string;
    completos: number;
    componentes: {
        activo_id: number;
        activo: string;
        talla: string | null;
        requerido: number;
        disponible: number;
    }[];
};

/**
 * Lo que el colaborador vinculado a esta cuenta tiene HOY bajo custodia,
 * separado por finalidad. Sólo lectura: nunca muestra inventario global ni
 * custodia de otras personas (lo garantiza el backend).
 */
defineProps<{
    colaborador: {
        nombre_completo: string;
        numero_empleado: string;
        empresa: string | null;
        sucursal: string | null;
        servicio: string | null;
    } | null;
    personales: Bien[];
    redistribuir: Bien[];
    sinClasificar: Bien[];
    conjuntos: Conjunto[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mis activos', href: '/mis-activos' }],
    },
});

function detalle(b: Bien): string {
    if (b.tipo === 'unidad') {
        return [b.codigo, b.marca_modelo, b.condicion]
            .filter(Boolean)
            .join(' · ');
    }
    return `${b.talla ? `Talla ${b.talla} · ` : ''}Cantidad: ${b.cantidad}`;
}
</script>

<template>
    <Head title="Mis activos" />

    <div class="flex w-full flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Mis activos"
            descripcion="Bienes que hoy están bajo tu custodia: los que usas tú, los que recibiste para entregar a otras personas y, aparte, los históricos sin clasificar."
        />

        <EstadoVacio
            v-if="!colaborador"
            titulo="Tu cuenta no está vinculada a una ficha de colaborador."
            descripcion="Pide a un administrador que vincule tu cuenta desde la ficha del colaborador para ver tu custodia."
        />

        <template v-else>
            <section class="rounded-xl border p-4 text-sm">
                <p class="flex items-center gap-2 font-medium">
                    <UserRound class="text-muted-foreground size-4" />
                    {{ colaborador.nombre_completo }}
                    <span class="text-muted-foreground font-normal"
                        >· N.º {{ colaborador.numero_empleado }}</span
                    >
                </p>
                <p class="text-muted-foreground mt-1 flex items-center gap-2">
                    <MapPin class="size-4" />
                    {{ colaborador.empresa ?? '—' }} ·
                    {{ colaborador.sucursal ?? '—' }} ·
                    {{ colaborador.servicio ?? 'Sin servicio asignado' }}
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-semibold">Uso personal</h2>
                <EstadoVacio
                    v-if="!personales.length"
                    titulo="No tienes bienes de uso personal bajo custodia."
                />
                <ul v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="(b, i) in personales"
                        :key="`p-${i}`"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p
                                class="flex min-w-0 items-center gap-2 font-medium"
                            >
                                <Package
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                <span class="truncate">{{ b.activo }}</span>
                            </p>
                            <Badge
                                variant="secondary"
                                class="shrink-0 text-xs"
                                >{{ b.finalidad_etiqueta }}</Badge
                            >
                        </div>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ detalle(b) }}
                        </p>
                    </li>
                </ul>
            </section>

            <section v-if="sinClasificar.length" class="space-y-3">
                <h2 class="text-sm font-semibold">Sin clasificar</h2>
                <p class="text-muted-foreground text-xs">
                    Bienes recibidos antes de que se registrara la finalidad.
                    Siguen bajo tu custodia y no se ofrecen para redistribuir
                    hasta que un administrador los clasifique.
                </p>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="(b, i) in sinClasificar"
                        :key="`s-${i}`"
                        class="rounded-lg border border-dashed p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p
                                class="flex min-w-0 items-center gap-2 font-medium"
                            >
                                <Package
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                <span class="truncate">{{ b.activo }}</span>
                            </p>
                            <Badge variant="outline" class="shrink-0 text-xs">{{
                                b.finalidad_etiqueta
                            }}</Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ detalle(b) }}
                        </p>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-semibold">Para redistribuir</h2>
                <EstadoVacio
                    v-if="!redistribuir.length && !conjuntos.length"
                    titulo="No tienes bienes para redistribuir."
                />
                <ul
                    v-if="redistribuir.length"
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <li
                        v-for="(b, i) in redistribuir"
                        :key="`r-${i}`"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p
                                class="flex min-w-0 items-center gap-2 font-medium"
                            >
                                <Package
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                <span class="truncate">{{ b.activo }}</span>
                            </p>
                            <Badge variant="warning" class="shrink-0 text-xs">{{
                                b.finalidad_etiqueta
                            }}</Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ detalle(b) }}
                        </p>
                    </li>
                </ul>
                <ul
                    v-if="conjuntos.length"
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <li
                        v-for="c in conjuntos"
                        :key="c.conjunto_id"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-medium">{{ c.nombre }}</p>
                            <Badge
                                :variant="
                                    c.completos > 0 ? 'success' : 'outline'
                                "
                                class="shrink-0 text-xs"
                                >{{
                                    c.completos > 0
                                        ? `${c.completos} completo(s)`
                                        : 'Incompleto'
                                }}</Badge
                            >
                        </div>
                        <ul
                            class="text-muted-foreground mt-1 space-y-0.5 text-xs"
                        >
                            <li
                                v-for="comp in c.componentes"
                                :key="`${comp.activo_id}-${comp.talla ?? ''}`"
                            >
                                {{ comp.activo
                                }}{{ comp.talla ? ` ${comp.talla}` : '' }}:
                                {{ comp.disponible }} / {{ comp.requerido }} por
                                conjunto
                            </li>
                        </ul>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
