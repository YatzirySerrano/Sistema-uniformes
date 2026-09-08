<?php

namespace App\Mail;

use App\Models\AcuseDevolucion;
use App\Servicios\ServicioAcuseDevolucionPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comprobante que se envía a las partes correspondientes cuando una
 * devolución queda CONFIRMADA: ambas firmas + acuse válido + inventario
 * actualizado conforme a la lógica actual. Nunca antes.
 *
 * Misma filosofía que `ComprobanteEntregaMail`: encolado, despachado
 * después del commit, un fallo de SMTP no revierte nada. Todo el contenido
 * sale del snapshot inmutable del acuse (`snapshot_devolucion`).
 */
class ComprobanteDevolucionMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly AcuseDevolucion $acuse,
        public readonly ?string $folioEntregaOriginal = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $folio = $this->acuse->snapshot_devolucion['devolucion']['folio'] ?? $this->acuse->folio;

        return new Envelope(subject: 'Confirmación de devolución · '.$folio);
    }

    public function content(): Content
    {
        $s = $this->acuse->snapshot_devolucion;

        return new Content(view: 'emails.comprobante', with: [
            'titulo' => 'Confirmación de devolución',
            'folio' => $s['devolucion']['folio'] ?? $this->acuse->folio,
            'folioAcuse' => $this->acuse->folio,
            'fecha' => $s['devolucion']['fecha'] ?? null,
            'empresaNombre' => $s['empresa']['nombre_comercial']
                ?? $s['empresa']['razon_social']
                ?? 'Empresa',
            'sucursal' => $s['sucursal']['nombre'] ?? '—',
            'colaborador' => $s['colaborador']['nombre_completo'] ?? '—',
            'numeroEmpleado' => $s['colaborador']['numero_empleado'] ?? '—',
            'encargado' => $s['operador']['name'] ?? '—',
            'items' => array_map(fn (array $i): array => [
                'nombre' => $i['activo'] ?? '—',
                'variante' => $i['talla'] ?? null,
                'cantidad' => (int) ($i['cantidad'] ?? 0),
                'condicion' => $i['condicion'] ?? null,
            ], $s['items'] ?? []),
            'estado' => 'Devolución confirmada',
            'confirmacionFirmas' => 'Ambas partes confirmaron la operación mediante su firma: el colaborador que devolvió los activos y el encargado que recibió la devolución.',
            'referenciaEtiqueta' => $this->folioEntregaOriginal !== null ? 'Entrega de origen' : null,
            'referenciaValor' => $this->folioEntregaOriginal,
            'adjuntoNota' => $this->acuse->tienePdf()
                ? 'Se adjunta el comprobante de devolución en formato PDF.'
                : 'El comprobante en PDF se está generando y podrá descargarse desde el sistema en unos minutos.',
            'color' => $s['empresa']['color_principal'] ?? '#171717',
            'logo' => $this->logoDataUri($s['empresa']['logo_ruta'] ?? null),
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $contenido = app(ServicioAcuseDevolucionPdf::class)->contenido($this->acuse);

        if ($contenido === null) {
            return [];
        }

        return [
            Attachment::fromData(fn (): string => $contenido, $this->acuse->nombreArchivoDescarga())
                ->withMime('application/pdf'),
        ];
    }

    private function logoDataUri(?string $ruta): ?string
    {
        if ($ruta === null) {
            return null;
        }

        $mime = match (Str::lower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        try {
            if (! Storage::disk('public')->exists($ruta)) {
                return null;
            }

            $contenido = Storage::disk('public')->get($ruta);
        } catch (\Throwable) {
            return null;
        }

        return $contenido === null ? null : 'data:'.$mime.';base64,'.base64_encode($contenido);
    }
}
