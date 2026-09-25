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
            $table->char('type_code', 2)->default('09'); // 09: Guía Remitente, 31: Guía Transportista
            $table->char('series', 4); // T001, V001
            $table->unsignedInteger('correlative');
            $table->date('issue_date');
            $table->time('issue_time');
            $table->date('transfer_date');
            $table->date('delivery_date')->nullable();
            $table->char('transport_mode', 2); // 01: Publico, 02: Privado
            $table->char('transfer_reason', 2); // Catálogo 20 (01 Venta, 02 Compra, etc.)
            $table->string('transfer_description', 255)->nullable();
            $table->decimal('total_weight', 12, 3);
            $table->char('weight_unit', 3)->default('KGM');
            $table->unsignedInteger('packages_count')->default(1);

            // Destinatario
            $table->char('recipient_doc_type', 1);
            $table->string('recipient_doc_number', 15);
            $table->string('recipient_name', 255);
            $table->string('recipient_address', 255)->nullable();
            $table->string('recipient_email', 255)->nullable();

            // Puntos de partida y llegada
            $table->string('origin_ubigeo', 6);
            $table->string('origin_address', 255);
            $table->string('destination_ubigeo', 6);
            $table->string('destination_address', 255);

            // Transportista público
            $table->char('carrier_doc_type', 1)->nullable();
            $table->string('carrier_doc_number', 15)->nullable();
            $table->string('carrier_name', 255)->nullable();
            $table->string('carrier_mtc', 50)->nullable();

            // Transporte privado (Conductor y vehículo)
            $table->char('driver_doc_type', 1)->nullable();
            $table->string('driver_doc_number', 15)->nullable();
            $table->string('driver_name', 255)->nullable();
            $table->string('driver_license', 50)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('secondary_vehicle_plate', 20)->nullable();

            // Documentos relacionados
            $table->json('related_documents')->nullable();

            // Estado y artefactos SUNAT
            $table->string('status', 20)->default('pending')->index(); // pending, signed, waiting_sunat, accepted, rejected, failed, voided
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

            // Anulaciones
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

        Schema::create('despatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('despatch_id')->constrained('despatches')->cascadeOnDelete();
            $table->string('internal_code', 50)->nullable();
            $table->string('description', 500);
            $table->char('unit_code', 3)->default('NIU');
            $table->decimal('quantity', 12, 4);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('despatch_items');
        Schema::dropIfExists('despatches');
    }
};
