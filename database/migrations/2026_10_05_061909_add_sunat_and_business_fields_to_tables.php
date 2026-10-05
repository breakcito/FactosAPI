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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('client_id', 100)->nullable()->after('sol_pass');
            $table->text('client_secret')->nullable()->after('client_id');
            $table->string('establishment_code', 4)->default('0000')->after('district');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('operation_type', 4)->default('0101')->after('type_code');
            $table->string('establishment_code', 4)->default('0000')->after('series');
            $table->string('purchase_order', 50)->nullable()->after('related_documents');
            $table->string('plate_number', 20)->nullable()->after('purchase_order');
            $table->decimal('total_free', 12, 2)->default(0.00)->after('total_exonerated');
            $table->decimal('total_exportation', 12, 2)->default(0.00)->after('total_free');
            $table->json('extra_fields')->nullable()->after('note_data');
        });

        Schema::table('despatches', function (Blueprint $table) {
            $table->string('establishment_code', 4)->default('0000')->after('series');
            $table->string('ticket', 100)->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'client_secret', 'establishment_code']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'operation_type',
                'establishment_code',
                'purchase_order',
                'plate_number',
                'total_free',
                'total_exportation',
                'extra_fields',
            ]);
        });

        Schema::table('despatches', function (Blueprint $table) {
            $table->dropColumn(['establishment_code', 'ticket']);
        });
    }
};
