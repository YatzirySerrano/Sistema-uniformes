<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ChevronDown,
    Layers,
    Settings2,
    SlidersHorizontal,
    Wrench,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Acción única por fila/card de existencia ("Gestionar ▾"), en vez de varios
 * botones sueltos: agrupa las operaciones contextuales sobre ESA existencia
 * concreta (empresa + almacén + activo + variante ya conocidos por la fila).
 * Mismo componente en "Existencias globales" (`Inventario/Index.vue`) y en
 * "Existencias por almacén" del detalle del activo (`Activos/Detalle.vue`)
 * para que ambas pantallas se vean y se usen igual.
 *
 * "Configurar mínimo" cambia de comportamiento según dónde se use: si llega
 * `activoHref`, navega al detalle del activo (Existencias globales no tiene
 * su propio diálogo de mínimo por renglón); si no, emite el evento para que
 * la propia pantalla abra su diálogo (detalle del activo). Sin permiso de
 * mínimos pero con `activoHref`, el enlace se ofrece igual como "Ver
 * desglose" — nunca desaparece el único acceso a más detalle del activo.
 */
const props = defineProps<{
    puedeAjustar: boolean;
    puedeMinimos: boolean;
    activoHref?: string;
}>();

const emit = defineEmits<{
    'corregir-existencia': [];
    'cambiar-condicion': [];
    'configurar-minimo': [];
}>();
</script>

<template>
    <DropdownMenu v-if="puedeAjustar || puedeMinimos || activoHref">
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm">
                <Wrench class="size-3.5" />
                Gestionar
                <ChevronDown class="size-3.5 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuItem
                v-if="puedeAjustar"
                class="cursor-pointer"
                @select="emit('corregir-existencia')"
            >
                <SlidersHorizontal class="size-3.5" />
                Corregir existencia
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="puedeAjustar"
                class="cursor-pointer"
                @select="emit('cambiar-condicion')"
            >
                <AlertTriangle class="size-3.5" />
                Cambiar condición
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="puedeMinimos && !activoHref"
                class="cursor-pointer"
                @select="emit('configurar-minimo')"
            >
                <Settings2 class="size-3.5" />
                Configurar mínimo
            </DropdownMenuItem>
            <DropdownMenuItem v-if="activoHref" as-child class="cursor-pointer">
                <Link :href="activoHref" class="flex w-full items-center gap-2">
                    <Settings2 v-if="puedeMinimos" class="size-3.5" />
                    <Layers v-else class="size-3.5" />
                    {{ puedeMinimos ? 'Configurar mínimo' : 'Ver desglose' }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
