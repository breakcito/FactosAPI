<?php

use App\Jobs\SendWebhookJob;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\Document;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('Health check endpoint is public and reports ok status', function () {
    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'ok',
            'service' => config('app.name'),
        ])
        ->assertJsonStructure([
            'status',
            'service',
            'time',
        ]);
});

test('Unauthenticated requests are strictly rejected with 401 Unauthorized', function () {
    $this->getJson('/api/v1/documents')->assertStatus(401);
    $this->getJson('/api/v1/companies')->assertStatus(401);
    $this->postJson('/api/v1/invoices', [])->assertStatus(401);
    $this->postJson('/api/v1/despatches', [])->assertStatus(401);
    $this->getJson('/api/v1/services/dni/72728282')->assertStatus(401);
});

test('User logout revokes current access token and blocks further requests', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    // Con token válido -> 200
    $resBefore = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');
    $resBefore->assertStatus(200)->assertJson(['user' => ['email' => $user->email]]);

    // Logout revoca el token en la base de datos
    $resLogout = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout');
    $resLogout->assertStatus(200)
        ->assertJson(['message' => 'Logged out.']);

    expect($user->tokens()->count())->toBe(0);
});

test('Validation rejects duplicated series and correlative with 422', function () {
    Queue::fake();
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    // Crear un primer documento F001-500
    Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 500,
    ]);

    // Intentar emitir otro documento con la misma serie y correlativo
    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 500,
        'issue_date' => '2026-10-05',
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client' => ['doc_type' => '6', 'doc_number' => '20600055231', 'name' => 'CLIENTE TEST'],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Producto Prueba',
                'quantity' => 1,
                'unit_value' => 100.00,
                'unit_price' => 118.00,
                'igv_type' => '10',
                'igv_amount' => 18.00,
                'total' => 118.00,
            ],
        ],
        'totals' => ['taxable' => 100.00, 'igv' => 18.00, 'total' => 118.00],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['correlative']);
});

test('Validation rejects invalid client RUC and empty items with 422', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    // RUC con menos de 11 dígitos y sin ítems
    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 601,
        'issue_date' => '2026-10-05',
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '12345', // RUC inválido
            'name' => 'EMPRESA INVALIDA',
        ],
        'items' => [], // Vacío
        'totals' => ['taxable' => 0.00, 'igv' => 0.00, 'total' => 0.00],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['client.doc_number', 'items']);
});

test('Validation rejects credit note without note object with 422', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    $payload = [
        'company_id' => $company->id,
        'series' => 'FC01',
        'correlative' => 1,
        'issue_date' => '2026-10-05',
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client' => ['doc_type' => '6', 'doc_number' => '20600055231', 'name' => 'CLIENTE TEST'],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Anulación de ítem',
                'quantity' => 1,
                'unit_value' => 100.00,
                'unit_price' => 118.00,
                'igv_type' => '10',
                'igv_amount' => 18.00,
                'total' => 118.00,
            ],
        ],
        'totals' => ['taxable' => 100.00, 'igv' => 18.00, 'total' => 118.00],
        // Omite intencionalmente 'note'
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/credit-notes', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['note']);
});

test('Documents can be queried and filtered by type, series, status and date range', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    // Crear 3 documentos con diferentes atributos
    Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 1,
        'status' => 'accepted',
        'issue_date' => '2026-10-01',
    ]);
    Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '03',
        'series' => 'B001',
        'correlative' => 1,
        'status' => 'pending',
        'issue_date' => '2026-10-02',
    ]);
    Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '07',
        'series' => 'FC01',
        'correlative' => 1,
        'status' => 'rejected',
        'issue_date' => '2026-10-03',
    ]);

    // Filtro 1: Solo Facturas (01)
    $res1 = $this->actingAs($user, 'sanctum')->getJson('/api/v1/documents?type_code=01');
    $res1->assertStatus(200);
    expect($res1->json('data.total'))->toBe(1);
    expect($res1->json('data.data.0.type_code'))->toBe('01');

    // Filtro 2: Solo Aceptados
    $res2 = $this->actingAs($user, 'sanctum')->getJson('/api/v1/documents?status=accepted');
    $res2->assertStatus(200);
    expect($res2->json('data.total'))->toBe(1);
    expect($res2->json('data.data.0.status'))->toBe('accepted');

    // Filtro 3: Por rango de fechas
    $res3 = $this->actingAs($user, 'sanctum')->getJson('/api/v1/documents?date_from=2026-10-02&date_to=2026-10-03');
    $res3->assertStatus(200);
    expect($res3->json('data.total'))->toBe(2);
});

test('Despatch download endpoints return 200 with streams or 404 when files are not ready', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'ruc' => '20123456789']);

    $xmlPath = 'tenants/20123456789/2026/10/09-T001-1.xml';
    $pdfPath = 'tenants/20123456789/2026/10/09-T001-1.pdf';

    // Despacho sin archivos aún
    $despatchSinArchivos = Despatch::factory()->create([
        'company_id' => $company->id,
        'series' => 'T001',
        'correlative' => 1,
        'xml_path' => null,
        'pdf_path' => null,
    ]);

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/despatches/{$despatchSinArchivos->id}/xml")
        ->assertStatus(404);
    $this->actingAs($user, 'sanctum')->getJson("/api/v1/despatches/{$despatchSinArchivos->id}/pdf")
        ->assertStatus(404);

    // Guardar archivos simulados
    Storage::disk('local')->put($xmlPath, '<DespatchAdvice>XML Guia</DespatchAdvice>');
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 PDF Guia');

    $despatchConArchivos = Despatch::factory()->create([
        'company_id' => $company->id,
        'series' => 'T001',
        'correlative' => 2,
        'xml_path' => $xmlPath,
        'pdf_path' => $pdfPath,
    ]);

    $resXml = $this->actingAs($user, 'sanctum')->get("/api/v1/despatches/{$despatchConArchivos->id}/xml");
    $resXml->assertStatus(200)
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<DespatchAdvice>XML Guia</DespatchAdvice>', false);

    $resPdf = $this->actingAs($user, 'sanctum')->get("/api/v1/despatches/{$despatchConArchivos->id}/pdf");
    $resPdf->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('%PDF-1.4 PDF Guia', false);
});

test('Webhook retry endpoint enqueues SendWebhookJob only for authorized tenant', function () {
    Queue::fake();

    $userA = User::factory()->create();
    $companyA = Company::factory()->create(['user_id' => $userA->id]);
    $docA = Document::factory()->create(['company_id' => $companyA->id]);

    $webhook = WebhookDelivery::create([
        'company_id' => $companyA->id,
        'document_id' => $docA->id,
        'event' => 'document.accepted',
        'payload' => ['document' => 'F001-1'],
        'status' => 'failed',
    ]);

    // Usuario B no autorizado -> 403
    $userB = User::factory()->create();
    $this->actingAs($userB, 'sanctum')
        ->postJson("/api/v1/webhooks/{$webhook->id}/retry")
        ->assertStatus(403);

    Queue::assertNothingPushed();

    // Usuario A autorizado -> 200 y encolado
    $this->actingAs($userA, 'sanctum')
        ->postJson("/api/v1/webhooks/{$webhook->id}/retry")
        ->assertStatus(200)
        ->assertJson(['status' => 'success']);

    Queue::assertPushed(SendWebhookJob::class);
});

test('Company can be created and updated with Google SMTP credentials securely', function () {
    $user = User::factory()->create();

    // 1. Crear Empresa
    $payloadCreate = [
        'ruc' => '20608899112',
        'business_name' => 'MI NUEVA EMPRESA S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
        'mail_host' => 'smtp.gmail.com',
        'mail_port' => 587,
        'mail_username' => 'miempresa@gmail.com',
        'mail_password' => 'clave-app-google',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'miempresa@gmail.com',
        'mail_from_name' => 'Facturación Mi Empresa',
        'email_notifications_active' => true,
        'send_to_client_email' => true,
        'company_copy_emails' => ['admin@miempresa.com'],
    ];

    $resCreate = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', $payloadCreate);
    $resCreate->assertStatus(201)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'ruc' => '20608899112',
                'mail_username' => 'miempresa@gmail.com',
                'email_notifications_active' => true,
            ],
        ]);

    $company = Company::where('ruc', '20608899112')->firstOrFail();

    // Las credenciales sensibles no deben exponerse en el JSON de respuesta
    expect($resCreate->json('data'))->not()->toHaveKey('sol_pass');
    expect($resCreate->json('data'))->not()->toHaveKey('mail_password');

    // 2. Actualizar configuración de correo de la Empresa
    $payloadUpdate = [
        'mail_username' => 'nuevo.correo@gmail.com',
        'mail_password' => 'nueva-clave-google-16char',
        'mail_from_name' => 'Nuevo Remitente S.A.C.',
    ];

    $resUpdate = $this->actingAs($user, 'sanctum')->putJson("/api/v1/companies/{$company->id}", $payloadUpdate);
    $resUpdate->assertStatus(200);

    $company->refresh();
    expect($company->mail_username)->toBe('nuevo.correo@gmail.com');
    expect($company->mail_password)->toBe('nueva-clave-google-16char');
    expect($company->mail_from_name)->toBe('Nuevo Remitente S.A.C.');
});
