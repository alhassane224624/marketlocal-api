<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le vendeur peut retirer un produit de la vente (rupture, saison…) sans le supprimer.
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('en_vente')->default(true)->after('stock');
        });

        // Demande d'ouverture : ce que le vendeur veut vendre, et le motif si l'admin refuse.
        Schema::table('shops', function (Blueprint $table) {
            $table->text('activite')->nullable()->after('description');
            $table->string('motif_refus', 500)->nullable()->after('statut');
            $table->timestamp('valide_at')->nullable()->after('motif_refus');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('en_vente');
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['activite', 'motif_refus', 'valide_at']);
        });
    }
};
