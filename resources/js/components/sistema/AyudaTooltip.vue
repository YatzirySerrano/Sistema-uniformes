<script setup lang="ts">
import { HelpCircle } from '@lucide/vue';
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
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger
                type="button"
                :aria-label="props.etiqueta ?? 'Más información'"
                class="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex size-4 items-center justify-center rounded-full transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <HelpCircle class="size-3.5" aria-hidden="true" />
            </TooltipTrigger>
            <TooltipContent class="max-w-[16rem] text-pretty">
                {{ props.texto }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
