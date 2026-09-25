<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'external_id' => 'ORDER-'.fake()->numerify('#####'),
            'type_code' => '01',
            'series' => 'F001',
            'correlative' => fake()->unique()->numberBetween(1, 999999),
            'issue_date' => now()->toDateString(),
            'issue_time' => now()->toTimeString(),
            'due_date' => null,
            'currency' => 'PEN',
            'client_doc_type' => '6',
            'client_doc_number' => '20'.fake()->numerify('#########'),
            'client_name' => fake()->company().' S.A.C.',
            'client_address' => fake()->streetAddress(),
            'client_email' => fake()->safeEmail(),
            'total_taxable' => 1000.00,
            'total_unaffected' => 0.00,
            'total_exonerated' => 0.00,
            'total_igv' => 180.00,
            'total_icbper' => 0.00,
            'total_discount' => 0.00,
            'total' => 1180.00,
            'status' => 'pending',
            'sunat_code' => null,
            'sunat_description' => null,
            'sunat_notes' => null,
            'hash' => null,
            'xml_path' => null,
            'cdr_path' => null,
            'pdf_path' => null,
            'retry_count' => 0,
            'next_retry_at' => null,
        ];
    }
}
