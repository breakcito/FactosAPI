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
            $table->json('company_data')->nullable()->after('company_id');
            $table->longText('xml_content')->nullable()->after('hash');
            $table->longText('cdr_content')->nullable()->after('xml_content');
            $table->longText('void_xml_content')->nullable()->after('void_reason');
            $table->longText('void_cdr_content')->nullable()->after('void_xml_content');
            $table->text('observations')->nullable()->after('client_email');
            $table->json('pie_pagina')->nullable()->after('observations');
            $table->boolean('is_advance')->default(false)->after('total');
            $table->decimal('advance_total', 12, 2)->default(0.00)->after('is_advance');
            $table->decimal('advance_balance', 12, 2)->default(0.00)->after('advance_total');
            $table->boolean('has_deductions')->default(false)->after('advance_balance');
        });

        Schema::table('despatches', function (Blueprint $table) {
            $table->json('company_data')->nullable()->after('company_id');
            $table->longText('xml_content')->nullable()->after('hash');
            $table->longText('cdr_content')->nullable()->after('xml_content');
            $table->longText('void_xml_content')->nullable()->after('void_reason');
            $table->longText('void_cdr_content')->nullable()->after('void_xml_content');
            $table->text('observations')->nullable()->after('destination_address');
            $table->boolean('is_referential_weight')->default(false)->after('packages_count');
            $table->string('vehicle_certificate', 100)->nullable()->after('vehicle_plate');
        });

        // Tabla de anticipos aplicados en facturas (SIN foreign key física)
        Schema::create('applied_advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('advance_document_id')->nullable()->index();
            $table->char('advance_type_code', 2)->default('01');
            $table->string('advance_series', 10);
            $table->string('advance_correlative', 20);
            $table->date('advance_issue_date')->nullable();
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->string('description', 255)->nullable();
            $table->boolean('is_reversed')->default(false);
            $table->timestamps();
        });

        // Tabla de comprobantes de compras (SIN foreign key física)
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->char('supplier_doc_type', 1)->default('6');
            $table->string('supplier_doc_number', 15)->index();
            $table->string('supplier_name', 255);
            $table->char('type_code', 2)->default('01');
            $table->string('series', 10);
            $table->string('correlative', 20);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('PEN');
            $table->decimal('exchange_rate', 10, 4)->nullable();
            $table->decimal('taxable_base', 12, 2)->default(0.00);
            $table->decimal('igv', 12, 2)->default(0.00);
            $table->decimal('unaffected', 12, 2)->default(0.00);
            $table->decimal('exonerated', 12, 2)->default(0.00);
            $table->decimal('other_taxes', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->text('goods_description')->nullable();
            $table->json('items')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'issue_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_documents');
        Schema::dropIfExists('applied_advances');

        Schema::table('despatches', function (Blueprint $table) {
            $table->dropColumn([
                'company_data',
                'xml_content',
                'cdr_content',
                'void_xml_content',
                'void_cdr_content',
                'observations',
                'is_referential_weight',
                'vehicle_certificate',
            ]);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'company_data',
                'xml_content',
                'cdr_content',
                'void_xml_content',
                'void_cdr_content',
                'observations',
                'pie_pagina',
                'is_advance',
                'advance_total',
                'advance_balance',
                'has_deductions',
            ]);
        });
    }
};
