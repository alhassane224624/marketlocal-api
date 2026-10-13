<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livraison par un livreur, sur le modèle « course libre » (type Glovo) :
 *  1. le vendeur a préparé ses articles et demande un livreur -> course « disponible » ;
 *  2. un livreur accepte la course                              -> « acceptee » ;
 *  3. il récupère le colis chez le vendeur                      -> « en_route » (articles expédiés) ;
 *  4. il le remet à l'acheteur, qui lui donne le code à 4 chiffres -> « livree » (articles livrés).
 *
 * Une commande multi-vendeurs a une livraison par boutique (un colis par vendeur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['acheteur', 'vendeur', 'admin', 'livreur'])->default('acheteur')->change();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('livreur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('statut', ['disponible', 'acceptee', 'en_route', 'livree', 'annulee'])->default('disponible');
            $table->string('code', 4); // donné par l'acheteur au livreur à la remise du colis
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'shop_id']);
            $table->index('statut');
            $table->index(['livreur_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['acheteur', 'vendeur', 'admin'])->default('acheteur')->change();
        });
    }
};
