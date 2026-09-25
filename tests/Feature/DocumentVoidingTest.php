<?php

use App\Jobs\SendWebhookJob;
use App\Jobs\VoidDocumentJob;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Greenter\GreenterService;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->company = Company::create([
        'user_id' => $this->user->id,
        'ruc' => '20600055231',
        'business_name' => 'EMPRESA PRUEBA S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'tenants/20600055231/cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
    ]);

    Storage::disk('local')->put(
        'tenants/20600055231/cert.pem',
        file_get_contents(base_path('/cert.pem'))
    );
});

test('cannot void document that is not accepted', function () {
    $document = Document::create([
        'company_id' => $this->company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 10,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20100070970',
        'client_name' => 'CLIENTE S.A.C.',
        'total' => 100.00,
        'status' => 'pending', // NOT accepted
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/documents/{$document->id}/void", [
            'reason' => 'Error en montos',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('can request voiding of accepted invoice by uuid', function () {
    Queue::fake();

    $document = Document::create([
        'company_id' => $this->company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 15,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20100070970',
        'client_name' => 'CLIENTE S.A.C.',
        'total' => 118.00,
        'status' => 'accepted',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/documents/{$document->id}/void", [
            'reason' => 'Error en datos del cliente',
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'void_pending');

    $document->refresh();
    expect($document->status)->toBe('void_pending');
    expect($document->void_reason)->toBe('Error en datos del cliente');

    Queue::assertPushed(VoidDocumentJob::class);
});

test('can request voiding of invoice via general endpoint', function () {
    Queue::fake();

    $document = Document::create([
        'company_id' => $this->company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 20,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20100070970',
        'client_name' => 'CLIENTE S.A.C.',
        'total' => 118.00,
        'status' => 'accepted',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/documents/void', [
            'company_id' => $this->company->id,
            'type_code' => '01',
            'series' => 'F001',
            'correlative' => 20,
            'reason' => 'Cancelación de pedido por el comprador',
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success');

    $document->refresh();
    expect($document->status)->toBe('void_pending');
});

test('void document job processes voiding and updates status', function () {
    Queue::fake([SendWebhookJob::class]);

    $document = Document::create([
        'company_id' => $this->company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 30,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20100070970',
        'client_name' => 'CLIENTE S.A.C.',
        'total' => 118.00,
        'status' => 'void_pending',
    ]);

    $summaryResultMock = Mockery::mock(SummaryResult::class);
    $summaryResultMock->shouldReceive('isSuccess')->andReturn(true);
    $summaryResultMock->shouldReceive('getTicket')->andReturn('TICKET-123456');

    $cdrResponseMock = Mockery::mock(CdrResponse::class);
    $cdrResponseMock->shouldReceive('getCode')->andReturn('0');
    $cdrResponseMock->shouldReceive('getDescription')->andReturn('La comunicación de baja fue aceptada');

    $statusResultMock = Mockery::mock(StatusResult::class);
    $statusResultMock->shouldReceive('isSuccess')->andReturn(true);
    $statusResultMock->shouldReceive('getCdrResponse')->andReturn($cdrResponseMock);
    $statusResultMock->shouldReceive('getCdrZip')->andReturn('dummy_cdr_zip_content');

    $greenterServiceMock = Mockery::mock(GreenterService::class);
    $greenterServiceMock->shouldReceive('sendVoiding')
        ->once()
        ->andReturn([
            'result' => $summaryResultMock,
            'xml' => '<xml>void</xml>',
            'xml_path' => 'tenants/20600055231/voids/test.xml',
        ]);
    $greenterServiceMock->shouldReceive('checkTicketStatus')
        ->once()
        ->with(Mockery::any(), 'TICKET-123456')
        ->andReturn($statusResultMock);
    $greenterServiceMock->shouldReceive('saveCdr')
        ->once()
        ->andReturn('tenants/20600055231/voids/cdr.zip');

    $job = new VoidDocumentJob($document, 'Anulación de prueba');
    $job->handle($greenterServiceMock);

    $document->refresh();
    expect($document->status)->toBe('voided');
    expect($document->void_ticket)->toBe('TICKET-123456');
    expect($document->void_sunat_code)->toBe('0');
    expect($document->void_sunat_description)->toBe('La comunicación de baja fue aceptada');
    expect($document->voided_at)->not()->toBeNull();
});
