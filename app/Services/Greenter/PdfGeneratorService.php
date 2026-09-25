<?php

namespace App\Services\Greenter;

use App\Models\Document;
use App\Services\Support\NumeroALetras;
use BaconQrCode\Renderer\Image\Svg;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class PdfGeneratorService
{
    public function generate(Document $document): string
    {
        $html = $this->renderHtml($document);

        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        $ruc = $document->company->ruc;
        $year = $document->issue_date->format('Y');
        $month = $document->issue_date->format('m');
        $filename = sprintf('%s-%s-%s.pdf', $document->type_code, $document->series, $document->correlative);
        $pdfPath = "tenants/{$ruc}/{$year}/{$month}/{$filename}";

        $disk = config('factos.storage_disk', 'local');
        Storage::disk($disk)->put($pdfPath, $pdfContent);

        return $pdfPath;
    }

    public function renderHtml(Document $document): string
    {
        $company = $document->company;
        $docTitle = match ($document->type_code) {
            '03' => 'BOLETA DE VENTA ELECTRÓNICA',
            '07' => 'NOTA DE CRÉDITO ELECTRÓNICA',
            '08' => 'NOTA DE DÉBITO ELECTRÓNICA',
            default => 'FACTURA ELECTRÓNICA',
        };

        $qrCodeDataUri = $this->generateQrDataUri($document);
        $montoLetras = NumeroALetras::convert($document->total, $document->currency);
        $formaPago = ($document->due_date && $document->due_date->gt($document->issue_date)) ? 'CRÉDITO' : 'CONTADO';
        $correlativePadded = str_pad((string) $document->correlative, 8, '0', STR_PAD_LEFT);

        $itemsRows = '';
        foreach ($document->items as $item) {
            $qty = number_format((float) $item->quantity, 2);
            $unitVal = number_format((float) $item->unit_value, 2);
            $itemTotal = number_format((float) $item->total, 2);
            $itemsRows .= "
                <tr>
                    <td class='text-center'>{$qty}</td>
                    <td class='text-center'>{$item->unit_code}</td>
                    <td>{$item->description}</td>
                    <td class='text-right'>{$unitVal}</td>
                    <td class='text-right'>{$itemTotal}</td>
                </tr>
            ";
        }

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <title>{$docTitle} {$document->series}-{$correlativePadded}</title>
            <style>
                body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 20px; }
                .header-table { width: 100%; margin-bottom: 20px; }
                .company-info { width: 55%; vertical-align: top; }
                .company-name { font-size: 16px; font-weight: bold; color: #1a56a0; text-transform: uppercase; }
                .company-details { font-size: 10px; color: #555; margin-top: 5px; line-height: 1.4; }
                .ruc-box { width: 40%; border: 2px solid #1a56a0; border-radius: 6px; text-align: center; padding: 10px; vertical-align: middle; }
                .ruc-number { font-size: 14px; font-weight: bold; margin-bottom: 5px; }
                .doc-type { font-size: 13px; font-weight: bold; color: #1a56a0; margin-bottom: 5px; }
                .doc-number { font-size: 14px; font-weight: bold; }
                .section-title { font-weight: bold; font-size: 11px; margin-bottom: 5px; border-bottom: 1px solid #1a56a0; padding-bottom: 3px; color: #1a56a0; }
                .info-table { width: 100%; margin-bottom: 15px; border-collapse: collapse; }
                .info-table td { padding: 4px; font-size: 10px; }
                .label { font-weight: bold; color: #444; width: 15%; }
                .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                .items-table th { background-color: #1a56a0; color: #fff; font-size: 10px; font-weight: bold; padding: 6px; text-align: left; }
                .items-table td { border-bottom: 1px solid #e2e8f0; padding: 6px; font-size: 10px; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .bottom-table { width: 100%; margin-top: 15px; }
                .qr-section { width: 45%; vertical-align: top; font-size: 9px; color: #666; }
                .totals-section { width: 50%; vertical-align: top; }
                .totals-table { width: 100%; border-collapse: collapse; }
                .totals-table td { padding: 4px 6px; font-size: 10px; }
                .totals-table .total-row { font-size: 12px; font-weight: bold; background-color: #f1f5f9; border-top: 2px solid #1a56a0; color: #1a56a0; }
                .legend-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px; font-size: 10px; font-weight: bold; margin-bottom: 10px; }
                .footer-text { margin-top: 20px; font-size: 9px; color: #888; text-align: center; border-top: 1px dashed #ccc; padding-top: 10px; }
            </style>
        </head>
        <body>
            <table class='header-table'>
                <tr>
                    <td class='company-info'>
                        <div class='company-name'>{$company->business_name}</div>
                        ".($company->trademark_name ? "<div style='font-size:11px; color:#555;'>{$company->trademark_name}</div>" : '')."
                        <div class='company-details'>
                            Dirección: {$company->address}<br>
                            Ubigeo: {$company->ubigeo} - {$company->district}, {$company->province}, {$company->department}
                        </div>
                    </td>
                    <td class='ruc-box'>
                        <div class='ruc-number'>R.U.C. {$company->ruc}</div>
                        <div class='doc-type'>{$docTitle}</div>
                        <div class='doc-number'>{$document->series}-{$correlativePadded}</div>
                    </td>
                </tr>
            </table>

            <div class='section-title'>DATOS DEL CLIENTE</div>
            <table class='info-table'>
                <tr>
                    <td class='label'>Cliente:</td>
                    <td>{$document->client_name}</td>
                    <td class='label'>Fecha Emisión:</td>
                    <td>{$document->issue_date->format('d/m/Y')} {$document->issue_time}</td>
                </tr>
                <tr>
                    <td class='label'>RUC/DNI:</td>
                    <td>{$document->client_doc_number}</td>
                    <td class='label'>Moneda:</td>
                    <td>{$document->currency}</td>
                </tr>
                <tr>
                    <td class='label'>Dirección:</td>
                    <td>".($document->client_address ?: '-')."</td>
                    <td class='label'>Forma de Pago:</td>
                    <td>{$formaPago}</td>
                </tr>
            </table>

            <table class='items-table'>
                <thead>
                    <tr>
                        <th style='width: 10%;' class='text-center'>CANT.</th>
                        <th style='width: 10%;' class='text-center'>UNIDAD</th>
                        <th style='width: 50%;'>DESCRIPCIÓN</th>
                        <th style='width: 15%;' class='text-right'>V. UNIT</th>
                        <th style='width: 15%;' class='text-right'>TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemsRows}
                </tbody>
            </table>

            <div class='legend-box'>
                {$montoLetras}
            </div>

            <table class='bottom-table'>
                <tr>
                    <td class='qr-section'>
                        ".($qrCodeDataUri ? "<img src='{$qrCodeDataUri}' style='width: 100px; height: 100px; margin-bottom: 5px;'><br>" : '')."
                        <strong>DigestValue (Hash):</strong><br>
                        <code>{$document->hash}</code><br><br>
                        Representación impresa de la {$docTitle}.<br>
                        Autorizado mediante Resolución de Superintendencia SUNAT.
                    </td>
                    <td class='totals-section'>
                        <table class='totals-table'>
                            <tr>
                                <td>Op. Gravadas:</td>
                                <td class='text-right'>{$document->currency} ".number_format((float) $document->total_taxable, 2).'</td>
                            </tr>
                            '.((float) $document->total_exonerated > 0 ? "
                            <tr>
                                <td>Op. Exoneradas:</td>
                                <td class='text-right'>{$document->currency} ".number_format((float) $document->total_exonerated, 2).'</td>
                            </tr>' : '').'
                            '.((float) $document->total_unaffected > 0 ? "
                            <tr>
                                <td>Op. Inafectas:</td>
                                <td class='text-right'>{$document->currency} ".number_format((float) $document->total_unaffected, 2).'</td>
                            </tr>' : '')."
                            <tr>
                                <td>I.G.V. (18%):</td>
                                <td class='text-right'>{$document->currency} ".number_format((float) $document->total_igv, 2)."</td>
                            </tr>
                            <tr class='total-row'>
                                <td>IMPORTE TOTAL:</td>
                                <td class='text-right'>{$document->currency} ".number_format((float) $document->total, 2)."</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class='footer-text'>
                FactosAPI — Sistema de Emisión Electrónica SUNAT
            </div>
        </body>
        </html>
        ";
    }

    private function generateQrDataUri(Document $document): ?string
    {
        try {
            $qrContent = sprintf(
                '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|',
                $document->company->ruc,
                $document->type_code,
                $document->series,
                $document->correlative,
                $document->total_igv,
                $document->total,
                $document->issue_date->format('Y-m-d'),
                $document->client_doc_type,
                $document->client_doc_number,
                $document->hash ?? ''
            );

            $renderer = new Svg;
            $renderer->setHeight(120);
            $renderer->setWidth(120);
            $writer = new Writer($renderer);
            $svg = @$writer->writeString($qrContent);

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }
}
