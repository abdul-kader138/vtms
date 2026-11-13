<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inps_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->decimal('taxable_income', 15, 2);
            $table->decimal('inps_rate', 5, 2);
            $table->decimal('inps_amount', 15, 2);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('inps_amount_after_discount', 15, 2);
            $table->json('slab_details')->nullable();
            $table->enum('calculation_method', ['minimum', 'percentage']);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inps_calculations');
    }
};
