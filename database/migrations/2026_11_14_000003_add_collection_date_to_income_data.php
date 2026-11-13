<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regime forfettario income is taxed on a cash basis (principio di cassa):
 * an invoice counts in the year it is COLLECTED, not the year it is dated.
 *  - collected_on: when the money came in; null = not collected yet.
 *  - income_year: year(collected_on), indexed — what the "law" rules sum.
 * tax_year (document year) stays: the legacy rules and the stamp duty
 * (due when the invoice is issued) still use it.
 *
 * Existing rows are assumed collected on their document date, which keeps
 * every current figure unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_data', function (Blueprint $table) {
            $table->date('collected_on')->nullable()->after('transaction_date');
            $table->unsignedSmallInteger('income_year')->nullable()->after('tax_year');
            $table->index(['customer_id', 'income_year']);
        });

        DB::table('income_data')->update([
            'collected_on' => DB::raw('transaction_date'),
            'income_year' => DB::raw('tax_year'),
        ]);
    }

    public function down(): void
    {
        Schema::table('income_data', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'income_year']);
            $table->dropColumn(['collected_on', 'income_year']);
        });
    }
};
