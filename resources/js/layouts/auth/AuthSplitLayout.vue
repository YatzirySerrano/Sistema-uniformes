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
 * Sin assets fotográficos externos (política del proyecto: no descargar de
 * fuentes no verificadas ni depender de una imagen externa que pueda dejar
 * de estar disponible). En su lugar, un fondo "mesh gradient" hecho sólo con
 * CSS (varios radial-gradient en capas) sobre los tokens de marca globales
 * (`--primary` / `--primary-hover` / `--primary-foreground`): se ve como una
 * imagen abstracta moderna, respeta el tema claro/oscuro y no depende de red.
 * Se reutiliza tanto en el panel izquierdo (escritorio) como en la banda
 * decorativa superior (móvil/tablet) para que ningún breakpoint quede plano.
 */
const fondoDecorativo = {
    backgroundImage: [
        'radial-gradient(at 15% 20%, color-mix(in srgb, var(--primary-foreground) 40%, transparent) 0px, transparent 55%)',
        'radial-gradient(at 85% 10%, color-mix(in srgb, var(--primary-foreground) 22%, transparent) 0px, transparent 50%)',
        'radial-gradient(at 0% 85%, color-mix(in srgb, var(--primary-hover) 70%, transparent) 0px, transparent 55%)',
        'radial-gradient(at 85% 95%, color-mix(in srgb, var(--primary-foreground) 18%, transparent) 0px, transparent 50%)',
        'linear-gradient(135deg, var(--primary), var(--primary-hover))',
    ].join(', '),
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
            class="relative flex shrink-0 items-center justify-center overflow-hidden bg-cover py-8 lg:hidden"
        >
            <div
                aria-hidden="true"
                class="absolute inset-0 opacity-[0.1]"
                style="
                    background-image: radial-gradient(
                        currentColor 1.5px,
                        transparent 1.5px
                    );
                    background-size: 18px 18px;
                    color: var(--primary-foreground);
                "
            />
            <Link
                :href="home()"
                class="text-primary-foreground relative z-10 flex items-center gap-2 text-lg font-medium"
            >
                <span
                    class="bg-primary-foreground/10 ring-primary-foreground/15 flex size-10 items-center justify-center rounded-xl ring-1 backdrop-blur-sm"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                {{ name }}
            </Link>
        </div>

        <!-- Panel completo: sólo escritorio (lg+). -->
        <div
            :style="fondoDecorativo"
            class="relative hidden flex-col justify-between overflow-hidden bg-cover p-10 lg:flex"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full opacity-20 blur-3xl"
                style="background: var(--primary-foreground)"
            />
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -bottom-32 -left-16 size-80 rounded-full opacity-10 blur-3xl"
                style="background: var(--primary-foreground)"
            />
            <div
                aria-hidden="true"
                class="absolute inset-0 opacity-[0.08]"
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
                <span
                    class="bg-primary-foreground/10 ring-primary-foreground/15 flex size-10 items-center justify-center rounded-xl ring-1 backdrop-blur-sm"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                {{ name }}
            </Link>

            <div class="text-primary-foreground relative z-10 space-y-7">
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
                            class="bg-primary-foreground/10 ring-primary-foreground/15 flex size-8 shrink-0 items-center justify-center rounded-lg ring-1"
                        >
                            <component :is="beneficio.icono" class="size-4" />
                        </span>
                        <span class="opacity-90">{{ beneficio.texto }}</span>
                    </li>
                </ul>
            </div>

            <p class="text-primary-foreground relative z-10 text-xs opacity-70">
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
