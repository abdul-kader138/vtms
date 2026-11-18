<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inps_discount_eligible was a bare boolean with no record of why it's set.
 * The 35% reduction (L. 190/2014, c. 111) is never automatic — INPS only
 * grants it on an explicit domanda di riduzione contributiva — so the flag
 * needs evidence behind it, the same way InpsContributionRule.source does
 * for its "CARRIED FORWARD" rows (see Customer::inpsDiscountVerified()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('inps_discount_request_date')->nullable()->after('inps_discount_eligible');
            $table->string('inps_discount_source')->nullable()->after('inps_discount_request_date');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['inps_discount_request_date', 'inps_discount_source']);
        });
    }
};
