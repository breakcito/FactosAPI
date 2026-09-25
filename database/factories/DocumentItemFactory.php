<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentItem>
 */
class DocumentItemFactory extends Factory
{
    protected $model = DocumentItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'internal_code' => 'PROD-01',
            'description' => fake()->sentence(3),
            'unit_code' => 'NIU',
            'quantity' => 1,
            'unit_value' => 100.00,
            'unit_price' => 118.00,
            'igv_type' => '10',
            'igv_amount' => 18.00,
            'total' => 118.00,
            'attributes' => null,
        ];
    }
}
