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
            'transfer_date' => now()->addDay()->toDateString(),
            'transfer_reason_code' => '01',
            'transfer_reason_description' => 'VENTA',
            'transport_mode' => '02',
            'carrier_name' => fake()->company(),
            'carrier_ruc' => '20'.fake()->numerify('#########'),
            'carrier_mtc' => null,
            'driver_doc_type' => '1',
            'driver_doc_number' => fake()->numerify('########'),
            'driver_name' => fake()->name(),
            'driver_license' => 'Q'.fake()->numerify('########'),
            'vehicle_plate' => 'ABC-'.fake()->numerify('###'),
            'secondary_vehicle_plate' => null,
            'recipient_doc_type' => '6',
            'recipient_doc_number' => '20'.fake()->numerify('#########'),
            'recipient_name' => fake()->company(),
            'origin_ubigeo' => '150101',
            'origin_address' => fake()->streetAddress(),
            'origin_code' => '0000',
            'delivery_ubigeo' => '150101',
            'delivery_address' => fake()->streetAddress(),
            'delivery_code' => '0000',
            'gross_weight' => 50.00,
            'weight_unit' => 'KGM',
            'total_packages' => 5,
            'observations' => null,
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
