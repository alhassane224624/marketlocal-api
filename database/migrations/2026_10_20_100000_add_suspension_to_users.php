<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suspension d'un compte par l'admin (fraude, abus…) : réversible, avec un motif.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspendu_at')->nullable();
            $table->string('motif_suspension', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspendu_at', 'motif_suspension']);
        });
    }
};
