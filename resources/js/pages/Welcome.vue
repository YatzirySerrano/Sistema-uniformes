<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';

const page = usePage();
const autenticado = computed(() => !!page.props.auth?.user);
</script>

<template>
    <Head title="Sistema de Control y Gestión de Uniformes" />

    <div
        class="bg-background text-foreground flex min-h-svh flex-col items-center justify-center gap-8 p-6"
    >
        <div class="flex flex-col items-center gap-4 text-center">
            <div
                class="bg-primary text-primary-foreground flex size-14 items-center justify-center rounded-xl"
            >
                <AppLogoIcon class="size-8 fill-current" />
            </div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Sistema de Control y Gestión de Uniformes
            </h1>
            <p class="text-muted-foreground max-w-md text-sm">
                Control de entregas de uniformes a colaboradores, inventario por
                sucursal, firmas de recepción y comprobantes, con trazabilidad
                completa.
            </p>
        </div>

        <div class="flex gap-3">
            <Button v-if="autenticado" as-child>
                <Link href="/dashboard">Ir al panel</Link>
            </Button>
            <Button v-else as-child>
                <Link href="/login">Iniciar sesión</Link>
            </Button>
        </div>
    </div>
</template>
