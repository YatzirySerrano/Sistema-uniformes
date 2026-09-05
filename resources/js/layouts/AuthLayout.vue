<script setup lang="ts">
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';

const {
    title = '',
    description = '',
    variant = 'simple',
} = defineProps<{
    title?: string;
    description?: string;
    /** `split`: layout de dos mitades para Login (desktop). El resto de las
     * pantallas de autenticación se mantiene centrado (`simple`). */
    variant?: 'simple' | 'split';
}>();
</script>

<template>
    <component
        :is="variant === 'split' ? AuthSplitLayout : AuthSimpleLayout"
        :title="title"
        :description="description"
    >
        <slot />
    </component>
</template>
