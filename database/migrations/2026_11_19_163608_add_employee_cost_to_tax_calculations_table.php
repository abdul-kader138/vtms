<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tax_calculations', function (Blueprint $table) {
            // Regime Forfettario (L. 197/2022, commi 54-55): gross cost of
            // employees/collaborators above Customer::employee_cost_limit
            // (€20k by default) loses eligibility from the following year —
            // same consequence timing as the revenue limit, but there is no
            // actual cost source in the app (unlike total_sales, which
            // IncomeData already tracks), so this is entered manually.
            $table->decimal('employee_cost', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_calculations', function (Blueprint $table) {
            $table->dropColumn('employee_cost');
        });
    }
};
