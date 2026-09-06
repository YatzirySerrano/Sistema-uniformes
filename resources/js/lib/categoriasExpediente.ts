import {
    Award,
    FileSignature,
    FileSpreadsheet,
    FileText,
    FileType,
    FolderOpen,
    IdCard,
    Image as ImageIcon,
    Landmark,
    PackageCheck,
    Receipt,
    ShieldCheck,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Mapa único categoría → ícono/color del expediente digital. Centralizado
 * aquí para no dispersar clases de Tailwind por todo `ExpedienteExplorer.vue`
 * y para que cada color tenga su variante `dark:` desde el inicio (nunca un
 * color de marca hardcodeado, sólo la paleta estándar de Tailwind).
 */
type EstiloCategoria = {
    icono: Component;
    clases: string;
};

const CATEGORIA_ESTILOS: Record<string, EstiloCategoria> = {
    identificacion: {
        icono: IdCard,
        clases: 'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-950/40 dark:border-blue-900 dark:text-blue-300',
    },
    contratos: {
        icono: FileSignature,
        clases: 'bg-amber-50 border-amber-200 text-amber-700 dark:bg-amber-950/40 dark:border-amber-900 dark:text-amber-300',
    },
    fiscal: {
        icono: Landmark,
        clases: 'bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-950/40 dark:border-emerald-900 dark:text-emerald-300',
    },
    seguridad_social: {
        icono: ShieldCheck,
        clases: 'bg-teal-50 border-teal-200 text-teal-700 dark:bg-teal-950/40 dark:border-teal-900 dark:text-teal-300',
    },
    comprobantes: {
        icono: Receipt,
        clases: 'bg-violet-50 border-violet-200 text-violet-700 dark:bg-violet-950/40 dark:border-violet-900 dark:text-violet-300',
    },
    constancias: {
        icono: Award,
        clases: 'bg-cyan-50 border-cyan-200 text-cyan-700 dark:bg-cyan-950/40 dark:border-cyan-900 dark:text-cyan-300',
    },
    entregas_acuses: {
        icono: PackageCheck,
        clases: 'bg-indigo-50 border-indigo-200 text-indigo-700 dark:bg-indigo-950/40 dark:border-indigo-900 dark:text-indigo-300',
    },
    otros: {
        icono: FolderOpen,
        clases: 'bg-muted border-border text-muted-foreground',
    },
};

const ESTILO_DEFAULT: EstiloCategoria = CATEGORIA_ESTILOS.otros;

export function estiloCategoria(valor: string): EstiloCategoria {
    return CATEGORIA_ESTILOS[valor] ?? ESTILO_DEFAULT;
}

/**
 * Ícono por tipo de archivo (no por categoría) para las tarjetas de
 * documento dentro de una carpeta.
 */
export function iconoDocumento(mime: string): Component {
    if (mime.startsWith('image/')) return ImageIcon;
    if (mime.includes('word')) return FileType;
    if (
        mime.includes('excel') ||
        mime.includes('spreadsheet') ||
        mime === 'text/csv'
    ) {
        return FileSpreadsheet;
    }

    return FileText;
}
