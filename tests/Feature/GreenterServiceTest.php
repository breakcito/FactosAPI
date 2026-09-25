<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use App\Services\Greenter\CertificateService;
use App\Services\Greenter\GreenterInvoiceBuilder;
use App\Services\Greenter\GreenterService;
use App\Services\Greenter\PdfGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('GreenterInvoiceBuilder and GreenterService sign document correctly and generate PDF', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
        'business_name' => 'SERVICIOS TECNOLOGICOS S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'docs/4-greenter/c-api-with-greenter-example-2/resources/cert.pem',
    ]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 452,
        'issue_date' => '2026-09-25',
        'issue_time' => '14:32:00',
        'currency' => 'PEN',
        'total_taxable' => 1000.00,
        'total_igv' => 180.00,
        'total' => 1180.00,
    ]);

    DocumentItem::factory()->create([
        'document_id' => $document->id,
        'internal_code' => 'PROD-01',
        'description' => 'Consultoría e integración de software',
        'unit_code' => 'ZZ',
        'quantity' => 1,
        'unit_value' => 1000.00,
        'unit_price' => 1180.00,
        'igv_type' => '10',
        'igv_amount' => 180.00,
        'total' => 1180.00,
    ]);

    $document->load(['company', 'items']);

    $builder = new GreenterInvoiceBuilder;
    $invoice = $builder->build($document);

    expect($invoice->getName())->toBe('20123456789-01-F001-452');

    $certService = new CertificateService;
    $greenterService = new GreenterService($certService);

    $signResult = $greenterService->signDocument($document, $invoice);

    expect($signResult['xml'])->toBeString()->not->toBeEmpty();
    expect($signResult['hash'])->toBeString()->not->toBeEmpty();
    expect($signResult['xml_path'])->toBe('tenants/20123456789/2026/09/01-F001-452.xml');
    expect(Storage::disk('local')->exists($signResult['xml_path']))->toBeTrue();

    // Verify PDF generation
    $document->hash = $signResult['hash'];
    $pdfService = new PdfGeneratorService;
    $pdfPath = $pdfService->generate($document);

    expect($pdfPath)->toBe('tenants/20123456789/2026/09/01-F001-452.pdf');
    expect(Storage::disk('local')->exists($pdfPath))->toBeTrue();
});
