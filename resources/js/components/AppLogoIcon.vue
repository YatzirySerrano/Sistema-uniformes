<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!--
        Icono neutro TEMPORAL del sistema (caja + check: "control y gestión de
        activos"). Sustituir por el logo/isotipo definitivo de
        Sistema-uniformes cuando exista uno — ver .ai/rules/ux.md.
    -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M6 3h12a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Zm1 9.5 3 3 7.5-8-1.5-1.5-6 6.5-1.5-1.5L7 12.5Z"
        />
    </svg>
</template>
