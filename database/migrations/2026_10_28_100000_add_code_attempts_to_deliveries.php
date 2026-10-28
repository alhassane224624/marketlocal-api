<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protection du code de remise (4 chiffres) contre les essais successifs :
 * après plusieurs erreurs, la saisie est bloquée un moment pour cette course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('code_echecs')->default(0)->after('code');
            $table->timestamp('code_bloque_jusqu_a')->nullable()->after('code_echecs');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['code_echecs', 'code_bloque_jusqu_a']);
        });
    }
};
