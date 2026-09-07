<script setup lang="ts">
defineProps<{
    titulo: string;
    descripcion?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="space-y-1" data-tour="titulo-pagina">
            <h1 class="text-xl font-semibold tracking-tight">{{ titulo }}</h1>
            <p v-if="descripcion" class="text-muted-foreground text-sm">
                {{ descripcion }}
            </p>
        </div>
        <div
            class="flex flex-wrap items-center gap-2"
            data-tour="acciones-pagina"
        >
            <slot name="acciones" />
        </div>
    </div>
</template>
