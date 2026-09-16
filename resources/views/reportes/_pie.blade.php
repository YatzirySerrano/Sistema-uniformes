{{--
    Pie de página nativo de Chrome (Puppeteer `footerTemplate`): sólo admite
    estilos inline y las clases especiales `pageNumber`/`totalPages` — nunca
    la hoja de estilos compartida. Se pasa vía `Pdf::footerView()`.
--}}
<div style="width: 100%; font-size: 7.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #94a3b8; text-align: right; padding: 0 10mm;">
    Sistema de Uniformes · página <span class="pageNumber"></span> de <span class="totalPages"></span>
</div>
