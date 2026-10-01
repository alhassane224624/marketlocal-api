<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('libelle')->nullable();       // ex: "Domicile", "Bureau"
            $table->string('nom_destinataire');
            $table->string('telephone');
            $table->string('adresse');
            $table->string('ville');
            $table->boolean('est_par_defaut')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'est_par_defaut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
