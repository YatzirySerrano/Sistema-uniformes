import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ContextoEmpresa, UsuarioAutenticado } from '@/types/sistema';

/**
 * Acceso a permisos, roles y contexto de empresa compartidos desde el backend.
 */
export function usePermisos() {
    const page = usePage();

    const usuario = computed<UsuarioAutenticado | null>(
        () =>
            (page.props.auth as unknown as { user: UsuarioAutenticado | null })
                ?.user ?? null,
    );

    const contexto = computed<ContextoEmpresa | null>(
        () =>
            (page.props.contextoEmpresa as unknown as ContextoEmpresa | null) ??
            null,
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

    return { usuario, contexto, permisos, puede, tieneRol };
}
