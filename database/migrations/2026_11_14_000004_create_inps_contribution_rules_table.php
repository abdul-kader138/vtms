<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INPS for Artigiani / Commercianti (Gestione IVS) as INPS defines it each
 * year in its circular: fixed contributions on a minimum income (minimale,
 * plus the maternity fee), then a percentage on income above it, with one
 * extra point above an annual threshold, up to a ceiling (massimale).
 * Used by the "law" rules; the legacy VTMS slabs stay in inps_rate_slabs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inps_contribution_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->enum('customer_type', ['commercianti', 'artigiani']);
            $table->decimal('minimum_income', 12, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('maternity_fee', 8, 2)->default(7.44);
            $table->decimal('threshold', 12, 2);
            $table->decimal('rate_above_threshold', 5, 2);
            $table->decimal('maximum_income', 12, 2);
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['year', 'customer_type']);
        });

        Schema::table('inps_calculations', function (Blueprint $table) {
            $table->string('calculation_method', 30)->change();
            $table->decimal('fixed_amount', 15, 2)->nullable()->after('inps_amount');
            $table->decimal('variable_amount', 15, 2)->nullable()->after('fixed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('inps_calculations', function (Blueprint $table) {
            $table->dropColumn(['fixed_amount', 'variable_amount']);
        });

        Schema::dropIfExists('inps_contribution_rules');
    }
};
