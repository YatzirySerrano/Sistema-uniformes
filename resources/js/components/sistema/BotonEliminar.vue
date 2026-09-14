<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Botón uniforme para una acción DESTRUCTIVA real (elimina/archiva el
 * registro): outline en rojo + icono Trash2 + texto "Eliminar" — mismo
 * tamaño/hover/focus que `BotonVer.vue`/`BotonEditar.vue`. El `@click` del
 * consumidor cae de forma natural al `<Button>` raíz (fallthrough de Vue);
 * normalmente abre un diálogo de confirmación antes de enviar la petición.
 *
 * Úsalo SÓLO para lo que semánticamente elimina/archiva. Si la acción es
 * Desactivar / Restaurar / Dar de baja / Cancelar / Anular, usa un botón
 * normal con su propio texto — no lo renombres como "Eliminar".
 */
withDefaults(
    defineProps<{
        etiqueta?: string;
        disabled?: boolean;
    }>(),
    { etiqueta: 'Eliminar' },
);
</script>

<template>
    <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="disabled"
        class="border-destructive/40 text-destructive hover:border-destructive/60 hover:bg-destructive/10 focus-visible:ring-destructive/40 dark:hover:bg-destructive/10"
    >
        <Trash2 class="size-3.5" /> {{ etiqueta }}
    </Button>
</template>
