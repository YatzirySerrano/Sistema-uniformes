<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Eye } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Botón uniforme para "ir al detalle" de un registro: outline blanco +
 * icono Eye + texto "Ver" — mismo tamaño/hover/focus en todos los listados
 * (Colaboradores, Sucursales, Entregas, Devoluciones, Activos, Contratos,
 * Servicios…). Úsalo SOLO donde exista una acción real de detalle; no crees
 * una página de detalle sólo para tener este botón.
 *
 * Con `href` navega como `<Link>`. Sin `href`, es un `<button>` normal para
 * páginas que navegan por JS (p. ej. una card completa ya clicable con su
 * propio manejador) — el consumidor le agrega `@click`.
 */
withDefaults(
    defineProps<{
        href?: string;
        etiqueta?: string;
    }>(),
    { etiqueta: 'Ver' },
);
</script>

<template>
    <Button v-if="href" variant="outline" size="sm" as-child>
        <Link :href="href"> <Eye class="size-3.5" /> {{ etiqueta }} </Link>
    </Button>
    <Button v-else type="button" variant="outline" size="sm">
        <Eye class="size-3.5" /> {{ etiqueta }}
    </Button>
</template>
