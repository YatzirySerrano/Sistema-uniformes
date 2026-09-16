<script setup lang="ts">
import { HelpCircle } from '@lucide/vue';
import { ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const props = withDefaults(
    defineProps<{
        /** Texto de ayuda que se muestra dentro del tooltip. */
        texto: string;
        /** Etiqueta accesible del botón (se lee con lector de pantalla). */
        etiqueta?: string;
    }>(),
    { etiqueta: undefined },
);

// reka-ui's Tooltip sólo abre con hover real de mouse o foco de teclado: su
// detección de "hover" ignora explícitamente los punteros táctiles, y su
// propio manejador de click SÓLO cierra, nunca abre — en móvil/tablet, tocar
// el icono no hacía nada. Se controla `open` explícitamente y se alterna con
// @click (mouse Y touch disparan el mismo evento click), sin quitarle el
// hover al mouse ni el foco/Enter/Espacio al teclado (esos siguen abriendo
// solos, sin pasar por este ref). `disable-closing-trigger` evita que el
// click-para-abrir compita con el cierre-por-click nativo del trigger; el
// cierre al tocar/hacer click FUERA sigue funcionando (lo maneja el
// contenido, no el trigger).
const abierto = ref(false);
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip v-model:open="abierto" :disable-closing-trigger="true">
            <TooltipTrigger
                type="button"
                :aria-label="props.etiqueta ?? 'Más información'"
                class="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex size-4 items-center justify-center rounded-full transition-colors focus-visible:ring-2 focus-visible:outline-none"
                @click.stop.prevent="abierto = !abierto"
            >
                <HelpCircle class="size-3.5" aria-hidden="true" />
            </TooltipTrigger>
            <TooltipContent class="max-w-[16rem] text-pretty">
                {{ props.texto }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
