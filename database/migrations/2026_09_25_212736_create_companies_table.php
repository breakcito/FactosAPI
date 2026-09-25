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
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ruc', 11)->unique();
            $table->string('business_name', 255);
            $table->string('trademark_name', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('sol_user', 50);
            $table->text('sol_pass');
            $table->string('certificate_path', 500);
            $table->text('certificate_pass');
            $table->string('webhook_url', 500)->nullable();
            $table->string('webhook_secret', 100)->nullable();
            $table->boolean('is_production')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('email_notifications_active')->default(false);
            $table->json('company_copy_emails')->nullable();
            $table->boolean('send_to_client_email')->default(false);
            $table->json('email_template_settings')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
