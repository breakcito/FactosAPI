<?php

namespace Database\Factories;

use App\Models\Despatch;
use App\Models\DespatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DespatchItem>
 */
class DespatchItemFactory extends Factory
{
    protected $model = DespatchItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'despatch_id' => Despatch::factory(),
            'internal_code' => 'ITEM-01',
            'description' => fake()->sentence(3),
            'unit_code' => 'KGM',
            'quantity' => 10,
        ];
    }
}
