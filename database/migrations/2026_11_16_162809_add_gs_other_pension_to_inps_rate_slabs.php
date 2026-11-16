<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gestione Separata has two different rates depending on whether the
     * customer has other mandatory pension coverage (INPS circular 27/2025:
     * 26.07% without it, 24% with it) — null means "applies regardless",
     * so existing single-rate rows for Artigiani/Commercianti (and any
     * historical Gestione Separata row entered before this distinction
     * existed) keep matching every customer of that type.
     */
    public function up(): void
    {
        Schema::table('inps_rate_slabs', function (Blueprint $table) {
            $table->boolean('gs_other_pension')->nullable()->after('customer_type');
        });

        Schema::table('inps_rate_slabs', function (Blueprint $table) {
            $table->dropUnique(['slab_year', 'customer_type', 'income_from']);
            $table->unique(['slab_year', 'customer_type', 'income_from', 'gs_other_pension']);
        });
    }

    public function down(): void
    {
        Schema::table('inps_rate_slabs', function (Blueprint $table) {
            $table->dropUnique(['slab_year', 'customer_type', 'income_from', 'gs_other_pension']);
            $table->unique(['slab_year', 'customer_type', 'income_from']);
            $table->dropColumn('gs_other_pension');
        });
    }
};
