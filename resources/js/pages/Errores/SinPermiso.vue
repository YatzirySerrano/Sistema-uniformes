<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, LayoutGrid, ShieldAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Acceso no autorizado (HTTP 403) al navegar a una pantalla. El backend
 * sigue respondiendo 403 (la autorización no cambia); esta vista sólo
 * sustituye la pantalla técnica de Laravel por un mensaje claro en español,
 * dentro del layout de la aplicación. Nunca muestra permisos, Policies ni
 * detalles técnicos: `mensaje` ya viene saneado por
 * `App\Soporte\AccesoNoAutorizado`.
 */
defineProps<{ mensaje: string }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Acceso no autorizado', href: '#' }],
    },
});

function volver(): void {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/dashboard';
    }
}
</script>

<template>
    <Head title="Acceso no autorizado" />

    <div class="flex w-full flex-1 items-start justify-center p-4 sm:p-8">
        <section
            class="bg-card w-full max-w-xl rounded-xl border p-6 shadow-xs sm:p-8"
            aria-labelledby="titulo-sin-permiso"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div
                    class="bg-destructive/10 text-destructive flex size-12 shrink-0 items-center justify-center rounded-full"
                >
                    <ShieldAlert class="size-6" aria-hidden="true" />
                </div>
                <div class="min-w-0 space-y-2">
                    <h1
                        id="titulo-sin-permiso"
                        class="text-lg font-semibold tracking-tight"
                    >
                        No tienes permiso para realizar esta acción
                    </h1>
                    <p class="text-muted-foreground text-sm">
                        Tu cuenta no tiene autorización para acceder a esta
                        sección o realizar esta operación.
                    </p>
                    <p
                        v-if="
                            mensaje !==
                            'No tienes permiso para realizar esta acción.'
                        "
                        class="text-sm"
                    >
                        {{ mensaje }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Si consideras que deberías tener acceso, solicita a un
                        administrador que revise tus permisos.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-2 sm:justify-end">
                <Button variant="outline" @click="volver">
                    <ArrowLeft class="size-4" />
                    Volver
                </Button>
                <Button as-child>
                    <Link href="/dashboard">
                        <LayoutGrid class="size-4" />
                        Ir al panel
                    </Link>
                </Button>
            </div>
        </section>
    </div>
</template>
