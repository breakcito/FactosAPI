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
        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('internal_code', 50)->nullable();
            $table->string('description', 500);
            $table->char('unit_code', 3)->default('NIU');
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_value', 12, 4);
            $table->decimal('unit_price', 12, 4);
            $table->char('igv_type', 2)->default('10');
            $table->decimal('igv_amount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->json('attributes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_items');
    }
};
