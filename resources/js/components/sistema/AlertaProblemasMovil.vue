<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Alerta de "no se puede continuar" para móvil/tablet: el recuadro amarillo
 * de siempre (`.ai/rules` / diseño de Entregas y Devoluciones) puede quedar
 * fuera del viewport cuando hay muchos renglones, así que el usuario pulsa
 * "Continuar" y no entiende por qué no avanza. Este Dialog aparece SÓLO al
 * intentar continuar con errores (nunca mientras escribe) y sólo lo abre el
 * padre en pantallas angostas — el recuadro amarillo original se conserva
 * siempre, en desktop y en móvil.
 */
defineProps<{
    open: boolean;
    problemas: string[];
}>();

const emit = defineEmits<{
    'update:open': [boolean];
    'ir-al-problema': [];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <AlertTriangle class="size-5 shrink-0 text-amber-500" />
                    Revisa lo siguiente antes de continuar
                </DialogTitle>
                <DialogDescription>
                    Hay
                    {{ problemas.length }}
                    {{ problemas.length === 1 ? 'problema' : 'problemas' }}
                    que debes corregir para poder continuar.
                </DialogDescription>
            </DialogHeader>
            <ul class="max-h-64 space-y-1.5 overflow-y-auto text-sm">
                <li
                    v-for="(p, i) in problemas"
                    :key="i"
                    class="rounded-md bg-amber-500/10 p-2 text-amber-900 dark:text-amber-300"
                >
                    {{ p }}
                </li>
            </ul>
            <DialogFooter class="sm:flex-col-reverse sm:gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    @click="emit('update:open', false)"
                >
                    Cerrar
                </Button>
                <Button type="button" @click="emit('ir-al-problema')">
                    Ir al primer problema
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
