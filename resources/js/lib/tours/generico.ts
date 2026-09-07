import type { Tour } from './tipos';

/**
 * Tour mínimo reutilizable para cualquier módulo que use el patrón estándar
 * de listado (`EncabezadoPagina` + `SelectorVista`): enseña a volver al
 * módulo desde el menú, a leer el título/descripción de la pantalla y a usar
 * las acciones principales (crear, filtrar, exportar). No inventa selectores
 * frágiles por página — se apoya en los `data-tour` de los componentes
 * compartidos, así que funciona igual en todos los módulos que los usan.
 */
export function tourGenerico(
    id: string,
    nombreModulo: string,
    href: string,
    queHaceAqui: string,
    conSelectorVista = false,
): Tour {
    const pasos: Tour['pasos'] = [
        {
            selector: `a[href="${href}"]`,
            titulo: nombreModulo,
            texto: `Desde el menú siempre puedes volver aquí. ${queHaceAqui}`,
        },
        {
            selector: '[data-tour="titulo-pagina"]',
            titulo: 'Dónde estás',
            texto: 'El título confirma el módulo y, debajo, una descripción breve de para qué sirve esta pantalla.',
        },
    ];

    if (conSelectorVista) {
        pasos.push({
            selector: '[data-tour="selector-vista"]',
            titulo: 'Cards o tabla',
            texto: 'Cambia entre ver la información en tarjetas o en una tabla compacta. Tu preferencia se recuerda para la próxima vez.',
        });
    }

    pasos.push({
        selector: '[data-tour="acciones-pagina"]',
        titulo: 'Acciones principales',
        texto: 'Aquí están los botones para crear, importar o exportar, según lo que permita tu rol. Si no ves un botón, es que tu rol no tiene ese permiso.',
    });

    return { id, titulo: `Cómo usar ${nombreModulo}`, pasos };
}
