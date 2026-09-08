<?php

namespace App\Mail;

use App\Models\AcuseRecepcion;
use App\Servicios\ServicioAcusePdf;
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
 * Comprobante que se envía a AMBAS partes cuando una entrega queda
 * documentalmente CONCLUIDA: las dos firmas correctas + el acuse creado +
 * la entrega confirmada. Nunca por el simple hecho de crear una entrega
 * pendiente de firma.
 *
 * Encolado (`ShouldQueue`) y despachado siempre DESPUÉS del commit de la
 * transacción de negocio (`$afterCommit = true`); un fallo de SMTP se aísla
 * en el worker / `failed_jobs` y jamás revierte la entrega firmada.
 *
 * Todo el contenido proviene del snapshot inmutable del acuse
 * (`snapshot_entrega`), nunca de datos recalculados. No incluye rutas
 * internas, hashes, ids técnicos ni paths privados.
 */
class ComprobanteEntregaMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly AcuseRecepcion $acuse)
    {
        // Aunque las acciones ya despachan este correo fuera de la
        // transacción de negocio, blindamos también aquí: nunca antes del
        // commit.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $folio = $this->acuse->snapshot_entrega['entrega']['folio'] ?? $this->acuse->folio;

        return new Envelope(subject: 'Confirmación de entrega · '.$folio);
    }

    public function content(): Content
    {
        $s = $this->acuse->snapshot_entrega;

        return new Content(view: 'emails.comprobante', with: [
            'titulo' => 'Confirmación de entrega',
            'folio' => $s['entrega']['folio'] ?? $this->acuse->folio,
            'folioAcuse' => $this->acuse->folio,
            'fecha' => $s['entrega']['fecha_entrega'] ?? null,
            'empresaNombre' => $s['empresa']['nombre_comercial']
                ?? $s['empresa']['razon_social']
                ?? 'Empresa',
            'sucursal' => $s['sucursal']['nombre'] ?? '—',
            'colaborador' => $s['colaborador']['nombre_completo'] ?? '—',
            'numeroEmpleado' => $s['colaborador']['numero_empleado'] ?? '—',
            'encargado' => $s['encargado']['name'] ?? '—',
            'items' => array_map(fn (array $i): array => [
                'nombre' => $i['activo'] ?? $i['prenda'] ?? '—',
                'variante' => $i['talla'] ?? null,
                'cantidad' => (int) ($i['cantidad'] ?? 0),
            ], $s['items'] ?? []),
            'estado' => 'Entrega confirmada',
            'confirmacionFirmas' => 'Ambas partes confirmaron la operación mediante su firma: el colaborador que recibió los activos y el encargado que realizó la entrega.',
            'referenciaEtiqueta' => null,
            'referenciaValor' => null,
            'adjuntoNota' => $this->acuse->tienePdf()
                ? 'Se adjunta el comprobante de entrega en formato PDF.'
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
        $contenido = app(ServicioAcusePdf::class)->contenido($this->acuse);

        if ($contenido === null) {
            return [];
        }

        return [
            Attachment::fromData(fn (): string => $contenido, $this->acuse->nombreArchivoDescarga())
                ->withMime('application/pdf'),
        ];
    }

    /**
     * El logo de la empresa como data URI (sólo PNG/JPG/GIF existentes en el
     * disco público). `null` ante cualquier problema — el correo se envía
     * igual, sólo con el nombre de la empresa.
     */
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
