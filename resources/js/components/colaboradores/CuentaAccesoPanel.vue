<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { KeyRound, Link2Off } from '@lucide/vue';
import { ref } from 'vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import BuscadorAsync from '@/components/sistema/BuscadorAsync.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

type Cuenta = { id: number; name: string; email: string };

/**
 * "Cuenta de acceso asociada" del colaborador: qué usuario del sistema lo
 * representa (y por tanto cuál es "su custodia" al redistribuir). Sólo se
 * monta si el backend envió el bloque (`colaboradores.usuario-ver`); vincular
 * / cambiar / desvincular requiere además `colaboradores.usuario-administrar`.
 */
const props = defineProps<{
    colaboradorId: number;
    usuario: Cuenta | null;
    puedeAdministrar: boolean;
}>();

const editando = ref(false);
const seleccion = ref<Cuenta | null>(null);

const form = useForm<{ usuario_id: number | null }>({ usuario_id: null });
const errorNegocio = () =>
    (form.errors as Record<string, string | undefined>).negocio;

async function buscarCuentas(
    q: string,
    signal?: AbortSignal,
): Promise<Cuenta[]> {
    const res = await fetch(
        `/colaboradores/${props.colaboradorId}/cuentas-disponibles?q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        },
    );
    if (!res.ok) return [];
    return (await res.json()).cuentas ?? [];
}

function guardar(usuarioId: number | null): void {
    form.usuario_id = usuarioId;
    form.put(`/colaboradores/${props.colaboradorId}/cuenta`, {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = false;
            seleccion.value = null;
        },
    });
}
</script>

<template>
    <section class="rounded-xl border p-4">
        <h2 class="mb-1 flex items-center gap-2 text-sm font-semibold">
            <KeyRound class="text-muted-foreground size-4" />
            Cuenta de acceso asociada
            <AyudaTooltip
                texto="Usuario del sistema que representa a este colaborador. Define qué es «su custodia» cuando entrega desde su custodia a otras personas. Una cuenta sólo puede representar a un colaborador."
                etiqueta="Ayuda sobre la cuenta de acceso"
            />
        </h2>

        <div v-if="usuario" class="text-sm">
            <p class="font-medium">{{ usuario.name }}</p>
            <p class="text-muted-foreground">{{ usuario.email }}</p>
        </div>
        <p v-else class="text-muted-foreground text-sm">
            Sin cuenta de acceso vinculada.
        </p>

        <template v-if="puedeAdministrar">
            <div v-if="editando" class="mt-3 grid gap-2 sm:max-w-md">
                <BuscadorAsync
                    v-model="seleccion"
                    :buscar="buscarCuentas"
                    :etiqueta="(c) => (c as Cuenta).name"
                    :descripcion="(c) => (c as Cuenta).email"
                    placeholder="Selecciona una cuenta"
                    placeholder-busqueda="Buscar por nombre o correo"
                    sin-resultados="No hay cuentas disponibles: deben estar activas, sin otro colaborador vinculado y con acceso a la empresa."
                />
                <p class="text-muted-foreground text-xs">
                    Sólo aparecen cuentas activas, con acceso a la empresa del
                    colaborador y que no representen ya a otro colaborador.
                </p>
                <InputError :message="errorNegocio()" />
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        :disabled="!seleccion || form.processing"
                        @click="guardar(seleccion?.id ?? null)"
                    >
                        Vincular
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="form.processing"
                        @click="editando = false"
                    >
                        Cancelar
                    </Button>
                </div>
            </div>
            <div v-else class="mt-3 flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="editando = true">
                    {{ usuario ? 'Cambiar cuenta' : 'Vincular cuenta' }}
                </Button>
                <Button
                    v-if="usuario"
                    variant="ghost"
                    size="sm"
                    :disabled="form.processing"
                    @click="guardar(null)"
                >
                    <Link2Off class="size-3.5" /> Desvincular
                </Button>
            </div>
        </template>
    </section>
</template>
