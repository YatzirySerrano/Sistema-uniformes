<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import EncabezadoPagina from '@/components/sistema/EncabezadoPagina.vue';
import Paginacion from '@/components/sistema/Paginacion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Paginado } from '@/types/sistema';

type Usuario = {
    id: number;
    name: string;
    email: string;
    activo: boolean;
    verificado: boolean;
    roles: string[];
    empresas: string[];
    ultimo_acceso_en: string | null;
    puedeCambiarEstado: boolean;
};

defineProps<{
    usuarios: Paginado<Usuario>;
    puedeCrear: boolean;
    puedeVerEliminados: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Usuarios', href: '/usuarios' }] },
});

const confirmando = ref<Usuario | null>(null);
const procesandoEstado = ref(false);

function toggle(u: Usuario) {
    if (u.activo) {
        confirmando.value = u; // eliminar => confirmación
    } else {
        // restaurar es seguro: sin confirmación
        router.post(`/usuarios/${u.id}/estado`, {}, { preserveScroll: true });
    }
}

function confirmarEstado(): void {
    if (!confirmando.value) return;
    procesandoEstado.value = true;
    router.post(
        `/usuarios/${confirmando.value.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                confirmando.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-4 p-4">
        <EncabezadoPagina
            titulo="Usuarios"
            descripcion="Cuentas de acceso al sistema. El registro público está deshabilitado."
        >
            <template #acciones>
                <Button v-if="puedeCrear" as-child>
                    <Link href="/usuarios/crear"
                        ><Plus class="size-4" /> Nuevo usuario</Link
                    >
                </Button>
            </template>
        </EncabezadoPagina>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Nombre</th>
                        <th class="px-3 py-2 font-medium">Correo</th>
                        <th class="px-3 py-2 font-medium">Roles</th>
                        <th class="px-3 py-2 font-medium">Empresas</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="u in usuarios.data"
                        :key="u.id"
                        class="hover:bg-muted/40 border-t transition-colors"
                    >
                        <td class="px-3 py-2">{{ u.name }}</td>
                        <td class="px-3 py-2">
                            {{ u.email }}
                            <Badge
                                v-if="!u.verificado"
                                variant="secondary"
                                class="ml-1 text-amber-600"
                                >sin verificar</Badge
                            >
                        </td>
                        <td class="px-3 py-2">
                            <span
                                v-for="r in u.roles"
                                :key="r"
                                class="bg-muted mr-1 rounded px-1.5 py-0.5 text-[11px] capitalize"
                                >{{ r }}</span
                            >
                        </td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ u.empresas.join(', ') || '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="u.activo ? 'success' : 'secondary'"
                                >{{ u.activo ? 'Activo' : 'Eliminado' }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Link
                                :href="`/usuarios/${u.id}/editar`"
                                class="text-primary text-xs hover:underline"
                                >Editar</Link
                            >
                            <button
                                v-if="u.puedeCambiarEstado"
                                type="button"
                                class="text-primary ml-3 text-xs hover:underline"
                                @click="toggle(u)"
                            >
                                {{ u.activo ? 'Eliminar' : 'Restaurar' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion :links="usuarios.links" :total="usuarios.total" />

        <Dialog
            :open="confirmando !== null"
            @update:open="
                (v) => {
                    if (!v) confirmando = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Eliminar el usuario
                        <span v-if="confirmando">{{ confirmando.name }}</span
                        >?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción le impedirá iniciar sesión. Su historial de
                        acciones no se modifica y podrás restaurar el acceso
                        cuando quieras.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEstado"
                        @click="confirmando = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="procesandoEstado"
                        @click="confirmarEstado"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
