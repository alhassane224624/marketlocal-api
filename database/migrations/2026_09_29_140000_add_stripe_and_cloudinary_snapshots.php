<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_public_id')->nullable()->after('image');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable()->unique()->after('telephone_livraison');
            $table->timestamp('paid_at')->nullable()->after('stripe_payment_intent_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('commission_amount', 10, 2)->nullable()->after('taux_commission');
            $table->decimal('seller_amount', 10, 2)->nullable()->after('commission_amount');
            $table->string('stripe_transfer_id')->nullable()->after('seller_amount');
            $table->string('seller_transfer_status')->default('pending')->after('stripe_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['commission_amount', 'seller_amount', 'stripe_transfer_id', 'seller_transfer_status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['stripe_payment_intent_id']);
            $table->dropColumn(['stripe_payment_intent_id', 'paid_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_public_id');
        });
    }
};
