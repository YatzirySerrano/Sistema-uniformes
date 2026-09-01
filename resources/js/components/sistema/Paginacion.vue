<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
    total?: number;
}>();
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap items-center justify-between gap-2 pt-2"
        aria-label="Paginación"
    >
        <p v-if="total !== undefined" class="text-muted-foreground text-xs">
            {{ total }} registro(s)
        </p>
        <div class="flex flex-wrap gap-1">
            <template v-for="(link, i) in links" :key="i">
                <span
                    v-if="!link.url"
                    class="text-muted-foreground rounded-md px-3 py-1.5 text-sm"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'hover:bg-accent'
                    "
                    preserve-scroll
                    v-html="link.label"
                />
            </template>
        </div>
    </nav>
</template>
