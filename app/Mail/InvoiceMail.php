<?php

namespace App\Mail;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Document $document,
        public bool $useFacturadorSender = false
    ) {
    }

    public function envelope(): Envelope
    {
        $company = $this->document->company;
        $subject = $company->email_template_settings['subject']
            ?? "Comprobante Electrónico {$this->document->series}-{$this->document->correlative} - {$company->business_name}";

        if (!$this->useFacturadorSender && $company->hasCustomMailConfig()) {
            $fromAddress = $company->mail_from_address
                ?: ($company->mail_username ?: config('mail.from.address'));
            $fromName = $company->mail_from_name
                ?: ($company->trademark_name ?: ($company->business_name ?: config('mail.from.name')));

            $from = new Address($fromAddress, $fromName);
            $replyTo = [$from];
        } else {
            // Envío realizado por el facturador (por defecto, fallback o notificación a la empresa)
            $fromAddress = config('mail.from.address');
            $fromName = $company->mail_from_name
                ?: ($company->trademark_name ?: ($company->business_name ?: config('mail.from.name')));

            $from = new Address($fromAddress, $fromName);

            // Los clientes deben responder a la empresa emisora
            $replyAddress = $company->mail_from_address
                ?: ($company->mail_username ?: (!empty($company->company_copy_emails[0]) ? $company->company_copy_emails[0] : $fromAddress));

            $replyTo = [new Address($replyAddress, $fromName)];
        }

        return new Envelope(
            from: $from,
            replyTo: $replyTo,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->renderHtml(),
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];
        $disk = config('factos.storage_disk', 'local');

        if ($this->document->xml_path && Storage::disk($disk)->exists($this->document->xml_path)) {
            $attachments[] = Attachment::fromStorageDisk($disk, $this->document->xml_path)
                ->as(basename($this->document->xml_path))
                ->withMime('application/xml');
        }

        if ($this->document->pdf_path && Storage::disk($disk)->exists($this->document->pdf_path)) {
            $attachments[] = Attachment::fromStorageDisk($disk, $this->document->pdf_path)
                ->as(basename($this->document->pdf_path))
                ->withMime('application/pdf');
        }

        return $attachments;
    }

    private function renderHtml(): string
    {
        $company = $this->document->company;
        $docTitle = match ($this->document->type_code) {
            '03' => 'Boleta de Venta Electrónica',
            '07' => 'Nota de Crédito Electrónica',
            '08' => 'Nota de Débito Electrónica',
            default => 'Factura Electrónica',
        };

        return "
        <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
            <h2 style='color: #1a56a0; margin-top: 0;'>{$company->business_name}</h2>
            <p>Estimado(a) <strong>{$this->document->client_name}</strong>,</p>
            <p>Adjuntamos su <strong>{$docTitle} {$this->document->series}-{$this->document->correlative}</strong> emitida el {$this->document->issue_date->format('d/m/Y')}.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr style='background-color: #f8fafc;'>
                    <td style='padding: 8px; border: 1px solid #e2e8f0;'><strong>Total:</strong></td>
                    <td style='padding: 8px; border: 1px solid #e2e8f0;'>{$this->document->currency} {$this->document->total}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border: 1px solid #e2e8f0;'><strong>Estado SUNAT:</strong></td>
                    <td style='padding: 8px; border: 1px solid #e2e8f0;'>Aceptado</td>
                </tr>
            </table>
            <p style='color: #64748b; font-size: 13px;'>Encontrará el archivo XML firmado y la representación impresa en PDF como archivos adjuntos a este mensaje.</p>
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
            <p style='color: #94a3b8; font-size: 11px; text-align: center;'>FactosAPI — Comprobantes Electrónicos</p>
        </div>
        ";
    }
}
