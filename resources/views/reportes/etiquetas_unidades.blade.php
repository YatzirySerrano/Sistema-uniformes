<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiquetas de unidades</title>
    <style>
        @page { margin: 10mm 8mm; }
        body { font-family: "Helvetica", "Arial", sans-serif; color: #111; margin: 0; }
        .grid { width: 100%; }
        .fila { display: table; width: 100%; table-layout: fixed; margin-bottom: 4mm; }
        .etiqueta {
            display: table-cell;
            width: 33.33%;
            vertical-align: top;
            padding: 3mm;
        }
        .etiqueta-caja {
            border: 0.5pt solid #999;
            border-radius: 3pt;
            padding: 3mm;
            height: 32mm;
        }
        .etiqueta-contenido { display: table; width: 100%; }
        .etiqueta-qr { display: table-cell; width: 24mm; vertical-align: middle; }
        .etiqueta-qr img { width: 22mm; height: 22mm; }
        .etiqueta-texto { display: table-cell; vertical-align: middle; padding-left: 2mm; }
        .etiqueta-empresa { font-size: 6.5pt; color: #777; text-transform: uppercase; letter-spacing: 0.3pt; }
        .etiqueta-nombre { font-size: 9pt; font-weight: bold; line-height: 1.15; margin: 0.5mm 0; }
        .etiqueta-codigo { font-size: 8.5pt; font-family: "Courier New", monospace; color: #222; }
    </style>
</head>
<body>
    <div class="grid">
        @foreach ($unidades->chunk(3) as $fila)
            <div class="fila">
                @foreach ($fila as $unidad)
                    <div class="etiqueta">
                        <div class="etiqueta-caja">
                            <div class="etiqueta-contenido">
                                <div class="etiqueta-qr">
                                    <img src="{{ $qr->pngDataUri($unidad) }}" alt="QR">
                                </div>
                                <div class="etiqueta-texto">
                                    <div class="etiqueta-empresa">{{ $unidad->empresa->codigo }}</div>
                                    <p class="etiqueta-nombre">{{ $unidad->activo->nombre }}</p>
                                    <div class="etiqueta-codigo">{{ $unidad->codigo }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</body>
</html>
