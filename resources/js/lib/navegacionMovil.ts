import type { Ref } from 'vue';

/**
 * Sidebar en móvil: es un panel superpuesto (Sheet) y el layout persiste
 * entre páginas de Inertia, así que nada lo cerraba al navegar. Se cierra
 * SÓLO tras una navegación exitosa desde una opción del menú y sólo en
 * móvil — el sidebar de escritorio (expandido/colapsado + cookie) no se toca.
 */
export function cerrarSidebarSiMovil(sidebar: {
    isMobile: Ref<boolean>;
    setOpenMobile: (abierto: boolean) => void;
}): void {
    if (sidebar.isMobile.value) {
        sidebar.setOpenMobile(false);
    }
}

/**
 * Clases de cada acceso del menú inferior móvil. Sólo tokens que conservan
 * contraste en ambos temas: el texto nunca usa `--primary`, porque el
 * branding de la empresa lo sobrescribe también en modo oscuro (con el color
 * por defecto, casi negro, la ruta actual quedaba negro sobre negro). La
 * marca se usa únicamente como FONDO de la píldora del icono activo, con su
 * propio `--primary-foreground` encima.
 */
export function clasesAccesoNavInferior(activo: boolean): {
    enlace: string;
    icono: string;
} {
    const base =
        'flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[11px] transition-colors outline-none hover:bg-accent hover:text-accent-foreground active:bg-accent active:text-accent-foreground focus-visible:bg-accent focus-visible:text-accent-foreground focus-visible:ring-2 focus-visible:ring-foreground/60 focus-visible:ring-inset';

    return activo
        ? {
              enlace: `${base} text-foreground font-semibold`,
              icono: 'bg-primary text-primary-foreground rounded-full px-3 py-0.5',
          }
        : {
              enlace: `${base} text-muted-foreground`,
              icono: 'px-3 py-0.5',
          };
}
