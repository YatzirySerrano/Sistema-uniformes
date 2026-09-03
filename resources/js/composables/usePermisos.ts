import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { EmpresaAutorizada, UsuarioAutenticado } from '@/types/sistema';

/**
 * Acceso a permisos, roles y a la lista de empresas autorizadas compartidos
 * desde el backend. El sistema es multiempresa pero NO tiene "empresa activa":
 * la empresa se elige en cada formulario / filtro.
 */
export function usePermisos() {
    const page = usePage();

    const usuario = computed<UsuarioAutenticado | null>(
        () =>
            (page.props.auth as unknown as { user: UsuarioAutenticado | null })
                ?.user ?? null,
    );

    const empresasAutorizadas = computed<EmpresaAutorizada[]>(
        () =>
            (page.props.empresasAutorizadas as unknown as
                | EmpresaAutorizada[]
                | undefined) ?? [],
    );

    const permisos = computed<string[]>(() => usuario.value?.permisos ?? []);

    function puede(permiso: string | string[]): boolean {
        if (usuario.value?.es_superadministrador) {
            return true;
        }
        const lista = Array.isArray(permiso) ? permiso : [permiso];
        return lista.some((p) => permisos.value.includes(p));
    }

    function tieneRol(rol: string): boolean {
        return usuario.value?.roles?.includes(rol) ?? false;
    }

    return { usuario, empresasAutorizadas, permisos, puede, tieneRol };
}
