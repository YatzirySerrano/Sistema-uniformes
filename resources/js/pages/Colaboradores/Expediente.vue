<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ExpedienteExplorer from '@/components/colaboradores/ExpedienteExplorer.vue';
import type {
    Categoria,
    ColaboradorMini,
    Documento,
} from '@/components/colaboradores/ExpedienteExplorer.vue';
import { Button } from '@/components/ui/button';

/**
 * Acceso directo (deep-link) al expediente sin pasar por el perfil. La
 * experiencia principal vive integrada en `Colaboradores/Detalle.vue`
 * (pestaña "Expediente"); esta página se conserva para no romper enlaces ya
 * compartidos, reusando el mismo `ExpedienteExplorer`.
 */
defineProps<{
    colaborador: ColaboradorMini;
    categorias: Categoria[];
    documentos: Documento[];
    puedeAdministrar: boolean;
    puedeDescargar: boolean;
    puedeVerEliminados: boolean;
    filtroEstado: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Expediente', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Expediente de ${colaborador.nombre_completo}`" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link :href="`/colaboradores/${colaborador.id}`">
                <ArrowLeft class="size-4" /> Volver al perfil
            </Link>
        </Button>

        <ExpedienteExplorer
            :colaborador="colaborador"
            :categorias="categorias"
            :documentos="documentos"
            :puede-administrar="puedeAdministrar"
            :puede-descargar="puedeDescargar"
            :puede-ver-eliminados="puedeVerEliminados"
            :filtro-estado="filtroEstado"
            mostrar-encabezado
        />
    </div>
</template>
