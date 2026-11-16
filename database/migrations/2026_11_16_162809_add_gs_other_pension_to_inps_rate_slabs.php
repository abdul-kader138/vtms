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
        // Idempotent: an earlier run of this migration failed after adding
        // the column but before the index change (MySQL DDL isn't
        // transactional), so it was never recorded as run.
        if (! Schema::hasColumn('inps_rate_slabs', 'gs_other_pension')) {
            Schema::table('inps_rate_slabs', function (Blueprint $table) {
                $table->boolean('gs_other_pension')->nullable()->after('customer_type');
            });
        }

        if ($this->hasIndex('inps_rate_slabs_slab_year_customer_type_income_from_unique')) {
            Schema::table('inps_rate_slabs', function (Blueprint $table) {
                $table->dropUnique(['slab_year', 'customer_type', 'income_from']);
            });
        }

        if (! $this->hasIndex('inps_rate_slabs_year_type_from_gs_unique')) {
            Schema::table('inps_rate_slabs', function (Blueprint $table) {
                // Explicit short name: the auto-generated one (table + all 4
                // column names + "_unique") exceeds MySQL's 64-char identifier limit.
                $table->unique(['slab_year', 'customer_type', 'income_from', 'gs_other_pension'], 'inps_rate_slabs_year_type_from_gs_unique');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('inps_rate_slabs'))->contains(fn ($i) => $i['name'] === $name);
    }

    public function down(): void
    {
        Schema::table('inps_rate_slabs', function (Blueprint $table) {
            $table->dropUnique('inps_rate_slabs_year_type_from_gs_unique');
            $table->unique(['slab_year', 'customer_type', 'income_from']);
            $table->dropColumn('gs_other_pension');
        });
    }
};
