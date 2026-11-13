<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diritto Annuale for a sole trader is not revenue-based: it depends on the
 * Registro Imprese section (speciale: artigiani / small businesses, €44;
 * ordinaria: €100) and on whether the local Camera di Commercio applies
 * its surcharge (usually +20%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('chamber_section', 10)->default('speciale')->after('inail_minimum_payment');
            $table->boolean('chamber_surcharge')->default(true)->after('chamber_section');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['chamber_section', 'chamber_surcharge']);
        });
    }
};
