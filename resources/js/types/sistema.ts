export type IdNombre = { id: number; nombre: string };

export type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type EmpresaResumen = {
    id: number;
    codigo: string;
    nombre_comercial: string;
    logo_url?: string | null;
};

/**
 * Empresa a la que el usuario tiene acceso. Alimenta los combobox de empresa de
 * formularios y filtros (no hay "empresa activa" global).
 */
export type EmpresaAutorizada = {
    id: number;
    codigo: string;
    nombre_comercial: string;
};

export type UsuarioAutenticado = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    activo: boolean;
    roles: string[];
    permisos: string[];
    es_superadministrador: boolean;
};

export type TallaOpcion = { id: number; valor: string };
export type ActivoOpcion = {
    id: number;
    nombre: string;
    tallas: TallaOpcion[];
};

export type ItemEntrega = {
    activo_id: number | null;
    talla_id: number | null;
    cantidad: number;
};
