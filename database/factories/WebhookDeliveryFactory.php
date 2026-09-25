<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_id' => Document::factory(),
            'event' => 'document.accepted',
            'payload' => ['event' => 'document.accepted'],
            'response_code' => 200,
            'response_body' => 'OK',
            'status' => 'delivered',
            'attempts' => 1,
        ];
    }
}
