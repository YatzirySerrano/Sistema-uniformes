<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Botón uniforme para "editar" un registro: outline + icono Pencil + texto
 * "Editar" — mismo tamaño/hover/focus que `BotonVer.vue` en todos los
 * listados. Con `href` navega como `<Link>`; sin `href`, es un `<button>`
 * normal para el consumidor que abre un formulario/diálogo por JS.
 */
withDefaults(
    defineProps<{
        href?: string;
        etiqueta?: string;
    }>(),
    { etiqueta: 'Editar' },
);
</script>

<template>
    <Button v-if="href" variant="outline" size="sm" as-child>
        <Link :href="href"> <Pencil class="size-3.5" /> {{ etiqueta }} </Link>
    </Button>
    <Button v-else type="button" variant="outline" size="sm">
        <Pencil class="size-3.5" /> {{ etiqueta }}
    </Button>
</template>
