<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use App\Models\WebhookDelivery;
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
