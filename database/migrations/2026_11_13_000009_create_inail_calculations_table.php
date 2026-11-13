<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inail_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->decimal('taxable_income', 15, 2);
            $table->decimal('inail_rate', 5, 2);
            $table->decimal('inail_calculated_amount', 15, 2);
            $table->decimal('inail_minimum_payment', 15, 2);
            $table->decimal('inail_final_amount', 15, 2);
            $table->string('ateco_code', 20)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inail_calculations');
    }
};
