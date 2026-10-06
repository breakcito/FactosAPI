<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('despatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('external_id', 100)->nullable()->index();
            $table->char('type_code', 2)->default('09');
            $table->char('series', 4);
            $table->string('establishment_code', 4)->default('0000');
            $table->unsignedInteger('correlative');
            $table->date('issue_date');
            $table->time('issue_time');
            $table->date('transfer_date');
            $table->date('delivery_date')->nullable();
            $table->char('transport_mode', 2);
            $table->char('transfer_reason', 2);
            $table->string('transfer_description', 255)->nullable();
            $table->decimal('total_weight', 12, 3);
            $table->char('weight_unit', 3)->default('KGM');
            $table->unsignedInteger('packages_count')->default(1);
            $table->char('recipient_doc_type', 1);
            $table->string('recipient_doc_number', 15);
            $table->string('recipient_name', 255);
            $table->string('recipient_address', 255)->nullable();
            $table->string('recipient_email', 255)->nullable();
            $table->string('origin_ubigeo', 6);
            $table->string('origin_address', 255);
            $table->string('destination_ubigeo', 6);
            $table->string('destination_address', 255);
            $table->char('carrier_doc_type', 1)->nullable();
            $table->string('carrier_doc_number', 15)->nullable();
            $table->string('carrier_name', 255)->nullable();
            $table->string('carrier_mtc', 50)->nullable();
            $table->char('driver_doc_type', 1)->nullable();
            $table->string('driver_doc_number', 15)->nullable();
            $table->string('driver_name', 255)->nullable();
            $table->string('driver_license', 50)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('secondary_vehicle_plate', 20)->nullable();
            $table->json('related_documents')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('ticket', 100)->nullable();
            $table->string('sunat_ticket', 100)->nullable();
            $table->string('sunat_code', 10)->nullable();
            $table->text('sunat_description')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->string('hash', 255)->nullable();
            $table->string('xml_path', 500)->nullable();
            $table->string('cdr_path', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->string('void_ticket', 100)->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->string('void_xml_path', 500)->nullable();
            $table->string('void_cdr_path', 500)->nullable();
            $table->string('void_sunat_code', 10)->nullable();
            $table->text('void_sunat_description')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'type_code', 'series', 'correlative']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('despatches');
    }
};
