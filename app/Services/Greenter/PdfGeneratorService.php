<?php

namespace App\Services\Greenter;

use App\Models\Despatch;
use App\Models\Document;
use App\Services\Support\NumeroALetras;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class PdfGeneratorService
{
    /**
     * Render the document or despatch PDF directly into binary bytes (in-memory).
     */
    public function renderPdfBytes(Document|Despatch $model): string
    {
        $html = ($model instanceof Document)
            ? $this->renderHtml($model)
            : $this->renderDespatchHtml($model);

        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function generate(Document $document): string
    {
        $pdfContent = $this->renderPdfBytes($document);

        $ruc = $document->company_data['ruc'] ?? $document->company?->ruc ?? '00000000000';
        $year = $document->issue_date->format('Y');
        $month = $document->issue_date->format('m');
        $filename = sprintf('%s-%s-%s.pdf', $document->type_code, $document->series, $document->correlative);
        $pdfPath = "tenants/{$ruc}/{$year}/{$month}/{$filename}";

        $disk = config('factos.storage_disk', 'local');
        try {
            Storage::disk($disk)->put($pdfPath, $pdfContent);
        } catch (\Throwable) {
            // Storage write is optional; endpoint renders dynamically
        }

        return $pdfPath;
    }

    public function renderHtml(Document $document): string
    {
        $document->loadMissing(['company', 'items', 'appliedAdvances']);

        // Extraer datos desnormalizados de la empresa para garantizar inmutabilidad histórica
        $cData = $document->company_data ?? [];
        $businessName = $cData['business_name'] ?? $document->company?->business_name ?? 'EMPRESA EMISORA';
        $trademarkName = $cData['trademark_name'] ?? $document->company?->trademark_name ?? '';
        $ruc = $cData['ruc'] ?? $document->company?->ruc ?? '';
        $address = $cData['address'] ?? $document->company?->address ?? '';
        $ubigeo = $cData['ubigeo'] ?? $document->company?->ubigeo ?? '';
        $district = $cData['district'] ?? $document->company?->district ?? '';
        $province = $cData['province'] ?? $document->company?->province ?? '';
        $department = $cData['department'] ?? $document->company?->department ?? '';

        $docTitle = match ($document->type_code) {
            '03' => 'BOLETA DE VENTA ELECTRÓNICA',
            '07' => 'NOTA DE CRÉDITO ELECTRÓNICA',
            '08' => 'NOTA DE DÉBITO ELECTRÓNICA',
            default => 'FACTURA ELECTRÓNICA',
        };

        if ($document->is_advance) {
            $docTitle .= ' (PAGO ANTICIPADO)';
        }

        $qrCodeDataUri = $this->generateQrDataUri($document, $ruc);
        $montoLetras = NumeroALetras::convert($document->total, $document->currency);
        $formaPago = ($document->due_date && $document->due_date->gt($document->issue_date)) ? 'CRÉDITO' : strtoupper($document->payment_method ?? 'CONTADO');
        $correlativePadded = str_pad((string) $document->correlative, 8, '0', STR_PAD_LEFT);
        $testBanner = !$document->is_production
            ? "<div style='background-color: #fee2e2; border: 1px dashed #ef4444; color: #b91c1c; text-align: center; padding: 6px; font-weight: bold; font-size: 11px; margin-bottom: 12px; border-radius: 4px;'>COMPROBANTE DE PRUEBA / BETA — SIN VALOR TRIBUTARIO</div>"
            : '';

        // Filas de Items
        $itemsRows = '';
        foreach ($document->items as $item) {
            $qty = number_format((float) $item->quantity, 2);
            $unitVal = number_format((float) $item->unit_value, 2);
            $itemTotal = number_format((float) $item->total, 2);
            $codeHtml = $item->internal_code ? "<span style='font-family: monospace; font-size: 9px; color: #555;'>[{$item->internal_code}] </span>" : '';
            $itemsRows .= "
                <tr>
                    <td class='text-center'>{$qty}</td>
                    <td class='text-center'>{$item->unit_code}</td>
                    <td>{$codeHtml}{$item->description}</td>
                    <td class='text-right'>{$unitVal}</td>
                    <td class='text-right'>{$itemTotal}</td>
                </tr>
            ";
        }

        // Anticipos aplicados (si existen)
        $advancesSection = '';
        $totalAdvancesAmount = 0.00;
        if ($document->appliedAdvances && $document->appliedAdvances->isNotEmpty()) {
            $advRows = '';
            foreach ($document->appliedAdvances as $adv) {
                $totalAdvancesAmount += (float) $adv->amount;
                $formattedAmount = number_format((float) $adv->amount, 2);
                $advDate = $adv->advance_issue_date ? $adv->advance_issue_date->format('d/m/Y') : '-';
                $advRows .= "
                    <tr>
                        <td>ANTICIPO: {$adv->advance_series}-{$adv->advance_correlative} ({$advDate})</td>
                        <td>{$adv->description}</td>
                        <td class='text-right'>-{$document->currency} {$formattedAmount}</td>
                    </tr>
                ";
            }
            $advancesSection = "
                <div class='section-title' style='margin-top: 12px;'>ANTICIPOS APLICADOS (DEDUCCIONES)</div>
                <table class='info-table' style='border: 1px solid #e2e8f0; margin-bottom: 10px;'>
                    <tr style='background-color: #f1f5f9; font-weight: bold;'>
                        <td style='width: 40%;'>Comprobante Anticipo</td>
                        <td style='width: 40%;'>Descripción</td>
                        <td style='width: 20%; text-align: right;'>Monto Deducido</td>
                    </tr>
                    {$advRows}
                </table>
            ";
        }

        // Observaciones generales
        $observationsHtml = '';
        if ($document->observations) {
            $observationsHtml = "
                <div style='margin-top: 10px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; font-size: 10px;'>
                    <strong style='color: #1a56a0;'>OBSERVACIONES:</strong><br>
                    <span style='white-space: pre-wrap; color: #334155;'>" . htmlspecialchars($document->observations) . "</span>
                </div>
            ";
        }

        // Información de Nota de Crédito / Débito (Dice / Debe decir / Motivo)
        $noteHtml = '';
        if ($document->note_data) {
            $nd = $document->note_data;
            $refSeries = $nd['reference_series'] ?? $nd['affected_series'] ?? '';
            $refCorr = $nd['reference_correlative'] ?? $nd['affected_correlative'] ?? '';
            $refDoc = ($refSeries && $refCorr) ? "{$refSeries}-{$refCorr}" : ($refSeries ?: $refCorr ?: '-');
            $reasonDesc = $nd['reason_description'] ?? $nd['reason'] ?? $nd['code'] ?? '';
            $noteHtml = "
                <div style='margin-top: 8px; background-color: #eff6ff; border: 1px solid #93c5fd; border-radius: 4px; padding: 6px 10px; font-size: 10px;'>
                    <strong>DOCUMENTO QUE MODIFICA:</strong> {$refDoc} | <strong>MOTIVO:</strong> {$reasonDesc}
                    " . (!empty($nd['dice']) ? "<br><strong>DICE:</strong> " . htmlspecialchars($nd['dice']) : '') . "
                    " . (!empty($nd['debe_decir']) ? "<br><strong>DEBE DECIR:</strong> " . htmlspecialchars($nd['debe_decir']) : '') . "
                </div>
            ";
        }

        // Detracción
        $detractionHtml = '';
        if ($document->detraction) {
            $det = $document->detraction;
            $detAmount = number_format((float) ($det['amount'] ?? 0), 2);
            $detractionHtml = "
                <div style='margin-top: 8px; background-color: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 4px; padding: 6px 10px; font-size: 10px;'>
                    <strong style='color: #9d174d;'>INFORMACIÓN DE LA DETRACCIÓN (SPOT):</strong><br>
                    Operación sujeta al Sistema de Pago de Obligaciones Tributarias con el Gobierno Central.<br>
                    <strong>Bien/Servicio:</strong> " . ($det['code'] ?? '034') . " | <strong>Porcentaje:</strong> " . ($det['percent'] ?? 10) . "% | <strong>Monto Detracción:</strong> S/ {$detAmount} | <strong>Cta Banco de la Nación:</strong> " . ($det['bank_account'] ?? '-') . "
                </div>
            ";
        }

        // Pie de Página personalizado (Cuentas Bancarias, Otras Observaciones, Contacto)
        $piePaginaHtml = '';
        $pie = $document->pie_pagina;
        if ($pie) {
            $banksHtml = '';
            if (!empty($pie['cuentas_bancarias']) && is_array($pie['cuentas_bancarias'])) {
                $bankRows = '';
                foreach ($pie['cuentas_bancarias'] as $b) {
                    $banco = htmlspecialchars($b['banco'] ?? $b['bank_name'] ?? '');
                    $cuenta = htmlspecialchars($b['numero_cuenta'] ?? $b['account_number'] ?? '');
                    $cci = htmlspecialchars($b['cci'] ?? '');
                    $moneda = htmlspecialchars($b['moneda'] ?? $b['currency'] ?? 'PEN');
                    $bankRows .= "<tr><td><strong>{$banco}</strong> ({$moneda})</td><td>Cuenta: {$cuenta}</td><td>CCI: {$cci}</td></tr>";
                }
                $banksHtml = "
                    <div style='margin-top: 10px;'>
                        <strong style='color: #1a56a0; font-size: 10px;'>CUENTAS BANCARIAS:</strong>
                        <table style='width: 100%; font-size: 9px; border-collapse: collapse; margin-top: 4px;'>
                            {$bankRows}
                        </table>
                    </div>
                ";
            }

            $otrasObsHtml = '';
            if (!empty($pie['otras_observaciones']) && is_array($pie['otras_observaciones'])) {
                $itemsList = implode('<br>• ', array_map('htmlspecialchars', $pie['otras_observaciones']));
                $otrasObsHtml = "
                    <div style='margin-top: 8px; font-size: 9px; color: #475569;'>
                        <strong style='color: #1a56a0;'>OTRAS NOTAS:</strong><br>• {$itemsList}
                    </div>
                ";
            }

            $contactoHtml = '';
            if (!empty($pie['datos_contacto']) && is_array($pie['datos_contacto'])) {
                $contactInfo = [];
                if (!empty($pie['datos_contacto']['telefonos'])) {
                    $contactInfo[] = 'Tel: ' . implode(', ', (array) $pie['datos_contacto']['telefonos']);
                }
                if (!empty($pie['datos_contacto']['correos'])) {
                    $contactInfo[] = 'Email: ' . implode(', ', (array) $pie['datos_contacto']['correos']);
                }
                if ($contactInfo) {
                    $contactoHtml = "<div style='margin-top: 6px; font-size: 9px; color: #64748b;'>" . implode(' | ', $contactInfo) . "</div>";
                }
            }

            if ($banksHtml || $otrasObsHtml || $contactoHtml) {
                $piePaginaHtml = "
                    <div style='margin-top: 15px; border-top: 2px solid #cbd5e1; padding-top: 8px;'>
                        {$banksHtml}
                        {$otrasObsHtml}
                        {$contactoHtml}
                    </div>
                ";
            }
        }

        // Totales con desglose de anticipos
        $anticiposTotalesRow = '';
        if ($totalAdvancesAmount > 0) {
            $formattedAdv = number_format($totalAdvancesAmount, 2);
            $anticiposTotalesRow = "
                <tr>
                    <td style='color: #dc2626;'>(-) Total Anticipos:</td>
                    <td class='text-right' style='color: #dc2626;'>{$document->currency} -{$formattedAdv}</td>
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
                body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 15px; }
                .header-table { width: 100%; margin-bottom: 15px; }
                .company-info { width: 55%; vertical-align: top; }
                .company-name { font-size: 15px; font-weight: bold; color: #1a56a0; text-transform: uppercase; }
                .company-details { font-size: 10px; color: #555; margin-top: 4px; line-height: 1.3; }
                .ruc-box { width: 40%; border: 2px solid #1a56a0; border-radius: 6px; text-align: center; padding: 8px; vertical-align: middle; }
                .ruc-number { font-size: 14px; font-weight: bold; margin-bottom: 4px; }
                .doc-type { font-size: 12px; font-weight: bold; color: #1a56a0; margin-bottom: 4px; }
                .doc-number { font-size: 14px; font-weight: bold; }
                .section-title { font-weight: bold; font-size: 11px; margin-bottom: 4px; border-bottom: 1px solid #1a56a0; padding-bottom: 2px; color: #1a56a0; }
                .info-table { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
                .info-table td { padding: 3px 4px; font-size: 10px; }
                .label { font-weight: bold; color: #444; width: 15%; }
                .items-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                .items-table th { background-color: #1a56a0; color: #fff; font-size: 10px; font-weight: bold; padding: 5px; text-align: left; }
                .items-table td { border-bottom: 1px solid #e2e8f0; padding: 5px; font-size: 10px; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .bottom-table { width: 100%; margin-top: 10px; }
                .qr-section { width: 45%; vertical-align: top; font-size: 9px; color: #666; }
                .totals-section { width: 50%; vertical-align: top; }
                .totals-table { width: 100%; border-collapse: collapse; }
                .totals-table td { padding: 3px 6px; font-size: 10px; }
                .totals-table .total-row { font-size: 12px; font-weight: bold; background-color: #f1f5f9; border-top: 2px solid #1a56a0; color: #1a56a0; }
                .legend-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px; font-size: 10px; font-weight: bold; margin-bottom: 8px; }
                .footer-text { margin-top: 15px; font-size: 9px; color: #888; text-align: center; border-top: 1px dashed #ccc; padding-top: 8px; }
            </style>
        </head>
        <body>
            {$testBanner}
            <table class='header-table'>
                <tr>
                    <td class='company-info'>
                        <div class='company-name'>{$businessName}</div>
                        " . ($trademarkName ? "<div style='font-size:11px; color:#555;'>{$trademarkName}</div>" : '') . "
                        <div class='company-details'>
                            Dirección: {$address}<br>
                            Ubigeo: {$ubigeo} - {$district}, {$province}, {$department}
                        </div>
                    </td>
                    <td class='ruc-box'>
                        <div class='ruc-number'>R.U.C. {$ruc}</div>
                        <div class='doc-type'>{$docTitle}</div>
                        <div class='doc-number'>{$document->series}-{$correlativePadded}</div>
                    </td>
                </tr>
            </table>

            <div class='section-title'>DATOS DEL CLIENTE / RECEPTOR</div>
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
                    <td>" . ($document->client_address ?: '-') . "</td>
                    <td class='label'>Forma de Pago:</td>
                    <td>{$formaPago}</td>
                </tr>
                " . ($document->purchase_order || $document->plate_number ? "
                <tr>
                    <td class='label'>" . ($document->purchase_order ? 'O/C:' : '') . '</td>
                    <td>' . ($document->purchase_order ?: '') . "</td>
                    <td class='label'>" . ($document->plate_number ? 'Placa:' : '') . '</td>
                    <td>' . ($document->plate_number ?: '') . '</td>
                </tr>
                ' : '') . "
            </table>

            {$noteHtml}
            {$detractionHtml}

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

            {$advancesSection}

            <div class='legend-box'>
                SON: {$montoLetras}
            </div>

            <table class='bottom-table'>
                <tr>
                    <td class='qr-section'>
                        " . ($qrCodeDataUri ? "<img src='{$qrCodeDataUri}' style='width: 90px; height: 90px; margin-bottom: 4px;'><br>" : '') . "
                        <strong>DigestValue (Hash):</strong><br>
                        <code>" . ($document->hash ?: 'Generando firma digital...') . "</code><br><br>
                        Representación impresa de la {$docTitle}.<br>
                        Autorizado mediante Resolución de Superintendencia SUNAT.
                    </td>
                    <td class='totals-section'>
                        <table class='totals-table'>
                            <tr>
                                <td>Op. Gravadas:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_taxable, 2) . '</td>
                            </tr>
                            ' . ((float) ($document->total_exportation ?? 0) > 0 ? "
                            <tr>
                                <td>Op. Exportación:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_exportation, 2) . '</td>
                            </tr>' : '') . '
                            ' . ((float) $document->total_exonerated > 0 ? "
                            <tr>
                                <td>Op. Exoneradas:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_exonerated, 2) . '</td>
                            </tr>' : '') . '
                            ' . ((float) $document->total_unaffected > 0 ? "
                            <tr>
                                <td>Op. Inafectas:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_unaffected, 2) . '</td>
                            </tr>' : '') . '
                            ' . ((float) ($document->total_free ?? 0) > 0 ? "
                            <tr>
                                <td>Op. Gratuitas:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_free, 2) . '</td>
                            </tr>' : '') . '
                            ' . ((float) $document->total_discount > 0 ? "
                            <tr>
                                <td>Descuento Total:</td>
                                <td class='text-right'>{$document->currency} -" . number_format((float) $document->total_discount, 2) . '</td>
                            </tr>' : '') . "
                            {$anticiposTotalesRow}
                            <tr>
                                <td>I.G.V. (18%):</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_igv, 2) . '</td>
                            </tr>
                            ' . ((float) $document->total_icbper > 0 ? "
                            <tr>
                                <td>I.C.B.P.E.R.:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total_icbper, 2) . '</td>
                            </tr>' : '') . "
                            <tr class='total-row'>
                                <td>IMPORTE TOTAL:</td>
                                <td class='text-right'>{$document->currency} " . number_format((float) $document->total, 2) . "</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            {$observationsHtml}
            {$piePaginaHtml}

            <div class='footer-text'>
                FactosAPI — Sistema de Emisión Electrónica SUNAT
            </div>
        </body>
        </html>
        ";
    }

    private function generateQrDataUri(Document $document, string $ruc): ?string
    {
        try {
            $qrContent = sprintf(
                '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|',
                $ruc,
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

            $renderer = new ImageRenderer(
                new RendererStyle(120),
                new SvgImageBackEnd
            );
            $writer = new Writer($renderer);
            $svg = $writer->writeString($qrContent);

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }

    public function generateDespatchPdf(Despatch $despatch): string
    {
        $pdfContent = $this->renderPdfBytes($despatch);

        $ruc = $despatch->company_data['ruc'] ?? $despatch->company?->ruc ?? '00000000000';
        $year = $despatch->issue_date->format('Y');
        $month = $despatch->issue_date->format('m');
        $filename = sprintf('%s-%s-%s.pdf', $despatch->type_code, $despatch->series, $despatch->correlative);
        $pdfPath = "tenants/{$ruc}/{$year}/{$month}/despatches/{$filename}";

        $disk = config('factos.storage_disk', 'local');
        try {
            Storage::disk($disk)->put($pdfPath, $pdfContent);
        } catch (\Throwable) {
            // Storage write is optional; endpoint renders dynamically
        }

        return $pdfPath;
    }

    public function renderDespatchHtml(Despatch $despatch): string
    {
        $despatch->loadMissing(['company', 'items']);

        // Extraer datos desnormalizados de la empresa
        $cData = $despatch->company_data ?? [];
        $businessName = $cData['business_name'] ?? $despatch->company?->business_name ?? 'EMPRESA EMISORA';
        $ruc = $cData['ruc'] ?? $despatch->company?->ruc ?? '';
        $address = $cData['address'] ?? $despatch->company?->address ?? '';
        $district = $cData['district'] ?? $despatch->company?->district ?? '';
        $province = $cData['province'] ?? $despatch->company?->province ?? '';
        $department = $cData['department'] ?? $despatch->company?->department ?? '';

        $correlativePadded = str_pad((string) $despatch->correlative, 8, '0', STR_PAD_LEFT);
        $qrCodeDataUri = $this->generateDespatchQrDataUri($despatch, $ruc);

        $itemsRows = '';
        foreach ($despatch->items as $item) {
            $qty = number_format((float) $item->quantity, 2);
            $code = $item->internal_code ?: '-';
            $itemsRows .= "
            <tr>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; font-family: monospace;'>{$code}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0;'>{$item->description}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; text-align: center;'>{$item->unit_code}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>{$qty}</td>
            </tr>
            ";
        }

        // Transporte: Público vs Privado
        $certVehicular = $despatch->vehicle_certificate ? " | TUCE/Certificado: {$despatch->vehicle_certificate}" : '';
        $transporteInfo = $despatch->isPublicTransport()
            ? "<strong>MODALIDAD: TRANSPORTE PÚBLICO</strong><br>
               <strong>Transportista:</strong> {$despatch->carrier_name} (RUC: {$despatch->carrier_doc_number})<br>
               <strong>N° Registro MTC:</strong> " . ($despatch->carrier_mtc ?: 'NO ESPECIFICADO')
            : "<strong>MODALIDAD: TRANSPORTE PRIVADO</strong><br>
               <strong>Vehículo Principal:</strong> Placa {$despatch->vehicle_plate}{$certVehicular}<br>
               " . ($despatch->secondary_vehicle_plate ? "<strong>Vehículo Secundario:</strong> Placa {$despatch->secondary_vehicle_plate}<br>" : '') . "
               <strong>Conductor:</strong> {$despatch->driver_name} (DNI/Doc: {$despatch->driver_doc_number} | Licencia: {$despatch->driver_license})";

        // Peso referencial banner
        $referentialWeightNotice = ($despatch->is_referential_weight || str_contains(strtoupper($despatch->observations ?? ''), 'REFERENCIAL'))
            ? "<div style='display: inline-block; background-color: #fef08a; border: 1px solid #eab308; color: #854d0e; padding: 3px 8px; font-weight: bold; font-size: 10px; border-radius: 4px; margin-left: 10px;'>EL PESO ES REFERENCIAL</div>"
            : '';

        // Documentos relacionados (ej: facturas de compras)
        $relDocsHtml = '';
        if ($despatch->related_documents && is_array($despatch->related_documents)) {
            $docsList = [];
            foreach ($despatch->related_documents as $rd) {
                $docNum = $rd['document_number'] ?? (($rd['series'] ?? '') . '-' . ($rd['correlative'] ?? ''));
                $suppInfo = '';
                if (!empty($rd['supplier_name']) || !empty($rd['supplier_ruc'])) {
                    $suppInfo = " — Proveedor: " . ($rd['supplier_name'] ?? '') . " (RUC " . ($rd['supplier_ruc'] ?? '') . ")";
                } elseif (!empty($rd['company_number'])) {
                    $suppInfo = " (RUC {$rd['company_number']})";
                }
                $docsList[] = ($rd['type_name'] ?? 'Factura') . ' N° ' . $docNum . $suppInfo;
            }
            if ($docsList) {
                $relDocsHtml = "
                    <div class='section-box' style='margin-top: 8px;'>
                        <strong>DOCUMENTOS SUSTENTATORIOS / RELACIONADOS:</strong><br>
                        " . implode('<br>', $docsList) . "
                    </div>
                ";
            }
        }

        // Observaciones
        $obsHtml = '';
        if ($despatch->observations) {
            $obsHtml = "
                <div class='section-box' style='margin-top: 8px;'>
                    <strong>OBSERVACIONES:</strong><br>
                    <span style='white-space: pre-wrap; color: #334155;'>" . htmlspecialchars($despatch->observations) . "</span>
                </div>
            ";
        }

        $guiaTitle = ($despatch->type_code === '31') ? 'GUÍA DE REMISIÓN TRANSPORTISTA' : 'GUÍA DE REMISIÓN REMITENTE';

        $testBanner = !$despatch->is_production
            ? "<div style='background-color: #fee2e2; border: 1px dashed #ef4444; color: #b91c1c; text-align: center; padding: 6px; font-weight: bold; font-size: 11px; margin-bottom: 12px; border-radius: 4px;'>GUÍA DE REMISIÓN DE PRUEBA / BETA — SIN VALOR TRIBUTARIO</div>"
            : '';

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='utf-8'>
            <title>{$guiaTitle} {$despatch->series}-{$correlativePadded}</title>
            <style>
                body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 15px; line-height: 1.4; }
                table { width: 100%; border-collapse: collapse; }
                .ruc-box { border: 2px solid #0284c7; border-radius: 8px; text-align: center; padding: 10px; }
                .ruc-box h3 { margin: 0; font-size: 14px; color: #0f172a; }
                .ruc-box h2 { margin: 4px 0; font-size: 12px; color: #0284c7; text-transform: uppercase; }
                .section-box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; margin-bottom: 8px; background-color: #f8fafc; font-size: 10px; }
                .items-table th { background-color: #0284c7; color: white; padding: 5px 8px; font-size: 10px; text-transform: uppercase; text-align: left; }
                .footer-text { text-align: center; color: #64748b; font-size: 9px; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 8px; }
            </style>
        </head>
        <body>
            {$testBanner}
            <table style='margin-bottom: 12px;'>
                <tr>
                    <td style='width: 60%; vertical-align: top;'>
                        <h1 style='margin: 0 0 4px 0; font-size: 16px; color: #0f172a;'>{$businessName}</h1>
                        <p style='margin: 0; color: #475569; font-size: 10px;'>{$address}</p>
                        <p style='margin: 0; color: #64748b; font-size: 10px;'>{$district} - {$province} - {$department}</p>
                    </td>
                    <td style='width: 40%; vertical-align: top;'>
                        <div class='ruc-box'>
                            <h3>RUC: {$ruc}</h3>
                            <h2>{$guiaTitle}</h2>
                            <h3 style='color: #dc2626;'>{$despatch->series}-{$correlativePadded}</h3>
                        </div>
                    </td>
                </tr>
            </table>

            <div class='section-box'>
                <table>
                    <tr>
                        <td style='width: 50%; vertical-align: top;'>
                            <strong>DESTINATARIO:</strong> {$despatch->recipient_name}<br>
                            <strong>RUC/DNI:</strong> {$despatch->recipient_doc_number}<br>
                            <strong>DIRECCIÓN:</strong> {$despatch->recipient_address}
                        </td>
                        <td style='width: 50%; vertical-align: top;'>
                            <strong>Fecha Emisión:</strong> {$despatch->issue_date->format('d/m/Y')}<br>
                            <strong>Fecha Inicio Traslado:</strong> {$despatch->transfer_date->format('d/m/Y')}<br>
                            " . ($despatch->delivery_date ? "<strong>Fecha Entrega al Transportista:</strong> {$despatch->delivery_date->format('d/m/Y')}<br>" : '') . "
                            <strong>Motivo de Traslado:</strong> " . strtoupper($despatch->transfer_reason) . " " . ($despatch->transfer_description ? "({$despatch->transfer_description})" : '') . "<br>
                            <strong>Peso Bruto Total:</strong> {$despatch->total_weight} {$despatch->weight_unit} | <strong>Bultos:</strong> {$despatch->packages_count} {$referentialWeightNotice}
                        </td>
                    </tr>
                </table>
            </div>

            <div class='section-box'>
                <table>
                    <tr>
                        <td style='width: 50%; vertical-align: top;'>
                            <strong>PUNTO DE PARTIDA:</strong><br>
                            {$despatch->origin_address} (Ubigeo: {$despatch->origin_ubigeo})
                        </td>
                        <td style='width: 50%; vertical-align: top;'>
                            <strong>PUNTO DE LLEGADA:</strong><br>
                            {$despatch->destination_address} (Ubigeo: {$despatch->destination_ubigeo})
                        </td>
                    </tr>
                </table>
            </div>

            <div class='section-box'>
                {$transporteInfo}
            </div>

            {$relDocsHtml}

            <table class='items-table' style='margin-top: 8px;'>
                <thead>
                    <tr>
                        <th style='width: 15%;'>Código</th>
                        <th style='width: 55%;'>Descripción del Bien</th>
                        <th style='width: 15%; text-align: center;'>U.M.</th>
                        <th style='width: 15%; text-align: right;'>Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemsRows}
                </tbody>
            </table>

            {$obsHtml}

            <table style='margin-top: 15px;'>
                <tr>
                    <td style='width: 25%; text-align: center;'>
                        " . ($qrCodeDataUri ? "<img src='{$qrCodeDataUri}' style='width: 90px; height: 90px;'>" : '') . "
                    </td>
                    <td style='width: 75%; vertical-align: middle;'>
                        <p style='margin: 0; color: #64748b; font-size: 10px;'>
                            Representación impresa de la Guía de Remisión Electrónica generada mediante el Sistema de Emisión Electrónica SUNAT.
                        </p>
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

    private function generateDespatchQrDataUri(Despatch $despatch, string $ruc): ?string
    {
        try {
            $qrContent = sprintf(
                '%s|%s|%s|%s|%s|%s|%s|%s|',
                $ruc,
                $despatch->type_code,
                $despatch->series,
                $despatch->correlative,
                $despatch->issue_date->format('Y-m-d'),
                $despatch->recipient_doc_type,
                $despatch->recipient_doc_number,
                $despatch->hash ?? ''
            );

            $renderer = new ImageRenderer(
                new RendererStyle(120),
                new SvgImageBackEnd
            );
            $writer = new Writer($renderer);
            $svg = $writer->writeString($qrContent);

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }
}
