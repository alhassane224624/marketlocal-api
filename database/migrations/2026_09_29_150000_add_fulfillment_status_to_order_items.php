<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'payee', 'expediee', 'livree', 'annulee'])
                ->default('en_attente')->after('quantite');
            $table->index(['order_id', 'shop_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'shop_id', 'statut']);
            $table->dropColumn('statut');
        });
    }
};
