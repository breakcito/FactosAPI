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
        Schema::create('despatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('despatch_id')->constrained('despatches')->cascadeOnDelete();
            $table->string('internal_code', 50)->nullable();
            $table->string('description', 500);
            $table->char('unit_code', 3)->default('NIU');
            $table->decimal('quantity', 12, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('despatch_items');
    }
};
