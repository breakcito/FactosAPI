<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Despatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Despatch>
 */
class DespatchFactory extends Factory
{
    protected $model = Despatch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'external_id' => 'GUIA-'.fake()->numerify('#####'),
            'type_code' => '09',
            'series' => 'T001',
            'correlative' => fake()->unique()->numberBetween(1, 999999),
            'issue_date' => now()->toDateString(),
            'issue_time' => '08:00:00',
            'transfer_date' => now()->addDay()->toDateString(),
            'delivery_date' => null,
            'transport_mode' => '02',
            'transfer_reason' => '01',
            'transfer_description' => 'VENTA',
            'total_weight' => 50.000,
            'weight_unit' => 'KGM',
            'packages_count' => 5,
            'recipient_doc_type' => '6',
            'recipient_doc_number' => '20'.fake()->numerify('#########'),
            'recipient_name' => fake()->company(),
            'recipient_address' => fake()->streetAddress(),
            'recipient_email' => null,
            'origin_ubigeo' => '150101',
            'origin_address' => fake()->streetAddress(),
            'destination_ubigeo' => '150101',
            'destination_address' => fake()->streetAddress(),
            'carrier_doc_type' => null,
            'carrier_doc_number' => null,
            'carrier_name' => null,
            'carrier_mtc' => null,
            'driver_doc_type' => '1',
            'driver_doc_number' => fake()->numerify('########'),
            'driver_name' => fake()->name(),
            'driver_license' => 'Q'.fake()->numerify('########'),
            'vehicle_plate' => 'ABC-'.fake()->numerify('###'),
            'secondary_vehicle_plate' => null,
            'related_documents' => null,
            'status' => 'pending',
            'sunat_code' => null,
            'sunat_description' => null,
            'hash' => null,
            'xml_path' => null,
            'cdr_path' => null,
            'pdf_path' => null,
            'retry_count' => 0,
            'next_retry_at' => null,
        ];
    }
}
