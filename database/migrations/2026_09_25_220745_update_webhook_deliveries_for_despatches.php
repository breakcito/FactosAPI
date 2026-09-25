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
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->foreignUuid('document_id')->nullable()->change();
            $table->foreignUuid('despatch_id')->nullable()->after('document_id')->constrained('despatches')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropForeign(['despatch_id']);
            $table->dropColumn('despatch_id');
            $table->foreignUuid('document_id')->nullable(false)->change();
        });
    }
};
