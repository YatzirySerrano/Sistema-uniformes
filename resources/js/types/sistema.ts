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

export type ContextoEmpresa = {
    empresaActivaId: number | null;
    empresaActiva: EmpresaResumen | null;
    empresasDisponibles: {
        id: number;
        codigo: string;
        nombre_comercial: string;
    }[];
    sucursalesDisponibles: { id: number; codigo: string; nombre: string }[];
    branding: Record<string, string>;
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
export type PrendaOpcion = {
    id: number;
    nombre: string;
    tallas: TallaOpcion[];
};

export type ItemEntrega = {
    prenda_id: number | null;
    talla_id: number | null;
    cantidad: number;
};
