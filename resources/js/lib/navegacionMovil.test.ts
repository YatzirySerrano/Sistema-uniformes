import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import {
    cerrarSidebarSiMovil,
    clasesAccesoNavInferior,
} from './navegacionMovil';

describe('sidebar: cerrar al navegar', () => {
    it('en móvil, una navegación exitosa cierra el panel', () => {
        const setOpenMobile = vi.fn();
        cerrarSidebarSiMovil({ isMobile: ref(true), setOpenMobile });

        expect(setOpenMobile).toHaveBeenCalledWith(false);
    });

    it('en escritorio no toca nada (ni colapsa ni cambia la preferencia)', () => {
        const setOpenMobile = vi.fn();
        cerrarSidebarSiMovil({ isMobile: ref(false), setOpenMobile });

        expect(setOpenMobile).not.toHaveBeenCalled();
    });
});

describe('menú inferior móvil: contraste en claro y oscuro', () => {
    const activo = clasesAccesoNavInferior(true);
    const inactivo = clasesAccesoNavInferior(false);

    it('el texto nunca usa --primary (la marca lo vuelve casi negro en modo oscuro)', () => {
        for (const c of [activo.enlace, inactivo.enlace]) {
            expect(c).not.toMatch(/(^|\s)text-primary(\s|$)/);
        }
        expect(activo.enlace).toContain('text-foreground');
        expect(inactivo.enlace).toContain('text-muted-foreground');
    });

    it('la marca sólo aparece como fondo de la píldora activa, con su texto de contraste', () => {
        expect(activo.icono).toContain('bg-primary');
        expect(activo.icono).toContain('text-primary-foreground');
        expect(inactivo.icono).not.toContain('bg-primary');
    });

    it('hover, tap y foco de teclado usan accent / accent-foreground, con anillo visible', () => {
        for (const c of [activo.enlace, inactivo.enlace]) {
            expect(c).toContain('hover:bg-accent');
            expect(c).toContain('hover:text-accent-foreground');
            expect(c).toContain('active:bg-accent');
            expect(c).toContain('active:text-accent-foreground');
            expect(c).toContain('focus-visible:text-accent-foreground');
            expect(c).toContain('focus-visible:ring-2');
        }
    });
});
