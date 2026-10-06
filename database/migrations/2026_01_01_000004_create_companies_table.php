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
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ruc', 11)->unique();
            $table->string('business_name', 255);
            $table->string('trademark_name', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('establishment_code', 4)->default('0000');
            $table->string('sol_user', 50);
            $table->text('sol_pass');
            $table->string('client_id', 100)->nullable();
            $table->text('client_secret')->nullable();
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
            $table->string('mail_host', 100)->nullable()->default('smtp.gmail.com');
            $table->unsignedSmallInteger('mail_port')->nullable()->default(587);
            $table->string('mail_username', 255)->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_encryption', 10)->nullable()->default('tls');
            $table->string('mail_from_address', 255)->nullable();
            $table->string('mail_from_name', 255)->nullable();
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
