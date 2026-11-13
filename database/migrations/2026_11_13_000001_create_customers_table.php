<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('vat_no')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->enum('customer_type', ['gestione_separata', 'commercianti', 'artigiani'])->nullable();
            $table->decimal('coefficient_of_profitability', 5, 2)->default(78.00);
            $table->decimal('tax_rate', 5, 2)->default(5.00);
            $table->date('business_start_date')->nullable();
            $table->boolean('inps_discount_eligible')->default(false);
            $table->decimal('annual_revenue_limit', 10, 2)->default(85000.00);
            $table->decimal('employee_cost_limit', 10, 2)->default(20000.00);
            $table->string('inail_ateco_code', 20)->nullable();
            $table->decimal('inail_rate', 5, 2)->nullable();
            $table->decimal('inail_minimum_payment', 15, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
