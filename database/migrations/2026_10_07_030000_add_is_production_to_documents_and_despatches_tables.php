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
            $table->boolean('is_production')->default(true)->index()->after('total');
        });

        Schema::table('despatches', function (Blueprint $table) {
            $table->boolean('is_production')->default(true)->index()->after('packages_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['is_production']);
            $table->dropColumn('is_production');
        });

        Schema::table('despatches', function (Blueprint $table) {
            $table->dropIndex(['is_production']);
            $table->dropColumn('is_production');
        });
    }
};
