<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->decimal('total_sales', 15, 2);
            $table->decimal('taxable_income', 15, 2);
            $table->decimal('coefficient_used', 5, 2);
            $table->decimal('tax_rate_used', 5, 2);
            $table->decimal('tax_due', 15, 2);
            $table->decimal('advance_payments_made', 15, 2)->default(0);
            $table->decimal('balance_payment', 15, 2);
            $table->decimal('next_year_advance_base', 15, 2);
            $table->timestamps();

            $table->unique(['customer_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_calculations');
    }
};
