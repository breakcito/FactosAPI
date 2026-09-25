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
        Schema::table('documents', function (Blueprint $table) {
            $table->string('payment_method', 30)->default('contado')->after('currency');
            $table->json('installments')->nullable()->after('payment_method');
            $table->json('detraction')->nullable()->after('installments');
            $table->json('retention')->nullable()->after('detraction');
            $table->json('prepayments')->nullable()->after('retention');
            $table->json('related_documents')->nullable()->after('prepayments');
            $table->json('note_data')->nullable()->after('related_documents');

            // Voiding / Bajas y Resumenes
            $table->string('void_ticket', 100)->nullable()->after('retry_count');
            $table->string('void_reason', 255)->nullable()->after('void_ticket');
            $table->string('void_xml_path', 500)->nullable()->after('void_reason');
            $table->string('void_cdr_path', 500)->nullable()->after('void_xml_path');
            $table->string('void_sunat_code', 10)->nullable()->after('void_cdr_path');
            $table->text('void_sunat_description')->nullable()->after('void_sunat_code');
            $table->timestamp('voided_at')->nullable()->after('void_sunat_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'installments',
                'detraction',
                'retention',
                'prepayments',
                'related_documents',
                'note_data',
                'void_ticket',
                'void_reason',
                'void_xml_path',
                'void_cdr_path',
                'void_sunat_code',
                'void_sunat_description',
                'voided_at',
            ]);
        });
    }
};
