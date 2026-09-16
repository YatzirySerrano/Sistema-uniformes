{{--
    Estilos compartidos por todos los reportes ejecutivos (Browsershot/
    Chromium — CSS moderno real, Grid/Flexbox). Los acuses firmados NO usan
    esto, siguen con su propia hoja de estilo en DomPDF.
--}}
<style>
    :root {
        --marca: {{ $colorPrincipal }};
        --texto: #0f172a;
        --texto-suave: #475569;
        --texto-tenue: #64748b;
        --borde: #e2e8f0;
        --fondo-tarjeta: #f8fafc;
    }

    * { box-sizing: border-box; }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
        color: var(--texto);
        font-size: 10px;
        margin: 0;
    }

    .encabezado {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 3px solid var(--marca);
        padding-bottom: 10px;
        margin-bottom: 14px;
    }

    .encabezado .identidad { display: flex; align-items: center; gap: 12px; }
    .encabezado img.logo { max-height: 44px; max-width: 150px; }
    .encabezado .empresa { font-size: 11px; font-weight: 600; color: var(--texto-suave); margin: 0 0 2px; }
    .encabezado h1 { font-size: 18px; margin: 0; color: var(--texto); }
    .encabezado .meta { text-align: right; font-size: 8.5px; color: var(--texto-tenue); line-height: 1.5; }
    .encabezado .meta b { color: var(--texto-suave); }

    .filtros-aplicados {
        font-size: 8.5px;
        color: var(--texto-tenue);
        font-style: italic;
        margin: 0 0 14px;
    }

    .kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 10px;
        margin-bottom: 16px;
    }

    .kpi-tarjeta {
        border: 1px solid var(--borde);
        background: var(--fondo-tarjeta);
        border-radius: 8px;
        padding: 10px 12px;
    }

    .kpi-tarjeta .etiqueta { font-size: 8px; color: var(--texto-tenue); margin: 0 0 4px; }
    .kpi-tarjeta .valor { font-size: 18px; font-weight: 700; color: var(--texto); margin: 0; }

    .glosario-kpis { font-size: 7.5px; color: var(--texto-tenue); line-height: 1.6; margin: -8px 0 16px; }
    .glosario-kpis b { color: var(--texto-suave); }

    .graficas {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .grafica-tarjeta {
        border: 1px solid var(--borde);
        border-radius: 8px;
        padding: 10px 12px;
        page-break-inside: avoid;
    }

    .grafica-tarjeta .titulo { font-size: 10px; font-weight: 700; color: var(--texto); margin: 0 0 6px; }
    .grafica-lienzo { width: 100%; min-height: 220px; }

    table.datos { width: 100%; border-collapse: collapse; }
    table.datos th, table.datos td { border: 1px solid var(--borde); padding: 5px 7px; text-align: left; font-size: 9px; }
    table.datos th { background: var(--texto); color: #ffffff; font-weight: 600; }
    table.datos tbody tr:nth-child(even) { background: var(--fondo-tarjeta); }
    table.datos thead { display: table-header-group; }
    table.datos tr { page-break-inside: avoid; }

    .vacio { padding: 12px 0; color: var(--texto-tenue); font-style: italic; }

    h2.seccion { font-size: 11px; color: var(--texto-suave); margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.04em; }
</style>
