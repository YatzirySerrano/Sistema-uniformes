<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { logout } from '@/routes';

// Deliberadamente FUERA del árbol de un DropdownMenu: un <Dialog> montado
// dentro de un DropdownMenuContent se destruye en cuanto el menú se cierra
// (la selección de un DropdownMenuItem cierra el menú de inmediato), así que
// el modal de confirmación desaparecía solo poco después de abrirse. Este
// componente vive como hermano del DropdownMenu, con su propio ciclo de
// vida, y sólo se le pide abrirse vía v-model:open.
const open = defineModel<boolean>('open', { default: false });

const confirmando = ref(false);

function confirmar(): void {
    if (confirmando.value) return;
    confirmando.value = true;
    router.flushAll();
    router.post(
        logout.url(),
        {},
        { onFinish: () => (confirmando.value = false) },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>¿Cerrar sesión?</DialogTitle>
                <DialogDescription>
                    ¿Seguro que deseas cerrar tu sesión?
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="ghost"
                    :disabled="confirmando"
                    @click="open = false"
                >
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :disabled="confirmando"
                    @click="confirmar"
                >
                    Cerrar sesión
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
