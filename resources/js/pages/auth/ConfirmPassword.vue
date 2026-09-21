<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
// Passkey en "Confirmar contraseña" deliberadamente oculto en frontend (aún
// no se ofrece al usuario) — backend/Fortify/WebAuthn intactos, ver
// .ai/rules/components-js-components.md. Para reactivarlo: descomentar este import y el
// import de rutas de abajo, y volver a poner `<PasskeyVerify />` antes del
// `<Form>`.
// import {
//     index as confirmOptions,
//     store as confirmStore,
// } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
// import PasskeyVerify from '@/components/PasskeyVerify.vue';

defineOptions({
    layout: {
        title: 'Confirmar contraseña',
        description:
            'Esta es un área segura. Confirma tu contraseña para continuar.',
    },
});
</script>

<template>
    <Head title="Confirmar contraseña" />

    <!-- Passkey oculto en frontend, ver comentario junto al import de arriba.
    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        label="Confirmar con passkey"
        loading-label="Confirmando..."
        separator="O confirma con tu contraseña"
    />
    -->

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label htmlFor="password">Contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    Confirmar contraseña
                </Button>
            </div>
        </div>
    </Form>
</template>
