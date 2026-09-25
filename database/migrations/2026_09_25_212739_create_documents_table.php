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
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('external_id', 100)->nullable()->index();
            $table->char('type_code', 2)->default('01'); // 01: Factura, 03: Boleta, 07: NC, 08: ND
            $table->char('series', 4);
            $table->unsignedInteger('correlative');
            $table->date('issue_date');
            $table->time('issue_time');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('PEN');
            $table->char('client_doc_type', 1);
            $table->string('client_doc_number', 15);
            $table->string('client_name', 255);
            $table->string('client_address', 255)->nullable();
            $table->string('client_email', 255)->nullable();
            $table->decimal('total_taxable', 12, 2)->default(0.00);
            $table->decimal('total_unaffected', 12, 2)->default(0.00);
            $table->decimal('total_exonerated', 12, 2)->default(0.00);
            $table->decimal('total_igv', 12, 2)->default(0.00);
            $table->decimal('total_icbper', 12, 2)->default(0.00);
            $table->decimal('total_discount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->string('status', 20)->default('pending')->index(); // pending, signed, waiting_sunat, accepted, rejected, failed
            $table->string('sunat_code', 10)->nullable();
            $table->text('sunat_description')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->string('hash', 255)->nullable();
            $table->string('xml_path', 500)->nullable();
            $table->string('cdr_path', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'type_code', 'series', 'correlative']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
