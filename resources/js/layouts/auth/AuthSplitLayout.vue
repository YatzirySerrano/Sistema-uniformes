<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ClipboardList, Package, QrCode, ShieldCheck } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Card } from '@/components/ui/card';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();

/**
 * Sin assets fotográficos locales apropiados (auditado en Fase 10): el panel
 * izquierdo usa un patrón geométrico + degradado con los tokens de marca
 * globales (`--primary`) en vez de una imagen, para no depender de un
 * recurso externo ni de una foto genérica que no encaje con el dominio.
 */
const beneficios = [
    {
        icono: Package,
        texto: 'Inventario y activos centralizados por empresa y almacén',
    },
    {
        icono: QrCode,
        texto: 'Trazabilidad individual con código y QR por unidad',
    },
    {
        icono: ClipboardList,
        texto: 'Entregas con firma de recepción y comprobante en PDF',
    },
    {
        icono: ShieldCheck,
        texto: 'Auditoría completa de cada movimiento',
    },
];
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-2">
        <div
            class="from-primary to-primary-hover relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br p-10 lg:flex"
        >
            <div
                class="absolute inset-0 opacity-[0.07]"
                style="
                    background-image: radial-gradient(
                        currentColor 1.5px,
                        transparent 1.5px
                    );
                    background-size: 22px 22px;
                    color: var(--primary-foreground);
                "
            />

            <Link
                :href="home()"
                class="text-primary-foreground relative z-10 flex items-center gap-2 text-lg font-medium"
            >
                <AppLogoIcon class="size-8 fill-current" />
                {{ name }}
            </Link>

            <div class="text-primary-foreground relative z-10 space-y-6">
                <p class="max-w-sm text-2xl font-semibold text-balance">
                    Control total de uniformes y activos, de punta a punta.
                </p>
                <ul class="space-y-3">
                    <li
                        v-for="beneficio in beneficios"
                        :key="beneficio.texto"
                        class="flex items-start gap-3 text-sm opacity-90"
                    >
                        <component
                            :is="beneficio.icono"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        {{ beneficio.texto }}
                    </li>
                </ul>
            </div>

            <p class="text-primary-foreground relative z-10 text-xs opacity-70">
                © {{ new Date().getFullYear() }} {{ name }}
            </p>
        </div>

        <div
            class="bg-background flex flex-col items-center justify-center p-6 sm:p-10"
        >
            <div class="flex w-full max-w-sm flex-col gap-6">
                <Link
                    :href="home()"
                    class="flex items-center justify-center gap-2 lg:hidden"
                >
                    <AppLogoIcon class="text-primary size-8 fill-current" />
                    <span class="text-lg font-medium">{{ name }}</span>
                </Link>

                <Card class="gap-4 p-6">
                    <div
                        v-if="title || description"
                        class="flex flex-col gap-1 text-center"
                    >
                        <h1
                            v-if="title"
                            class="text-xl font-semibold tracking-tight"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-muted-foreground text-sm"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </Card>
            </div>
        </div>
    </div>
</template>
