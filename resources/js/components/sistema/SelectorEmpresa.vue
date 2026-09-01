<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermisos } from '@/composables/usePermisos';

const { contexto } = usePermisos();

const empresas = computed(() => contexto.value?.empresasDisponibles ?? []);
const activaId = computed(() => contexto.value?.empresaActivaId ?? null);
const activa = computed(() =>
    empresas.value.find((e) => e.id === activaId.value),
);

function cambiar(id: number) {
    if (id === activaId.value) return;
    router.post(
        '/empresa-activa',
        { empresa_id: id },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div v-if="empresas.length" class="px-2 py-1.5">
        <DropdownMenu>
            <DropdownMenuTrigger
                class="hover:bg-sidebar-accent flex w-full items-center gap-2 rounded-md border px-2.5 py-2 text-left text-sm transition-colors"
                :disabled="empresas.length < 2"
            >
                <Building2 class="size-4 shrink-0 opacity-70" />
                <span class="min-w-0 flex-1">
                    <span
                        class="text-muted-foreground block text-[10px] uppercase"
                        >Empresa activa</span
                    >
                    <span class="block truncate font-medium">{{
                        activa?.nombre_comercial ?? 'Sin empresa'
                    }}</span>
                </span>
                <ChevronsUpDown
                    v-if="empresas.length > 1"
                    class="size-4 shrink-0 opacity-50"
                />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-64">
                <DropdownMenuLabel>Cambiar de empresa</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="empresa in empresas"
                    :key="empresa.id"
                    class="flex items-center justify-between"
                    @select="cambiar(empresa.id)"
                >
                    <span class="min-w-0 truncate">{{
                        empresa.nombre_comercial
                    }}</span>
                    <Check
                        v-if="empresa.id === activaId"
                        class="size-4 shrink-0"
                    />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
