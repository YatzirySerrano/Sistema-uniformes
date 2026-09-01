<script setup lang="ts">
import { Inbox } from '@lucide/vue';

defineProps<{
    titulo: string;
    descripcion?: string;
}>();
</script>

<template>
    <div
        class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center"
    >
        <div class="bg-muted text-muted-foreground rounded-full p-3">
            <slot name="icono"><Inbox class="size-6" /></slot>
        </div>
        <div class="space-y-1">
            <p class="font-medium">{{ titulo }}</p>
            <p v-if="descripcion" class="text-muted-foreground text-sm">
                {{ descripcion }}
            </p>
        </div>
        <div class="mt-1 flex flex-wrap items-center justify-center gap-2">
            <slot name="acciones" />
        </div>
    </div>
</template>
