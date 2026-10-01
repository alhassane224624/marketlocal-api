<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->unique()->after('commission');
            $table->string('kyc_status')->default('not_started')->after('stripe_account_id');
            $table->boolean('is_active')->default(false)->after('kyc_status');
            $table->string('logo_public_id')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn(['stripe_account_id', 'kyc_status', 'is_active', 'logo_public_id']);
        });
    }
};
