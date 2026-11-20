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

    public function generate(Document $document): ?string
    {
        return null;
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

        $qrCodeDataUri = $this->generateQrDataUri($document, $ruc);
        $montoLetras = NumeroALetras::convert($document->total, $document->currency);
        $formaPago = ($document->due_date && $document->due_date->gt($document->issue_date)) ? 'CRÉDITO' : strtoupper($document->payment_method ?? 'CONTADO');
        $correlativePadded = str_pad((string) $document->correlative, 8, '0', STR_PAD_LEFT);
        $testBanner = !$document->is_production
            ? "<div style='background-color: #fee2e2; border: 1px dashed #ef4444; color: #b91c1c; text-align: center; padding: 6px; font-weight: bold; font-size: 11px; margin-bottom: 12px; border-radius: 4px;'>COMPROBANTE DE PRUEBA / BETA — SIN VALOR TRIBUTARIO</div>"
            : '';

        // Filas de Items (preservando hasta 6 decimales significativos en cantidad y valor unitario)
        $itemsRows = '';
        foreach ($document->items as $item) {
            $qtyFloat = (float) $item->quantity;
            $unitValFloat = (float) $item->unit_value;
            $baseImponible = $qtyFloat * $unitValFloat;

            $qty = $this->formatDecimal($qtyFloat, 2, 6);
            $unitVal = $this->formatDecimal($unitValFloat, 2, 6);
            $baseImponibleFmt = number_format($baseImponible, 2);
            $itemCode = $item->internal_code ? htmlspecialchars($item->internal_code) : '-';
            $itemDesc = htmlspecialchars((string) $item->description);

            $itemsRows .= "
                <tr>
                    <td class='text-center'>{$qty}</td>
                    <td class='text-center'>{$item->unit_code}</td>
                    <td class='text-center'>{$itemCode}</td>
                    <td>{$itemDesc}</td>
                    <td class='text-right'>{$unitVal}</td>
                    <td class='text-right'>{$baseImponibleFmt}</td>
                </tr>
            ";
        }

        // Anticipos aplicados (si existen) — columna Monto Deducido visual siempre en 0.00 (saldo restante)
        $advancesSection = '';
        $totalAdvancesAmount = 0.00;
        if ($document->appliedAdvances && $document->appliedAdvances->isNotEmpty()) {
            $advRows = '';
            foreach ($document->appliedAdvances as $adv) {
                $totalAdvancesAmount += (float) $adv->amount;
                $advDate = $adv->advance_issue_date ? $adv->advance_issue_date->format('d/m/Y') : '-';
                $advDesc = htmlspecialchars((string) $adv->description);
                $advRows .= "
                    <tr>
                        <td>{$adv->advance_series}-{$adv->advance_correlative} ({$advDate})</td>
                        <td>{$advDesc}</td>
                        <td class='text-right'>{$document->currency} 0.00</td>
                    </tr>
                ";
            }
            $advancesSection = "
                <div class='section-title' style='margin-top: 10px;'>ANTICIPOS APLICADOS</div>
                <table class='info-table' style='border: 1px solid #bae6fd; margin-bottom: 10px;'>
                    <tr style='background-color: #ecfeff; font-weight: bold; color: #0e7490;'>
                        <td style='width: 35%;'>Comprobante</td>
                        <td style='width: 45%;'>Descripción</td>
                        <td style='width: 20%; text-align: right;'>Monto Deducido</td>
                    </tr>
                    {$advRows}
                </table>
            ";
        }

        // Observaciones generales (se muestran dentro de Datos del Cliente)
        $observationsRowHtml = '';
        if ($document->observations !== null && trim((string) $document->observations) !== '') {
            $observationsRowHtml = "
                <tr>
                    <td class='label' style='vertical-align: top;'>Observaciones:</td>
                    <td colspan='3' style='white-space: pre-wrap; color: #334155;'>" . htmlspecialchars((string) $document->observations) . "</td>
                </tr>
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
                <div style='margin-top: 8px; margin-bottom: 8px; background-color: #ecfeff; border: 1px solid #67e8f9; border-radius: 4px; padding: 6px 10px; font-size: 10px;'>
                    <strong style='color: #0e7490;'>DOCUMENTO QUE MODIFICA:</strong> {$refDoc} | <strong style='color: #0e7490;'>MOTIVO:</strong> {$reasonDesc}
                    " . (!empty($nd['new_invoice_number']) ? "<br><strong style='color: #0e7490;'>NUEVA FACTURA ELECTRÓNICA:</strong> " . htmlspecialchars((string) $nd['new_invoice_number']) : '') . "
                    " . (!empty($nd['dice']) ? "<br><strong>DICE:</strong> " . htmlspecialchars($nd['dice']) : '') . "
                    " . (!empty($nd['debe_decir']) ? "<br><strong>DEBE DECIR:</strong> " . htmlspecialchars($nd['debe_decir']) : '') . "
                </div>
            ";
        }

        // Detracción (con leyenda oficial SUNAT y descripción del bien o servicio)
        $detractionHtml = '';
        if ($document->detraction) {
            $det = $document->detraction;
            $detAmount = number_format((float) ($det['amount'] ?? 0), 2);
            $serviceCode = (string) ($det['service_code'] ?? $det['code'] ?? '034');
            $serviceDesc = $this->getDetractionServiceDescription($serviceCode);
            $serviceLabel = $serviceDesc ? "{$serviceCode} - {$serviceDesc}" : $serviceCode;
            $detractionLegend = $this->getDetractionLegend($serviceCode, (string) $document->operation_type);
            $paymentMethodCode = (string) ($det['payment_method_code'] ?? '003');
            $medioPagoDesc = match ($paymentMethodCode) {
                '003' => '003 - Transferencia de fondos',
                '001' => '001 - Depósito en cuenta',
                default => $paymentMethodCode,
            };
            $usdRefHtml = '';
            if (!empty($det['amount_usd']) && strtoupper((string) $document->currency) === 'USD') {
                $detUsdFmt = number_format((float) $det['amount_usd'], 2);
                $tcHtml = !empty($det['exchange_rate']) ? ' (TC: ' . number_format((float) $det['exchange_rate'], 3) . ')' : '';
                $usdRefHtml = " <span style='color: #64748b; font-size: 9px;'>(Ref. USD {$detUsdFmt}{$tcHtml})</span>";
            }
            $detractionHtml = "
                <div style='margin-top: 10px; background-color: #fffbeb; border: 1px solid #f59e0b; border-left: 4px solid #c98a1a; border-radius: 4px; padding: 7px 10px; font-size: 10px;'>
                    <strong style='color: #92400e;'>INFORMACIÓN DE LA DETRACCIÓN:</strong><br>
                    <div style='font-weight: bold; color: #0e7490; margin: 2px 0 4px 0;'>{$detractionLegend}</div>
                    <strong>Bien o Servicio:</strong> {$serviceLabel} | <strong>Medio de pago:</strong> {$medioPagoDesc}<br>
                    <strong>Nro. Cta. Banco de la Nación:</strong> " . ($det['bank_account'] ?? '-') . " | <strong>Porcentaje de detracción:</strong> " . ($det['percent'] ?? 10) . "% | <strong>Monto detracción:</strong> <strong style='color: #92400e;'>S/ {$detAmount}</strong>{$usdRefHtml}
                </div>
            ";
        }

        // Información del Crédito (Cuotas)
        $creditHtml = '';
        if (strtolower((string) $document->payment_method) === 'credito' && !empty($document->installments) && is_array($document->installments)) {
            $cuotaRows = '';
            $netPending = 0.00;
            foreach ($document->installments as $idx => $inst) {
                $cNum = $inst['number'] ?? ($idx + 1);
                $cDue = $inst['due_date'] ?? '-';
                $cAmt = (float) ($inst['amount'] ?? 0);
                $netPending += $cAmt;
                $cAmtFmt = number_format($cAmt, 2);
                $cuotaRows .= "<tr><td style='padding: 2px 4px;'>Cuota " . str_pad((string) $cNum, 3, '0', STR_PAD_LEFT) . "</td><td style='padding: 2px 4px;'>{$cDue}</td><td class='text-right' style='padding: 2px 4px;'>{$document->currency} {$cAmtFmt}</td></tr>";
            }
            $netPendingFmt = number_format($netPending, 2);
            $totalCuotas = count($document->installments);
            $creditHtml = "
                <div style='margin-top: 10px; background-color: #f8fafc; border: 1px solid #bae6fd; border-left: 4px solid #0e9aa7; border-radius: 4px; padding: 7px 10px; font-size: 10px;'>
                    <strong style='color: #0e7490;'>INFORMACIÓN DEL CRÉDITO:</strong><br>
                    <strong>Monto neto pendiente de pago:</strong> {$document->currency} {$netPendingFmt} | <strong>Total de Cuotas:</strong> {$totalCuotas}
                    <table style='width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 9px;'>
                        <tr style='background-color: #ecfeff; font-weight: bold; color: #0e7490;'>
                            <td style='padding: 3px 4px;'>N° Cuota</td>
                            <td style='padding: 3px 4px;'>Fec. Venc.</td>
                            <td style='padding: 3px 4px; text-align: right;'>Monto</td>
                        </tr>
                        {$cuotaRows}
                    </table>
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
                    $bankRows .= "<tr><td style='padding: 2px 0;'><strong>{$banco}</strong> ({$moneda})</td><td style='padding: 2px 0;'>Cuenta: {$cuenta}</td><td style='padding: 2px 0;'>CCI: {$cci}</td></tr>";
                }
                $banksHtml = "
                    <div style='margin-top: 8px;'>
                        <strong style='color: #0e7490; font-size: 10px;'>CUENTAS BANCARIAS:</strong>
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
                        <strong style='color: #0e7490;'>OTRAS NOTAS:</strong><br>• {$itemsList}
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
                    <div style='margin-top: 12px; border-top: 2px solid #d49b27; padding-top: 8px;'>
                        {$banksHtml}
                        {$otrasObsHtml}
                        {$contactoHtml}
                    </div>
                ";
            }
        }

        // Resumen de montos en orden: Subtotal, Anticipos, Valor Venta, IGV, Importe Total
        $grossItemsTaxable = 0.00;
        foreach ($document->items as $it) {
            $grossItemsTaxable += (float) $it->unit_value * (float) $it->quantity;
        }
        $valorVenta = (float) $document->total_taxable
            + (float) ($document->total_exonerated ?? 0)
            + (float) ($document->total_unaffected ?? 0)
            + (float) ($document->total_exportation ?? 0);

        $subTotalVentasFmt = number_format($grossItemsTaxable, 2);
        $advBase = $totalAdvancesAmount > 0 ? round($totalAdvancesAmount / 1.18, 2) : 0.00;
        $formattedAdvBase = number_format($advBase, 2);

        $anticiposTotalesRow = $totalAdvancesAmount > 0
            ? "
                <tr>
                    <td style='color: #b45309;'>(-) Anticipos:</td>
                    <td class='text-right' style='color: #b45309;'>{$document->currency} -{$formattedAdvBase}</td>
                </tr>
            "
            : '';

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <title>{$docTitle} {$document->series}-{$correlativePadded}</title>
            <style>
                body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 15px; }
                .header-table { width: 100%; margin-bottom: 10px; }
                .company-info { width: 55%; vertical-align: top; }
                .company-name { font-size: 15px; font-weight: bold; color: #0e9aa7; text-transform: uppercase; }
                .company-details { font-size: 10px; color: #475569; margin-top: 4px; line-height: 1.35; }
                .ruc-box { width: 40%; border: 2px solid #0e9aa7; border-top: 4px solid #d49b27; border-radius: 6px; text-align: center; padding: 8px; vertical-align: middle; background-color: #f8fafc; }
                .ruc-number { font-size: 14px; font-weight: bold; color: #0f172a; margin-bottom: 4px; }
                .doc-type { font-size: 12px; font-weight: bold; color: #0e9aa7; margin-bottom: 4px; }
                .doc-number { font-size: 14px; font-weight: bold; color: #c98a1a; }
                .section-title { font-weight: bold; font-size: 11px; margin-bottom: 4px; border-bottom: 1.5px solid #d49b27; padding-bottom: 2px; color: #0e7490; }
                .info-table { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
                .info-table td { padding: 3px 4px; font-size: 10px; }
                .label { font-weight: bold; color: #0e7490; width: 16%; }
                .items-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                .items-table th { background-color: #0e9aa7; color: #ffffff; font-size: 9.5px; font-weight: bold; padding: 6px 5px; text-align: left; border-bottom: 2px solid #d49b27; }
                .items-table td { border-bottom: 1px solid #e2e8f0; padding: 5px; font-size: 10px; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .bottom-table { width: 100%; margin-top: 10px; }
                .qr-section { width: 45%; vertical-align: top; font-size: 9px; color: #64748b; }
                .totals-section { width: 50%; vertical-align: top; }
                .totals-table { width: 100%; border-collapse: collapse; }
                .totals-table td { padding: 3px 6px; font-size: 10px; }
                .totals-table .total-row { font-size: 12px; font-weight: bold; background-color: #ecfeff; border-top: 2px solid #d49b27; border-bottom: 2px solid #0e9aa7; color: #0e7490; }
                .legend-box { background-color: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #d49b27; border-radius: 4px; padding: 6px 8px; font-size: 10px; font-weight: bold; color: #1e293b; margin-bottom: 8px; }
                .footer-text { margin-top: 15px; font-size: 9px; color: #888; text-align: center; border-top: 1px dashed #ccc; padding-top: 8px; }
            </style>
        </head>
        <body>
            {$testBanner}
            <table class='header-table'>
                <tr>
                    <td class='company-info'>
                        <div class='company-name'>{$businessName}</div>
                        " . ($trademarkName ? "<div style='font-size:11px; color:#c98a1a; font-weight: bold;'>{$trademarkName}</div>" : '') . "
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

            <div class='section-title'>DATOS DEL CLIENTE</div>
            <table class='info-table'>
                <tr>
                    <td class='label'>Cliente:</td>
                    <td>" . htmlspecialchars((string) $document->client_name) . "</td>
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
                    <td>" . htmlspecialchars((string) ($document->client_address ?: '-')) . "</td>
                    <td class='label'>Forma de Pago:</td>
                    <td>{$formaPago}</td>
                </tr>
                " . ($document->purchase_order || $document->plate_number ? "
                <tr>
                    <td class='label'>" . ($document->purchase_order ? 'Orden de Compra:' : '') . '</td>
                    <td>' . htmlspecialchars((string) ($document->purchase_order ?: '')) . "</td>
                    <td class='label'>" . ($document->plate_number ? 'Placa:' : '') . '</td>
                    <td>' . htmlspecialchars((string) ($document->plate_number ?: '')) . '</td>
                </tr>
                ' : '') . "
                {$observationsRowHtml}
            </table>

            {$noteHtml}

            <table class='items-table'>
                <thead>
                    <tr>
                        <th style='width: 11%;' class='text-center'>CANT.</th>
                        <th style='width: 9%;' class='text-center'>UNIDAD</th>
                        <th style='width: 12%;' class='text-center'>CÓDIGO</th>
                        <th style='width: 38%;'>DESCRIPCIÓN</th>
                        <th style='width: 14%;' class='text-right'>V. UNIT</th>
                        <th style='width: 16%;' class='text-right'>BASE IMPONIBLE</th>
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
                        <code>" . ($document->hash ?: 'Generando firma digital...') . "</code>
                    </td>
                    <td class='totals-section'>
                        <table class='totals-table'>
                            <tr>
                                <td>Subtotal:</td>
                                <td class='text-right'>{$document->currency} {$subTotalVentasFmt}</td>
                            </tr>
                            {$anticiposTotalesRow}
                            <tr>
                                <td>Valor Venta:</td>
                                <td class='text-right'>{$document->currency} " . number_format($valorVenta, 2) . '</td>
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

            {$detractionHtml}
            {$creditHtml}
            {$piePaginaHtml}
        </body>
        </html>
        ";
    }

    private function formatDecimal(float $value, int $minDecimals = 2, int $maxDecimals = 6): string
    {
        $formatted = number_format($value, $maxDecimals, '.', ',');
        [$intPart, $decPart] = explode('.', $formatted);
        $trimmedDec = rtrim($decPart, '0');
        if (strlen($trimmedDec) < $minDecimals) {
            $trimmedDec = str_pad($trimmedDec, $minDecimals, '0');
        }

        return "{$intPart}.{$trimmedDec}";
    }

    private function getDetractionServiceDescription(string $code): string
    {
        return match ($code) {
            '001' => 'Azúcar y melaza de caña',
            '002' => 'Arroz',
            '003' => 'Alcohol etílico',
            '004' => 'Recursos hidrobiológicos',
            '005' => 'Maíz amarillo duro',
            '007' => 'Caña de azúcar',
            '008' => 'Madera',
            '009' => 'Arena y piedra',
            '010' => 'Residuos, subproductos, desechos, recortes y desperdicios',
            '011' => 'Bienes gravados con el IGV por renuncia a la exoneración',
            '012' => 'Intermediación laboral y tercerización',
            '013' => 'Animales vivos',
            '014' => 'Carnes y despojos comestibles',
            '017' => 'Harina, polvo y pellets de pescado',
            '019' => 'Arrendamiento de bienes',
            '020' => 'Mantenimiento y reparación de bienes muebles',
            '021' => 'Movimiento de carga',
            '022' => 'Otros servicios empresariales',
            '023' => 'Leche',
            '024' => 'Comisión mercantil',
            '025' => 'Fabricación de bienes por encargo',
            '026' => 'Servicio de transporte de personas',
            '027' => 'Servicio de transporte de carga',
            '028' => 'Transporte de pasajeros',
            '030' => 'Contratos de construcción',
            '031' => 'Oro gravado con el IGV',
            '032' => 'Páprika y otros frutos de los géneros capsicum o pimienta',
            '034' => 'Minerales metálicos no auríferos',
            '035' => 'Bienes exonerados del IGV',
            '036' => 'Oro y demás minerales metálicos exonerados del IGV',
            '037' => 'Demás servicios gravados con el IGV',
            '039' => 'Minerales no metálicos',
            '040' => 'Bien inmueble gravado con IGV',
            '041' => 'Plomo',
            default => '',
        };
    }

    private function getDetractionLegend(string $serviceCode, string $operationType): string
    {
        if ($operationType === '1004' || $serviceCode === '027') {
            return 'Operación sujeta al Sistema de Pago de Obligaciones Tributarias con el Gobierno Central - Servicio de Transporte de Carga';
        }
        if ($operationType === '1002' || $serviceCode === '004') {
            return 'Operación sujeta al Sistema de Pago de Obligaciones Tributarias con el Gobierno Central - Recursos Hidrobiológicos';
        }
        if ($operationType === '1003' || $serviceCode === '026' || $serviceCode === '028') {
            return 'Operación sujeta al Sistema de Pago de Obligaciones Tributarias con el Gobierno Central - Servicio de Transporte de Personas';
        }

        return 'Operación sujeta al Sistema de Pago de Obligaciones Tributarias con el Gobierno Central';
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

    public function generateDespatchPdf(Despatch $despatch): ?string
    {
        return null;
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
        $logoDataUri = $this->resolveCompanyLogoDataUri($cData['logo_url'] ?? $despatch->company?->logo_path);

        $correlativePadded = str_pad((string) $despatch->correlative, 8, '0', STR_PAD_LEFT);
        $qrCodeDataUri = $this->generateDespatchQrDataUri($despatch, $ruc);

        $reasonCatalog = [
            '01' => 'Venta',
            '02' => 'Compra',
            '03' => 'Venta con entrega a terceros',
            '04' => 'Traslado entre establecimientos de la misma empresa',
            '05' => 'Consignación',
            '06' => 'Devolución',
            '07' => 'Traslado de bienes para transformación',
            '08' => 'Importación',
            '09' => 'Exportación',
            '13' => 'Otros',
            '14' => 'Venta sujeta a confirmación del comprador',
            '17' => 'Traslado de bienes para transformación',
            '18' => 'Traslado emisor itinerante CP',
        ];
        $reasonLabel = $reasonCatalog[$despatch->transfer_reason] ?? $despatch->transfer_reason;

        $itemsRows = '';
        foreach ($despatch->items as $idx => $item) {
            $qty = $this->formatDecimal((float) $item->quantity, 2, 6);
            $code = $item->internal_code ? htmlspecialchars((string) $item->internal_code) : '-';
            $desc = htmlspecialchars((string) $item->description);
            $num = $idx + 1;
            $itemsRows .= "
            <tr>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; text-align: center;'>{$num}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; font-family: monospace;'>{$code}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0;'>{$desc}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; text-align: center;'>{$item->unit_code}</td>
                <td style='padding: 5px 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>{$qty}</td>
            </tr>
            ";
        }

        // Indicadores automáticos según modalidad de traslado (Público vs Privado)
        $isPublic = $despatch->isPublicTransport();
        $modalidadLabel = $isPublic ? 'Público' : 'Privado';
        $hasVehicleOrDriver = !empty($despatch->vehicle_plate) || !empty($despatch->driver_doc_number);
        $indRegVehiculosConductores = ($isPublic && $hasVehicleOrDriver) ? 'SI' : ($isPublic ? 'NO' : 'SI');
        $weightFormatted = $this->formatDecimal((float) $despatch->total_weight, 3, 6);

        $indicadoresHtml = "
            <div class='section-box'>
                <strong style='color: #0e7490;'>DATOS DEL TRASLADO:</strong><br>
                <div style='margin: 4px 0 6px 0; padding: 4px 8px; background-color: #ecfeff; border: 1px solid #bae6fd; border-radius: 4px;'>
                    <strong>Unidad de Medida del Peso Bruto:</strong> {$despatch->weight_unit} &nbsp;|&nbsp; <strong>Peso Bruto Total de la carga:</strong> {$weightFormatted}
                </div>
                <table>
                    <tr>
                        <td style='width: 50%;'>
                            <strong>Modalidad de Traslado:</strong> {$modalidadLabel}<br>
                            <strong>Indicador de transbordo programado:</strong> NO<br>
                            <strong>Indicador de traslado en vehículos de categoría M1 o L:</strong> NO
                        </td>
                        <td style='width: 50%;'>
                            <strong>Indicador de retorno de vehículo con envases o embalajes vacíos:</strong> NO<br>
                            <strong>Indicador para registrar vehículos y conductores del transportista:</strong> {$indRegVehiculosConductores}
                        </td>
                    </tr>
                </table>
            </div>
        ";

        // Secciones de Transportista, Vehículos y Conductores adaptadas a la modalidad y datos registrados
        $carrierSectionHtml = '';
        if ($isPublic || !empty($despatch->carrier_doc_number) || !empty($despatch->carrier_name)) {
            $carrierSectionHtml = "
                <div class='section-box'>
                    <strong style='color: #0e7490;'>DATOS DEL TRANSPORTISTA:</strong><br>
                    <strong>Razón Social / Nombre:</strong> " . ($despatch->carrier_name ?: '-') . " — <strong>RUC:</strong> " . ($despatch->carrier_doc_number ?: '-') . "<br>
                    <strong>Número de registro del MTC:</strong> " . ($despatch->carrier_mtc ?: 'NO ESPECIFICADO') . "
                </div>
            ";
        }

        $vehicleSectionHtml = '';
        if (!empty($despatch->vehicle_plate) || !$isPublic) {
            $tuceHtml = $despatch->vehicle_certificate
                ? "<br><strong>Número de TUCE o Certificado de Habilitación Vehicular:</strong> {$despatch->vehicle_certificate}"
                : '';
            $secHtml = $despatch->secondary_vehicle_plate
                ? "<br><strong>Secundario — Número de placa:</strong> {$despatch->secondary_vehicle_plate}"
                : '';
            $vehicleSectionHtml = "
                <div class='section-box'>
                    <strong style='color: #0e7490;'>DATOS DE LOS VEHÍCULOS:</strong><br>
                    <strong>Principal — Número de placa:</strong> " . ($despatch->vehicle_plate ?: '-') . "{$tuceHtml}{$secHtml}
                </div>
            ";
        }

        $driverSectionHtml = '';
        if (!empty($despatch->driver_doc_number) || !empty($despatch->driver_name) || !$isPublic) {
            $driverSectionHtml = "
                <div class='section-box'>
                    <strong style='color: #0e7490;'>DATOS DE LOS CONDUCTORES:</strong><br>
                    <strong>Principal:</strong> " . ($despatch->driver_name ?: '-') . " — <strong>DOCUMENTO DE IDENTIDAD N°:</strong> " . ($despatch->driver_doc_number ?: '-') . "<br>
                    <strong>Número de licencia de conducir:</strong> " . ($despatch->driver_license ?: '-') . "
                </div>
            ";
        }

        // Datos del Proveedor (cuando el motivo es Compra 02 o viene en documentos relacionados)
        $suppliersInfoList = [];
        $relDocsHtml = '';
        if ($despatch->related_documents && is_array($despatch->related_documents)) {
            $docsList = [];
            foreach ($despatch->related_documents as $rd) {
                $docNum = $rd['number'] ?? $rd['document_number'] ?? (($rd['series'] ?? '') . '-' . ($rd['correlative'] ?? ''));
                $sName = $rd['supplier_name'] ?? '';
                $sRuc = $rd['supplier_ruc'] ?? $rd['company_number'] ?? '';
                if ($sName || $sRuc) {
                    $key = "{$sRuc}|{$sName}";
                    $suppliersInfoList[$key] = trim("{$sName} - REGISTRO ÚNICO DE CONTRIBUYENTES N° {$sRuc}", ' -');
                }
                $suppInfo = '';
                if ($sName || $sRuc) {
                    $suppInfo = " — RUC N° {$sRuc}" . ($sName ? " ({$sName})" : '');
                }
                $typeLabel = $rd['type_name'] ?? match ((string) ($rd['type_code'] ?? '01')) {
                    '03' => 'Boleta',
                    '09' => 'GRE Remitente',
                    '31' => 'GRE Transportista',
                    '50' => 'DAM',
                    default => 'Factura',
                };
                $docsList[] = "{$typeLabel} N° {$docNum}{$suppInfo}";
            }
            if ($docsList) {
                $relDocsHtml = "
                    <div class='section-box' style='margin-top: 8px;'>
                        <strong style='color: #0e7490;'>DOCUMENTOS RELACIONADOS:</strong><br>
                        " . implode('<br>', $docsList) . "
                    </div>
                ";
            }
        }

        $supplierHeaderHtml = '';
        if (!empty($suppliersInfoList)) {
            $supplierHeaderHtml = "<br><strong>DATOS DEL PROVEEDOR:</strong> " . implode(' / ', array_values($suppliersInfoList));
        }

        // Observaciones
        $obsHtml = '';
        if ($despatch->observations) {
            $obsHtml = "
                <div class='section-box' style='margin-top: 8px;'>
                    <strong style='color: #0e7490;'>OBSERVACIONES:</strong> " . htmlspecialchars($despatch->observations) . "
                </div>
            ";
        }

        $guiaTitle = ($despatch->type_code === '31') ? 'GUÍA DE REMISIÓN ELECTRÓNICA TRANSPORTISTA' : 'GUÍA DE REMISIÓN ELECTRÓNICA REMITENTE';

        $testBanner = !$despatch->is_production
            ? "<div style='background-color: #fee2e2; border: 1px dashed #ef4444; color: #b91c1c; text-align: center; padding: 6px; font-weight: bold; font-size: 11px; margin-bottom: 12px; border-radius: 4px;'>GUÍA DE REMISIÓN DE PRUEBA / BETA — SIN VALOR TRIBUTARIO</div>"
            : '';

        $logoHtml = $logoDataUri
            ? "<div style='margin-bottom: 4px; text-align: center;'><img src='{$logoDataUri}' style='max-width: 115px; max-height: 60px; width: auto; height: auto;'></div>"
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
                .header-table { margin-bottom: 12px; border-bottom: 2px solid #d49b27; padding-bottom: 8px; }
                .ruc-box { border: 2px solid #0e9aa7; border-top: 4px solid #d49b27; border-radius: 8px; text-align: center; padding: 8px; background-color: #f8fafc; }
                .ruc-box h3 { margin: 0; font-size: 13px; color: #0f172a; }
                .ruc-box h2 { margin: 4px 0; font-size: 11px; color: #0e9aa7; text-transform: uppercase; }
                .section-box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; margin-bottom: 8px; background-color: #f8fafc; font-size: 10px; }
                .items-table th { background-color: #0e9aa7; color: white; padding: 5px 8px; font-size: 10px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #d49b27; }
                .footer-text { text-align: center; color: #64748b; font-size: 9px; margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 6px; }
            </style>
        </head>
        <body>
            {$testBanner}
            <table class='header-table'>
                <tr>
                    <td style='width: 18%; vertical-align: middle; text-align: left;'>
                        " . ($qrCodeDataUri ? "<img src='{$qrCodeDataUri}' style='width: 82px; height: 82px;'>" : '') . "
                    </td>
                    <td style='width: 44%; vertical-align: middle; text-align: center; padding: 0 8px;'>
                        {$logoHtml}
                        <h1 style='margin: 0 0 3px 0; font-size: 14px; color: #0e9aa7; text-transform: uppercase;'>{$businessName}</h1>
                        <p style='margin: 0; color: #475569; font-size: 9.5px;'>{$address}</p>
                        <p style='margin: 0; color: #64748b; font-size: 9px;'>{$district} - {$province} - {$department}</p>
                    </td>
                    <td style='width: 38%; vertical-align: middle;'>
                        <div class='ruc-box'>
                            <h3>RUC N° {$ruc}</h3>
                            <h2>{$guiaTitle}</h2>
                            <h3 style='color: #c98a1a;'>N° {$despatch->series} - {$correlativePadded}</h3>
                        </div>
                    </td>
                </tr>
            </table>

            <div class='section-box'>
                <table>
                    <tr>
                        <td style='width: 50%; vertical-align: top;'>
                            <strong>Fecha y hora de emisión:</strong> {$despatch->issue_date->format('d/m/Y')} {$despatch->issue_time}<br>
                            " . ($despatch->delivery_date ? "<strong>Fecha de entrega de Bienes al transportista:</strong> {$despatch->delivery_date->format('d/m/Y')}<br>" : '') . "
                            <strong>Fecha de inicio de Traslado:</strong> {$despatch->transfer_date->format('d/m/Y')}<br>
                            <strong>Motivo de Traslado:</strong> {$reasonLabel} " . ($despatch->transfer_description ? "({$despatch->transfer_description})" : '') . "
                        </td>
                        <td style='width: 50%; vertical-align: top;'>
                            <strong>Punto de Partida:</strong> {$despatch->origin_address} (Ubigeo: {$despatch->origin_ubigeo})<br>
                            <strong>Punto de Llegada:</strong> {$despatch->destination_address} (Ubigeo: {$despatch->destination_ubigeo})
                        </td>
                    </tr>
                </table>
            </div>

            <div class='section-box'>
                <strong style='color: #0e7490;'>DATOS DEL DESTINATARIO:</strong> {$despatch->recipient_name} — REGISTRO ÚNICO DE CONTRIBUYENTES / DOC N° {$despatch->recipient_doc_number}
                {$supplierHeaderHtml}
            </div>

            {$relDocsHtml}

            <div style='font-weight: bold; font-size: 10px; color: #0e7490; margin: 6px 0 4px 0;'>BIENES POR TRANSPORTAR:</div>
            <table class='items-table' style='margin-bottom: 8px;'>
                <thead>
                    <tr>
                        <th style='width: 6%; text-align: center;'>N°</th>
                        <th style='width: 16%;'>Código de Bien</th>
                        <th style='width: 50%;'>Descripción Detallada</th>
                        <th style='width: 14%; text-align: center;'>Unidad de medida</th>
                        <th style='width: 14%; text-align: right;'>Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemsRows}
                </tbody>
            </table>

            {$indicadoresHtml}
            {$carrierSectionHtml}
            {$vehicleSectionHtml}
            {$driverSectionHtml}
            {$obsHtml}
        </body>
        </html>
        ";
    }

    private function resolveCompanyLogoDataUri(?string $logoPath): ?string
    {
        if (!$logoPath) {
            return null;
        }
        $trimmed = trim($logoPath);
        if (str_starts_with($trimmed, 'data:image/') || str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        try {
            $disk = config('factos.storage_disk', 'local');
            if (Storage::disk($disk)->exists($trimmed)) {
                $bytes = Storage::disk($disk)->get($trimmed);
                $mime = Storage::disk($disk)->mimeType($trimmed) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode((string) $bytes);
            }
            if (Storage::disk('public')->exists($trimmed)) {
                $bytes = Storage::disk('public')->get($trimmed);
                $mime = Storage::disk('public')->mimeType($trimmed) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode((string) $bytes);
            }
            if (is_file($trimmed) && is_readable($trimmed)) {
                $bytes = file_get_contents($trimmed);
                $mime = mime_content_type($trimmed) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode((string) $bytes);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
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
