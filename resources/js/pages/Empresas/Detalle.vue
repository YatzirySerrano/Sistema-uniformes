<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    Hash,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Power,
    ScrollText,
    Store,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { EmpresaEditable } from '@/components/empresas/FormularioEmpresa.vue';
import FormularioEmpresa from '@/components/empresas/FormularioEmpresa.vue';
import AyudaTooltip from '@/components/sistema/AyudaTooltip.vue';
import PanelSuspendidos from '@/components/sistema/PanelSuspendidos.vue';
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

type EmpresaDetalle = EmpresaEditable & {
    logo_url: string | null;
    sucursales_total: number;
    sucursales_activas: number;
    colaboradores_total: number;
    colaboradores_activos: number;
};

const props = defineProps<{
    empresa: EmpresaDetalle;
    puedeEditar: boolean;
    puedeCambiarEstado: boolean;
    suspendidos: {
        id: number;
        tipo: string;
        nombre: string | null;
        suspendida_en: string;
        puede_reactivarse: boolean;
        motivos: string[];
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Empresas', href: '/empresas' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const modalEditar = ref(false);
const claveFormulario = ref(0);
const modalEstado = ref(false);
const procesandoEstado = ref(false);

const empresaEditable = computed<EmpresaEditable>(() => ({
    id: props.empresa.id,
    codigo: props.empresa.codigo,
    nombre_comercial: props.empresa.nombre_comercial,
    razon_social: props.empresa.razon_social,
    rfc: props.empresa.rfc,
    telefono: props.empresa.telefono,
    correo: props.empresa.correo,
    direccion: props.empresa.direccion,
    activa: props.empresa.activa,
}));

const telefonoLegible = computed(() => {
    const t = props.empresa.telefono;
    if (!t) return null;
    return t.length === 10
        ? `${t.slice(0, 2)} ${t.slice(2, 6)} ${t.slice(6)}`
        : t;
});

function abrirEditar(): void {
    claveFormulario.value++;
    modalEditar.value = true;
}

function alGuardar(): void {
    modalEditar.value = false;
}

function confirmarEstado(): void {
    procesandoEstado.value = true;
    router.post(
        `/empresas/${props.empresa.id}/estado`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                procesandoEstado.value = false;
                modalEstado.value = false;
            },
        },
    );
}

const navegando = ref(false);

/** Navega a otro módulo prefiltrado por esta empresa. */
function irA(ruta: string): void {
    const separador = ruta.includes('?') ? '&' : '?';
    router.visit(`${ruta}${separador}empresa_id=${props.empresa.id}`);
}
</script>

<template>
    <Head :title="empresa.nombre_comercial" />

    <div class="flex w-full flex-col gap-4 p-4">
        <Button variant="ghost" size="sm" as-child class="w-fit">
            <Link href="/empresas">
                <ArrowLeft class="size-4" /> Volver a empresas
            </Link>
        </Button>

        <!-- Encabezado -->
        <div
            class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="bg-muted flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border"
                >
                    <img
                        v-if="empresa.logo_url"
                        :src="empresa.logo_url"
                        :alt="`Logotipo de ${empresa.nombre_comercial}`"
                        class="size-full object-contain"
                    />
                    <Building2 v-else class="text-muted-foreground size-6" />
                </span>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight">
                        {{ empresa.nombre_comercial }}
                    </h1>
                    <p class="text-muted-foreground font-mono text-sm">
                        {{ empresa.codigo }}
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="empresa.activa ? 'success' : 'secondary'"
                        >
                            {{ empresa.activa ? 'Activa' : 'Eliminada' }}
                        </Badge>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="puedeEditar"
                    variant="outline"
                    size="sm"
                    @click="abrirEditar"
                >
                    <Pencil class="size-3.5" /> Editar
                </Button>
                <Button
                    v-if="puedeCambiarEstado"
                    :variant="empresa.activa ? 'ghost' : 'default'"
                    size="sm"
                    @click="modalEstado = true"
                >
                    <Power class="size-3.5" />
                    {{ empresa.activa ? 'Eliminar' : 'Restaurar' }}
                </Button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <!-- Información general -->
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <ScrollText class="text-muted-foreground size-4" />
                    Información general
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Nombre comercial
                        </dt>
                        <dd>{{ empresa.nombre_comercial }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Razón social
                        </dt>
                        <dd>{{ empresa.razon_social ?? '—' }}</dd>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <dt
                                class="text-muted-foreground flex items-center gap-1 text-xs"
                            >
                                <Hash class="size-3" /> Código
                            </dt>
                            <dd class="font-mono">{{ empresa.codigo }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">RFC</dt>
                            <dd class="font-mono">
                                {{ empresa.rfc ?? '—' }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </section>

            <!-- Contacto -->
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Phone class="text-muted-foreground size-4" />
                    Contacto
                </h2>
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Phone class="size-3" /> Teléfono
                        </dt>
                        <dd>{{ telefonoLegible ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Mail class="size-3" /> Correo
                        </dt>
                        <dd class="break-all">{{ empresa.correo ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <MapPin class="size-3" /> Dirección
                        </dt>
                        <dd>{{ empresa.direccion ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Resumen operativo -->
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Users class="text-muted-foreground size-4" />
                    Resumen operativo
                </h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <!-- Sucursales -->
                    <div class="bg-muted/40 flex flex-col gap-2 rounded-lg p-3">
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Store class="size-3" /> Sucursales activas
                            <AyudaTooltip
                                texto="Sucursales de esta empresa con estado activo. Entre paréntesis, el total incluyendo inactivas."
                                etiqueta="Ayuda sobre sucursales activas"
                            />
                        </p>
                        <template v-if="empresa.sucursales_total > 0">
                            <p class="text-2xl font-semibold">
                                {{ empresa.sucursales_activas }}
                                <span
                                    class="text-muted-foreground text-sm font-normal"
                                >
                                    / {{ empresa.sucursales_total }}
                                </span>
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                :disabled="navegando"
                                @click="irA('/sucursales')"
                            >
                                Ver sucursales
                            </Button>
                        </template>
                        <template v-else>
                            <p class="text-muted-foreground text-sm">
                                No hay sucursales registradas para esta empresa.
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                :disabled="navegando"
                                @click="irA('/sucursales?nueva=1')"
                            >
                                Registrar sucursal
                            </Button>
                        </template>
                    </div>

                    <!-- Colaboradores -->
                    <div class="bg-muted/40 flex flex-col gap-2 rounded-lg p-3">
                        <p
                            class="text-muted-foreground flex items-center gap-1 text-xs"
                        >
                            <Users class="size-3" /> Colaboradores activos
                            <AyudaTooltip
                                texto="Colaboradores con estado activo. Entre paréntesis, el total incluyendo inactivos."
                                etiqueta="Ayuda sobre colaboradores activos"
                            />
                        </p>
                        <template v-if="empresa.colaboradores_total > 0">
                            <p class="text-2xl font-semibold">
                                {{ empresa.colaboradores_activos }}
                                <span
                                    class="text-muted-foreground text-sm font-normal"
                                >
                                    / {{ empresa.colaboradores_total }}
                                </span>
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                :disabled="navegando"
                                @click="irA('/colaboradores')"
                            >
                                Ver colaboradores
                            </Button>
                        </template>
                        <template v-else>
                            <p class="text-muted-foreground text-sm">
                                Aún no hay colaboradores registrados.
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                :disabled="navegando"
                                @click="irA('/colaboradores')"
                            >
                                Registrar colaborador
                            </Button>
                        </template>
                    </div>
                </div>
                <p class="text-muted-foreground mt-3 text-xs">
                    Los accesos rápidos abren cada módulo prefiltrado por esta
                    empresa.
                </p>
            </section>
        </div>

        <PanelSuspendidos
            v-if="suspendidos.length"
            :suspendidos="suspendidos"
            :endpoint="`/empresas/${empresa.id}/suspendidos/reactivar`"
            :puede-reactivar="puedeCambiarEstado"
        />

        <!-- Modal editar -->
        <Dialog v-model:open="modalEditar">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Editar empresa</DialogTitle>
                    <DialogDescription>
                        Actualiza los datos generales y de contacto de la
                        empresa.
                    </DialogDescription>
                </DialogHeader>
                <FormularioEmpresa
                    :key="claveFormulario"
                    :empresa="empresaEditable"
                    :logo-url-actual="empresa.logo_url"
                    @guardado="alGuardar"
                    @cancelar="modalEditar = false"
                />
            </DialogContent>
        </Dialog>

        <!-- Modal confirmar cambio de estado -->
        <Dialog v-model:open="modalEstado">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            empresa.activa
                                ? '¿Eliminar esta empresa?'
                                : '¿Restaurar esta empresa?'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            empresa.activa
                                ? 'Esta acción retirará la empresa de los listados y operaciones disponibles. Los datos históricos se conservarán y podrás restaurarla cuando quieras.'
                                : 'La empresa volverá a estar disponible para operar con normalidad.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="procesandoEstado"
                        @click="modalEstado = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        :variant="empresa.activa ? 'destructive' : 'default'"
                        :disabled="procesandoEstado"
                        @click="confirmarEstado"
                    >
                        {{ empresa.activa ? 'Eliminar' : 'Restaurar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
