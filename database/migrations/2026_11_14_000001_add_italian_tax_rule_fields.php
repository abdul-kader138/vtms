<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('ateco_code', 20)->nullable()->after('vat_no');
            $table->boolean('startup_rate_eligible')->default(false)->after('tax_rate');
        });

        Schema::table('tax_calculations', function (Blueprint $table) {
            $table->decimal('gross_income', 15, 2)->nullable()->after('total_sales');
            $table->decimal('inps_deducted', 15, 2)->default(0)->after('gross_income');
            $table->string('rules', 10)->default('legacy')->after('next_year_advance_base');
        });

        // 0 = one payment for the whole year (legacy); 1–4 = quarterly
        // stamp duty under the law rules.
        Schema::table('fattura_tra_privati_payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('quarter')->default(0)->after('payment_year');
        });

        // New index first: on MySQL the old one also backs the customer_id
        // foreign key and can't be dropped until another index covers it.
        Schema::table('fattura_tra_privati_payments', function (Blueprint $table) {
            $table->unique(['customer_id', 'payment_year', 'quarter'], 'ftp_payments_customer_year_quarter_unique');
        });

        Schema::table('fattura_tra_privati_payments', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'payment_year']);
        });
    }

    public function down(): void
    {
        Schema::table('fattura_tra_privati_payments', function (Blueprint $table) {
            $table->unique(['customer_id', 'payment_year']);
        });

        Schema::table('fattura_tra_privati_payments', function (Blueprint $table) {
            $table->dropUnique('ftp_payments_customer_year_quarter_unique');
            $table->dropColumn('quarter');
        });

        Schema::table('tax_calculations', function (Blueprint $table) {
            $table->dropColumn(['gross_income', 'inps_deducted', 'rules']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['ateco_code', 'startup_rate_eligible']);
        });
    }
};
