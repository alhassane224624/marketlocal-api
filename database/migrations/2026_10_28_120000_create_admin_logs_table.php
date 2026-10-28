<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des actions d'administration : qui a fait quoi, sur quoi, et quand.
 * La description est figée au moment de l'action (le nom d'une boutique peut changer ensuite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            // Admin supprimé : on garde la trace de l'action.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('cible_type', 30)->nullable();
            $table->unsignedBigInteger('cible_id')->nullable();
            $table->string('description', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
