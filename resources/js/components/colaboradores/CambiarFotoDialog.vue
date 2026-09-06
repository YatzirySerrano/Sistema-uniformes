<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import SubidaArchivo from '@/components/sistema/SubidaArchivo.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    fotoActualUrl: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const form = useForm<{ foto: File | null }>({ foto: null });

// Al abrir/cerrar el diálogo el formulario siempre vuelve a su estado
// original — nunca conserva el archivo elegido de un intento anterior.
watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            form.reset();
            form.clearErrors();
        }
    },
);

function enviar(): void {
    form.post(`/colaboradores/${props.colaboradorId}/foto`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Cambiar foto de perfil</DialogTitle>
                <DialogDescription>
                    Se usará como foto de perfil del colaborador. Formatos JPG,
                    PNG o WEBP, hasta 3 MB.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="enviar">
                <SubidaArchivo
                    id="cambiar-foto"
                    v-model="form.foto"
                    tipo="imagen"
                    accept="image/jpeg,image/png,image/webp"
                    formatos-etiqueta="JPG, PNG o WEBP"
                    :peso-maximo-mb="3"
                    tamano="large"
                    :archivo-actual-url="fotoActualUrl"
                    :invalido="!!form.errors.foto"
                />
                <InputError :message="form.errors.foto" />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.foto"
                    >
                        {{ form.processing ? 'Guardando…' : 'Guardar foto' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
