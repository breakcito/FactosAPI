<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('models and relationships work correctly', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);
    $document = Document::factory()->create(['company_id' => $company->id]);
    $item = DocumentItem::factory()->create(['document_id' => $document->id]);
    $webhook = WebhookDelivery::factory()->create([
        'company_id' => $company->id,
        'document_id' => $document->id,
    ]);

    expect($company->user->id)->toBe($user->id);
    expect($company->documents)->toHaveCount(1);
    expect($document->items)->toHaveCount(1);
    expect($document->items->first()->id)->toBe($item->id);
    expect($document->webhookDeliveries)->toHaveCount(1);
    expect($webhook->company->id)->toBe($company->id);
    expect($webhook->document->id)->toBe($document->id);
});

test('DatabaseSeeder seeds admin user and SUNAT beta test company correctly', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'admin@factos.pe')->first();
    expect($user)->not()->toBeNull();
    expect($user->name)->toBe('Administrador Factos');

    $company = Company::where('ruc', '20000000001')->first();
    expect($company)->not()->toBeNull();
    expect($company->user_id)->toBe($user->id);
    expect($company->business_name)->toBe('EMPRESA DE PRUEBA SUNAT S.A.C.');
    expect($company->sol_user)->toBe('MODDATOS');
    expect($company->sol_pass)->toBe('moddatos');
    expect($company->hasCustomMailConfig())->toBeTrue();
});
