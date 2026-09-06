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
 * Foto propia del proyecto (`public/images/loginBg.jpg`, subida por el
 * equipo — no es un asset externo de terceros). Se atenúa con un degradado
 * oscuro para que el logo y el texto en blanco mantengan contraste sobre
 * cualquier zona de la imagen, en cualquier tema. Se reutiliza tanto en el
 * panel izquierdo (escritorio) como en la banda decorativa superior
 * (móvil/tablet) para que ningún breakpoint quede plano.
 */
const fondoDecorativo = {
    backgroundImage: [
        'linear-gradient(180deg, rgba(15,23,42,0.55) 0%, rgba(15,23,42,0.35) 45%, rgba(15,23,42,0.75) 100%)',
        "url('/images/loginBg.jpg')",
    ].join(', '),
    backgroundSize: 'cover',
    backgroundPosition: 'center',
};

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
    <div class="flex min-h-dvh flex-col lg:grid lg:grid-cols-2">
        <!-- Banda decorativa: sólo visible en móvil/tablet, para que la
             pantalla no quede plana antes de llegar al panel completo de escritorio. -->
        <div
            :style="fondoDecorativo"
            class="relative flex shrink-0 items-center justify-center overflow-hidden py-10 lg:hidden"
        >
            <Link
                :href="home()"
                class="relative z-10 flex items-center gap-2 text-lg font-medium text-white"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20 backdrop-blur-sm"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                {{ name }}
            </Link>
        </div>

        <!-- Panel completo: sólo escritorio (lg+). -->
        <div
            :style="fondoDecorativo"
            class="relative hidden flex-col justify-between overflow-hidden p-10 lg:flex"
        >
            <Link
                :href="home()"
                class="relative z-10 flex items-center gap-2 text-lg font-medium text-white"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20 backdrop-blur-sm"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                {{ name }}
            </Link>

            <div class="relative z-10 space-y-7 text-white">
                <p class="max-w-sm text-3xl font-semibold text-balance">
                    Control total de uniformes y activos, de punta a punta.
                </p>
                <ul class="space-y-4">
                    <li
                        v-for="beneficio in beneficios"
                        :key="beneficio.texto"
                        class="flex items-center gap-3 text-sm"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/20"
                        >
                            <component :is="beneficio.icono" class="size-4" />
                        </span>
                        <span class="opacity-90">{{ beneficio.texto }}</span>
                    </li>
                </ul>
            </div>

            <p class="relative z-10 text-xs text-white opacity-70">
                © {{ new Date().getFullYear() }} {{ name }}
            </p>
        </div>

        <div
            class="bg-background relative flex flex-1 flex-col items-center justify-center p-6 sm:p-10"
        >
            <div
                aria-hidden="true"
                class="from-primary/[0.04] pointer-events-none absolute inset-0 bg-gradient-to-bl to-transparent"
            />

            <div class="relative z-10 flex w-full max-w-sm flex-col gap-6">
                <Card class="gap-4 p-6 shadow-lg">
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
