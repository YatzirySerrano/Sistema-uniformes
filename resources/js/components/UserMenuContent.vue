<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
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
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

// Cerrar sesión pide confirmación explícita: nunca se cierra la sesión de
// inmediato al primer clic.
const modalCerrarSesion = ref(false);

function confirmarCerrarSesion(): void {
    router.flushAll();
    router.post(logout.url());
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Configuración
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem
        class="cursor-pointer"
        data-test="logout-button"
        @click="modalCerrarSesion = true"
    >
        <LogOut class="mr-2 h-4 w-4" />
        Cerrar sesión
    </DropdownMenuItem>

    <Dialog v-model:open="modalCerrarSesion">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>¿Cerrar sesión?</DialogTitle>
                <DialogDescription>
                    ¿Seguro que deseas cerrar tu sesión?
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="ghost" @click="modalCerrarSesion = false">
                    Cancelar
                </Button>
                <Button variant="destructive" @click="confirmarCerrarSesion">
                    Cerrar sesión
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
