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
            $table->string('mail_host', 100)->nullable()->default('smtp.gmail.com')->after('email_template_settings');
            $table->unsignedSmallInteger('mail_port')->nullable()->default(587)->after('mail_host');
            $table->string('mail_username', 255)->nullable()->after('mail_port');
            $table->text('mail_password')->nullable()->after('mail_username');
            $table->string('mail_encryption', 10)->nullable()->default('tls')->after('mail_password');
            $table->string('mail_from_address', 255)->nullable()->after('mail_encryption');
            $table->string('mail_from_name', 255)->nullable()->after('mail_from_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'mail_host',
                'mail_port',
                'mail_username',
                'mail_password',
                'mail_encryption',
                'mail_from_address',
                'mail_from_name',
            ]);
        });
    }
};
