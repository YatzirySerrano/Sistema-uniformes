<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import FormularioColaborador from '@/components/colaboradores/FormularioColaborador.vue';
import type { ColaboradorEditable } from '@/components/colaboradores/FormularioColaborador.vue';
import type { EmpresaAutorizada } from '@/types/sistema';

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    colaborador: ColaboradorEditable | null;
    empresasAutorizadas: EmpresaAutorizada[];
    sucursalPreseleccionada?: Opcion | null;
    empresaPreseleccionadaId?: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Formulario', href: '#' },
        ],
    },
});

const esEdicion = !!props.colaborador;

function alCancelar(): void {
    router.visit('/colaboradores');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar colaborador' : 'Nuevo colaborador'" />

    <div class="flex w-full flex-col gap-6 p-4">
        <EncabezadoPagina
            :titulo="esEdicion ? 'Editar colaborador' : 'Nuevo colaborador'"
            descripcion="El colaborador pertenece a una empresa / razón social y a una de sus sucursales."
        />

        <FormularioColaborador
            :colaborador="colaborador"
            :empresas-autorizadas="empresasAutorizadas"
            :sucursal-preseleccionada="sucursalPreseleccionada"
            :empresa-preseleccionada-id="empresaPreseleccionadaId"
            @cancelar="alCancelar"
        />
    </div>
</template>
